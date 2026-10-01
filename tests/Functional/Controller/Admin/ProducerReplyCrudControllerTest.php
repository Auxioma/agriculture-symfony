<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\ProducerReplyCrudController;
use App\Entity\Catalog\Currency;
use App\Entity\Catalog\Unit;
use App\Entity\Identity\User;
use App\Entity\Matching\ProducerReply;
use App\Enum\ReplyStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Teste l'écran "Réponses et devis" du back-office : consultation seule des ProducerReply (demande liée,
 * producteur, prix indicatif, date, statut), sans création, modification ni suppression.
 */
final class ProducerReplyCrudControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private function loginAsAdmin(): User
    {
        $admin = $this->makeUserWithPassword('admin', 'motdepasse123');
        $admin->setRoles([User::ROLE_ADMIN]);
        $this->em->flush();

        $this->client->followRedirects(true);
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Se connecter', [
            '_username' => $admin->getEmail(),
            '_password' => 'motdepasse123',
        ]);

        return $admin;
    }

    private function makeReply(ReplyStatus $status = ReplyStatus::Accepted): ProducerReply
    {
        $country = $this->makeCountry();
        $product = $this->makeProduct($this->makeCategory(), 'Tomates bio');
        $client = $this->makeUser('client');
        $request = $this->makeClientRequest($client, $product);
        $request->setQuantity('5.000');

        $unit = new Unit();
        $unit->setCode('kg');
        $this->em->persist($unit);
        $request->setUnit($unit);

        $currency = new Currency();
        $currency->setCode('EUR');
        $currency->setName('Euro');
        $currency->setSymbol('€');
        $currency->setIsActive(true);
        $this->em->persist($currency);

        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country);
        $producer->setFarmName('Ferme Dupont');

        $reply = new ProducerReply();
        $reply->setRequest($request);
        $reply->setProducer($producer);
        $reply->setReplyText('Récolte de la semaine, livraison possible.');
        $reply->setPriceAmount('3.50');
        $reply->setPriceUnit($unit);
        $reply->setCurrency($currency);
        $reply->setStatus($status);
        $this->em->persist($reply);
        $this->em->flush();

        return $reply;
    }

    private function url(string $action, ?ProducerReply $reply = null): string
    {
        $generator = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(ProducerReplyCrudController::class)
            ->setAction($action);
        if (null !== $reply) {
            $generator->setEntityId($reply->getId());
        }

        return $generator->generateUrl();
    }

    public function testIndexListsReplyWithRequestProducerPriceAndStatus(): void
    {
        $this->loginAsAdmin();
        $this->makeReply(ReplyStatus::Accepted);

        $this->client->request('GET', $this->url(Action::INDEX));

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Réponses et devis', $html);
        self::assertStringContainsString('Tomates bio - 5 kg', $html);
        self::assertStringContainsString('Ferme Dupont', $html);
        self::assertStringContainsString('3,50 €/kg', $html);
        self::assertStringContainsString('Acceptée', $html);
        self::assertStringContainsString('Voir', $html);
    }

    public function testDetailShowsReplyText(): void
    {
        $this->loginAsAdmin();
        $reply = $this->makeReply();

        $this->client->request('GET', $this->url(Action::DETAIL, $reply));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Récolte de la semaine, livraison possible.', $this->client->getResponse()->getContent());
    }

    public function testRepliesCannotBeCreatedEditedOrDeletedFromBackOffice(): void
    {
        $this->loginAsAdmin();
        $reply = $this->makeReply();
        $this->client->followRedirects(false);

        $this->client->request('GET', $this->url(Action::NEW));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', $this->url(Action::EDIT, $reply));
        self::assertResponseStatusCodeSame(403);
    }
}
