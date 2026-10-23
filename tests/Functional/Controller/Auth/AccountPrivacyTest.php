<?php

namespace App\Tests\Functional\Controller\Auth;

use App\Entity\Identity\RefreshToken;
use App\Entity\Identity\User;
use App\Entity\Identity\UserAddress;
use App\Entity\Producer\DeliveryZone;
use App\Entity\Producer\OpeningHour;
use App\Entity\Producer\ProducerMedia;
use App\Entity\Producer\ProducerProductMedia;
use App\Entity\Producer\ProducerProfile;
use App\Entity\Producer\ProducerSetting;
use App\Entity\Producer\QuickReply;
use App\Entity\Producer\TeamMember;
use App\Entity\Trust\VerificationDocument;
use App\Enum\SubscriptionStatus;
use App\Enum\VerificationDocumentStatus;
use App\Service\Payment\FakePaymentGateway;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;

/**
 * Teste DELETE /api/me et GET /api/me/export (cahier fonctionnel : « export des données », « suppression ou anonymisation du
 * compte » ; MLD : anonymisation qui préserve factures et statistiques). FakePaymentGateway remplace Stripe, les fichiers sont
 * écrits dans var/storage/test* (voir flysystem.yaml).
 */
final class AccountPrivacyTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private const PASSWORD = 'motdepasse123';

    private function login(User $user): string
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $user->getEmail(),
            'password' => self::PASSWORD,
        ]));

        return json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function delete(string $token, array $body): void
    {
        $this->client->request('DELETE', '/api/me', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode($body));
    }

    private function gateway(): FakePaymentGateway
    {
        return static::getContainer()->get(FakePaymentGateway::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function userRow(User $user): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT email, first_name, status, totp_secret FROM identity.users WHERE id = :id', ['id' => $user->getId()->toRfc4122()]);
    }

    private function countRows(string $sql, string $id): int
    {
        return (int) $this->em->getConnection()->fetchOne($sql, ['id' => $id]);
    }

    private function storedFile(string $directory, string $key): string
    {
        $path = \dirname(__DIR__, 4).'/var/storage/'.$directory.'/'.$key;
        @mkdir(\dirname($path), 0777, true);
        file_put_contents($path, 'contenu');

        return $path;
    }

    /**
     * @return array{0: User, 1: ProducerProfile}
     */
    private function aProducer(): array
    {
        $owner = $this->makeUserWithPassword('farmer', self::PASSWORD);
        $owner->setRoles([User::ROLE_PRODUCER]);
        $owner->setFirstName('Jeanne');
        $producer = $this->makeProducerProfile($owner, $this->makeCountry(), farmName: 'Ferme de Jeanne');
        $producer->setDescription('Maraîchage')->setStory('Histoire de la ferme')->setCity('Lyon')->setPostalCode('69001')->setLocation('SRID=4326;POINT(4.85 45.75)');

        return [$owner, $producer];
    }

    public function testThePasswordIsRequired(): void
    {
        $user = $this->makeUserWithPassword('client', self::PASSWORD);
        $this->em->flush();

        $this->delete($this->login($user), []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($user->getEmail(), $this->userRow($user)['email']);
        self::assertNotSame('deleted', $this->userRow($user)['status']);
    }

    public function testAWrongPasswordChangesNothing(): void
    {
        $user = $this->makeUserWithPassword('client', self::PASSWORD);
        $this->em->flush();

        $this->delete($this->login($user), ['password' => 'pas-le-bon']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Mot de passe incorrect.', json_decode($this->client->getResponse()->getContent(), true)['error']);
        self::assertSame($user->getEmail(), $this->userRow($user)['email']);
        self::assertNotSame('deleted', $this->userRow($user)['status']);
        self::assertSame(0, $this->countRows("SELECT count(*) FROM identity.data_requests WHERE user_id = :id AND request_type = 'deletion'", $user->getId()->toRfc4122()));
    }

    public function testAClientAccountIsAnonymizedAndTheRequestIsTraced(): void
    {
        $user = $this->makeUserWithPassword('client', self::PASSWORD);
        $user->setFirstName('Paul');
        $token = new RefreshToken();
        $token->setIdUser($user);
        $token->setTokenHash('hash-de-session');
        $token->setExpiresAt(new \DateTimeImmutable('+7 days'));
        $this->em->persist($token);
        $this->em->flush();
        $id = $user->getId()->toRfc4122();
        $this->em->getConnection()->executeStatement("UPDATE identity.users SET totp_secret = 'SECRET' WHERE id = :id", ['id' => $id]);

        $this->delete($this->login($user), ['password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(204);
        $row = $this->userRow($user);
        self::assertStringContainsString('@anonymized.local', $row['email']);
        self::assertNull($row['first_name']);
        self::assertSame('deleted', $row['status']);
        self::assertNull($row['totp_secret']);
        // * la trace RGPD (MPD) et l'audit (MLD) ne portent que l'identifiant
        self::assertSame(1, $this->countRows("SELECT count(*) FROM identity.data_requests WHERE user_id = :id AND request_type = 'deletion' AND status = 'done' AND processed_at IS NOT NULL", $id));
        self::assertSame(1, $this->countRows("SELECT count(*) FROM audit.audit_logs WHERE action = 'account_deleted' AND record_id = :id", $id));
        self::assertSame(0, $this->countRows('SELECT count(*) FROM identity.refresh_tokens WHERE user_id = :id AND revoked_at IS NULL', $id));
    }

    public function testTheSavedAddressesDisappearWithTheAccount(): void
    {
        $user = $this->makeUserWithPassword('client', self::PASSWORD);
        $address = (new UserAddress())->setIdUser($user)->setLabel('Maison')->setAddress1('12 rue des Lilas')->setCity('Lyon')->setPostalCode('69001')->setCountryCode('FR');
        $this->em->persist($address);
        $this->em->flush();
        $id = $user->getId()->toRfc4122();
        self::assertSame(1, $this->countRows('SELECT count(*) FROM identity.user_addresses WHERE user_id = :id', $id));

        $this->delete($this->login($user), ['password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->countRows('SELECT count(*) FROM identity.user_addresses WHERE user_id = :id', $id));
    }

    public function testAProducerWithoutSubscriptionIsErasedButItsStatisticsStay(): void
    {
        [$owner, $producer] = $this->aProducer();
        $producerId = $producer->getId()->toRfc4122();

        // * photo de l'exploitation, photo de produit, justificatif : trois fichiers à retirer du stockage
        $media = new ProducerMedia();
        $media->setProducer($producer)->setType('photo')->setIsPublic(true);
        $mediaKey = $producerId.'/'.$media->getId()->toRfc4122().'.jpg';
        $media->setFileUrl('http://localhost/test-storage/'.$mediaKey);
        $this->em->persist($media);
        $files = [$this->storedFile('test', $mediaKey)];

        $item = $this->makeProducerProduct($producer, $this->makeProduct($this->makeCategory()));
        $photo = new ProducerProductMedia();
        $photoKey = $producerId.'/products/'.$photo->getId()->toRfc4122().'.jpg';
        $photo->setFileUrl('http://localhost/test-storage/'.$photoKey)->setPosition(0);
        $item->addMedium($photo);
        $this->em->persist($photo);
        $files[] = $this->storedFile('test', $photoKey);

        $document = new VerificationDocument();
        $document->setProducer($producer)->setType('certificat')->setStatus(VerificationDocumentStatus::Pending);
        $documentKey = $producerId.'/'.$document->getId()->toRfc4122();
        $document->setFileUrl($documentKey);
        $this->em->persist($document);
        $files[] = $this->storedFile('test-verification-documents', $documentKey);

        $this->makeProducerLabel($producer, $this->makeLabel());
        $zone = (new DeliveryZone())->setProducer($producer)->setRadiusKm('20');
        $hour = (new OpeningHour())->setProducer($producer)->setWeekday(1)->setOpensAt(new \DateTimeImmutable('09:00'))->setClosesAt(new \DateTimeImmutable('18:00'))->setIsClosed(false);
        $reply = (new QuickReply())->setProducer($producer)->setTitle('Merci')->setContent('Merci de votre demande')->setIsActive(true);
        $member = (new TeamMember())->setProducer($producer)->setIdUser($this->makeUser('membre'))->setRole('editor')->setIsActive(true);
        $settings = (new ProducerSetting())->setPickupEnabled(true);
        $producer->setSettings($settings);
        foreach ([$zone, $hour, $reply, $member, $settings] as $row) {
            $this->em->persist($row);
        }
        $this->em->flush();
        $token = $this->login($owner);
        $this->client->request('GET', '/api/producers/'.$producerId);
        self::assertResponseIsSuccessful();

        $this->delete($token, ['password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(204);
        $producerRow = $this->em->getConnection()->fetchAssociative('SELECT is_active, farm_name, slug, description, story, city, postal_code, location FROM producer.producer_profiles WHERE id = :id', ['id' => $producerId]);
        self::assertFalse((bool) $producerRow['is_active']);
        self::assertSame('Exploitation supprimée', $producerRow['farm_name']);
        self::assertStringStartsWith('supprimee-', $producerRow['slug']);
        self::assertSame([null, null, null, null, null], [$producerRow['description'], $producerRow['story'], $producerRow['city'], $producerRow['postal_code'], $producerRow['location']]);
        foreach (['producer_media', 'opening_hours', 'delivery_zones', 'quick_replies', 'team_members', 'producer_settings', 'producer_labels'] as $table) {
            self::assertSame(0, $this->countRows("SELECT count(*) FROM producer.$table WHERE producer_id = :id", $producerId), $table);
        }
        self::assertSame(0, $this->countRows('SELECT count(*) FROM trust.verification_documents WHERE producer_id = :id', $producerId));
        self::assertSame(0, $this->countRows('SELECT count(*) FROM producer.producer_product_media'.' WHERE producer_product_id = :id', $item->getId()->toRfc4122()));
        // * le produit reste (statistiques) mais n'est plus proposé
        self::assertFalse((bool) $this->em->getConnection()->fetchOne('SELECT is_active FROM producer.producer_products WHERE id = :id', ['id' => $item->getId()->toRfc4122()]));
        foreach ($files as $path) {
            self::assertFileDoesNotExist($path);
        }

        // * la fiche publique a disparu
        $this->client->request('GET', '/api/producers/'.$producerId);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAMemberOfSomeoneElsesTeamLeavesIt(): void
    {
        [, $producer] = $this->aProducer();
        $member = $this->makeUserWithPassword('membre', self::PASSWORD);
        $this->em->persist((new TeamMember())->setProducer($producer)->setIdUser($member)->setRole('editor')->setIsActive(true));
        $this->em->flush();

        $this->delete($this->login($member), ['password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(0, $this->countRows('SELECT count(*) FROM producer.team_members WHERE user_id = :id', $member->getId()->toRfc4122()));
        // * l'exploitation, elle, n'est pas touchée
        self::assertTrue((bool) $this->em->getConnection()->fetchOne('SELECT is_active FROM producer.producer_profiles WHERE id = :id', ['id' => $producer->getId()->toRfc4122()]));
    }

    public function testAnActiveSubscriptionMustBeAcceptedFirstAndNothingIsTouchedUntilThen(): void
    {
        [$owner, $producer] = $this->aProducer();
        $this->makeActiveSubscription($producer)->setProviderSubscriptionId('sub_test_1');
        $this->em->flush();

        $this->delete($this->login($owner), ['password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(409);
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('subscription_active', $body['code']);
        self::assertSame('Basic', $body['subscription']['planName']);
        self::assertSame('monthly', $body['subscription']['billingCycle']);
        self::assertSame('active', $body['subscription']['status']);
        self::assertNotEmpty($body['subscription']['currentPeriodEnd']);
        self::assertSame([], $this->gateway()->calls);
        self::assertSame($owner->getEmail(), $this->userRow($owner)['email']);
        self::assertNotSame('deleted', $this->userRow($owner)['status']);
        self::assertTrue((bool) $this->em->getConnection()->fetchOne('SELECT is_active FROM producer.producer_profiles WHERE id = :id', ['id' => $producer->getId()->toRfc4122()]));
    }

    public function testAnAcceptedSubscriptionIsCancelledAtOnceThenTheCustomerAndTheAccountAreErased(): void
    {
        [$owner, $producer] = $this->aProducer();
        $subscription = $this->makeActiveSubscription($producer);
        $subscription->setProviderSubscriptionId('sub_test_1');
        $this->em->flush();

        $this->delete($this->login($owner), ['password' => self::PASSWORD, 'acceptSubscriptionCancellation' => true]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([['cancel_now', 'sub_test_1'], ['anonymize_customer', 'sub_test_1']], $this->gateway()->calls);
        self::assertSame('deleted', $this->userRow($owner)['status']);
        // * l'abonnement reste en base (comptabilité), marqué résilié
        self::assertSame('cancelled', $this->em->getConnection()->fetchOne('SELECT status FROM billing.subscriptions WHERE id = :id', ['id' => $subscription->getId()->toRfc4122()]));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusesInForce')]
    public function testEveryStatusThatGivesRightsNeedsTheAcceptance(SubscriptionStatus $status): void
    {
        [$owner, $producer] = $this->aProducer();
        $this->makeActiveSubscription($producer, $status);
        $this->em->flush();

        $this->delete($this->login($owner), ['password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(409);
    }

    /**
     * @return iterable<string, array{0: SubscriptionStatus}>
     */
    public static function statusesInForce(): iterable
    {
        yield 'en cours' => [SubscriptionStatus::Active];
        yield "période d'essai" => [SubscriptionStatus::Trialing];
        yield 'paiement en retard' => [SubscriptionStatus::PastDue];
    }

    public function testAnAlreadyEndedSubscriptionNeedsNoAcceptance(): void
    {
        [$owner, $producer] = $this->aProducer();
        $this->makeActiveSubscription($producer, SubscriptionStatus::Cancelled)->setProviderSubscriptionId('sub_old');
        $this->em->flush();

        $this->delete($this->login($owner), ['password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->gateway()->calls);
    }

    public function testWhenStripeFailsNothingIsDeletedAndTheUserCanTryAgain(): void
    {
        [$owner, $producer] = $this->aProducer();
        $subscription = $this->makeActiveSubscription($producer);
        $subscription->setProviderSubscriptionId('sub_test_1');
        $this->em->flush();
        $token = $this->login($owner);
        $this->gateway()->failure = new ApiConnectionException('Stripe injoignable');

        $this->delete($token, ['password' => self::PASSWORD, 'acceptSubscriptionCancellation' => true]);

        self::assertResponseStatusCodeSame(502);
        self::assertSame($owner->getEmail(), $this->userRow($owner)['email']);
        self::assertNotSame('deleted', $this->userRow($owner)['status']);
        self::assertTrue((bool) $this->em->getConnection()->fetchOne('SELECT is_active FROM producer.producer_profiles WHERE id = :id', ['id' => $producer->getId()->toRfc4122()]));
        self::assertSame('active', $this->em->getConnection()->fetchOne('SELECT status FROM billing.subscriptions WHERE id = :id', ['id' => $subscription->getId()->toRfc4122()]));
        self::assertSame(0, $this->countRows("SELECT count(*) FROM identity.data_requests WHERE user_id = :id AND request_type = 'deletion'", $owner->getId()->toRfc4122()));

        // * Stripe revient : la même demande aboutit
        $this->gateway()->failure = null;
        $this->delete($token, ['password' => self::PASSWORD, 'acceptSubscriptionCancellation' => true]);
        self::assertResponseStatusCodeSame(204);
    }

    public function testASubscriptionStripeNoLongerKnowsIsAlreadyCancelledAndTheDeletionGoesOn(): void
    {
        [$owner, $producer] = $this->aProducer();
        $this->makeActiveSubscription($producer)->setProviderSubscriptionId('sub_gone');
        $this->em->flush();
        $token = $this->login($owner);
        // * Stripe répond « introuvable » à la résiliation, puis échoue aussi à anonymiser le client : ni l'un ni l'autre ne bloque
        $this->gateway()->failure = InvalidRequestException::factory('No such subscription', 404, null, null, null, 'resource_missing');

        $this->delete($token, ['password' => self::PASSWORD, 'acceptSubscriptionCancellation' => true]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('deleted', $this->userRow($owner)['status']);
    }

    public function testTheExportCoversTheProducersWholeBusinessAndIsTraced(): void
    {
        [$owner, $producer] = $this->aProducer();
        $item = $this->makeProducerProduct($producer, $this->makeProduct($this->makeCategory(), 'Tomates'));
        $item->setVariety('Cœur de bœuf')->setDefaultPrice('3.50');
        $this->makeProducerLabel($producer, $this->makeLabel('hve', 'HVE'));
        $this->em->persist((new DeliveryZone())->setProducer($producer)->setRadiusKm('20'));
        $this->em->persist((new OpeningHour())->setProducer($producer)->setWeekday(2)->setOpensAt(new \DateTimeImmutable('08:30'))->setClosesAt(new \DateTimeImmutable('12:00'))->setIsClosed(false));
        $settings = (new ProducerSetting())->setPickupEnabled(true);
        $producer->setSettings($settings);
        $this->em->persist($settings);
        $this->makeActiveSubscription($producer);
        $this->em->flush();

        $this->client->request('GET', '/api/me/export', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->login($owner)]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Ferme de Jeanne', $data['producerProfile']['farmName']);
        self::assertTrue($data['producerProfile']['settings']['pickupEnabled']);
        self::assertSame('Tomates', $data['products'][0]['product']);
        self::assertSame('Cœur de bœuf', $data['products'][0]['variety']);
        self::assertSame('3.50', $data['products'][0]['defaultPrice']);
        self::assertSame('HVE', $data['labels'][0]['label']);
        self::assertSame('20.00', $data['deliveryZones'][0]['radiusKm']);
        self::assertSame([2, '08:30', '12:00'], [$data['openingHours'][0]['weekday'], $data['openingHours'][0]['opensAt'], $data['openingHours'][0]['closesAt']]);
        self::assertSame(['Basic', 'monthly', 'active'], [$data['subscriptions'][0]['plan'], $data['subscriptions'][0]['billingCycle'], $data['subscriptions'][0]['status']]);
        self::assertSame([], $data['replies']);
        self::assertSame(1, $this->countRows("SELECT count(*) FROM identity.data_requests WHERE user_id = :id AND request_type = 'export' AND status = 'done'", $owner->getId()->toRfc4122()));
    }
}
