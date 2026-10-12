<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Catalog\Country;
use App\Entity\Identity\User;
use App\Entity\Matching\ProducerReply;
use App\Entity\Matching\RequestMatch;
use App\Entity\Producer\ProducerProfile;
use App\Enum\MatchStatus;
use App\Enum\ReplyStatus;
use App\Enum\RequestStatus;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Teste les pièces jointes d'un brouillon de réponse (cahier fonctionnel : "Joindre un PDF, une photo ou un devis")
 * et ce qui en découle : envoyer le brouillon garde ses pièces jointes, refuser poliment l'abandonne.
 */
final class ProducerReplyAttachmentControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private const FILES = __DIR__.'/../../../Fixtures/files/';

    /**
     * @return array{0: string, 1: ProducerProfile}
     */
    private function loginAsProducer(): array
    {
        [$owner, $producer] = $this->makeProducer('producer');
        $this->em->flush();

        return [$this->login($owner->getEmail()), $producer];
    }

    /**
     * @return array{0: User, 1: ProducerProfile}
     */
    private function makeProducer(string $name, ?Country $country = null): array
    {
        $owner = $this->makeUserWithPassword($name, 'motdepasse123');
        $owner->setRoles([User::ROLE_PRODUCER]);

        return [$owner, $this->makeProducerProfile($owner, $country ?? $this->makeCountry(), farmName: 'Ferme '.$name)];
    }

    private function login(string $email): string
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => 'motdepasse123',
        ]));

        return json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    private function makeMatch(ProducerProfile $producer, RequestStatus $requestStatus = RequestStatus::Sent): RequestMatch
    {
        $request = $this->makeClientRequest($this->makeUser('client'.bin2hex(random_bytes(3))), null, status: $requestStatus);
        $match = new RequestMatch();
        $match->setRequest($request);
        $match->setProducer($producer);
        $match->setStatus(MatchStatus::Proposed);
        $match->setDistanceKm('4.00');
        $this->em->persist($match);

        return $match;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(string $token, string $url, array $body = []): int
    {
        $this->client->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode($body));

        return $this->client->getResponse()->getStatusCode();
    }

    // * enregistre un brouillon par la vraie route et renvoie son identifiant
    private function saveDraft(string $token, RequestMatch $match): string
    {
        self::assertSame(201, $this->postJson($token, '/api/producer/requests/'.$match->getRequest()->getId()->toRfc4122().'/reply', ['draft' => true, 'replyText' => 'Bonjour']));

        return json_decode($this->client->getResponse()->getContent(), true)['id'];
    }

    private function upload(string $token, string $replyId, string $file = 'sample.jpg', string $mime = 'image/jpeg'): int
    {
        $this->client->request('POST', '/api/producer/replies/'.$replyId.'/attachments', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], files: ['file' => new UploadedFile(self::FILES.$file, $file, $mime, null, true)], content: null);

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function rows(string $table, string $where = 'true', array $params = []): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT count(*) FROM $table WHERE $where", $params);
    }

    public function testUploadAttachesAFileToTheDraftAndTheDetailListsItWithASignedUrl(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $match = $this->makeMatch($producer);
        $this->em->flush();
        $draftId = $this->saveDraft($token, $match);

        self::assertSame(201, $this->upload($token, $draftId));
        $attachment = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame('sample.jpg', $attachment['fileName']);
        self::assertSame('image/jpeg', $attachment['mimeType']);
        self::assertStringStartsWith('https://attachments.test/', $attachment['fileUrl']);
        self::assertSame(1, $this->rows('matching.reply_attachments', 'reply_id = :id', ['id' => $draftId]));

        $this->client->request('GET', '/api/producer/requests/'.$match->getRequest()->getId()->toRfc4122(), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $draft = json_decode($this->client->getResponse()->getContent(), true)['draft'];
        self::assertSame($draftId, $draft['id']);
        self::assertSame([$attachment['id']], array_column($draft['attachments'], 'id'));
    }

    public function testUploadRefusesUnsupportedFormatsTooBigFilesAndTooManyFiles(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $match = $this->makeMatch($producer);
        $this->em->flush();
        $draftId = $this->saveDraft($token, $match);

        self::assertSame(422, $this->upload($token, $draftId, 'sample.txt', 'text/plain'));

        // * une vraie image gonflée au-delà de 10 Mo (le type est lu dans le contenu, pas dans le nom)
        $big = tempnam(sys_get_temp_dir(), 'big');
        file_put_contents($big, file_get_contents(self::FILES.'sample.jpg').str_repeat("\0", 10 * 1024 * 1024));
        $this->client->request('POST', '/api/producer/replies/'.$draftId.'/attachments', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], files: ['file' => new UploadedFile($big, 'enorme.jpg', 'image/jpeg', null, true)], content: null);
        self::assertResponseStatusCodeSame(422);
        @unlink($big);

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(201, $this->upload($token, $draftId));
        }
        self::assertSame(422, $this->upload($token, $draftId));
        self::assertSame(5, $this->rows('matching.reply_attachments'));
    }

    public function testUploadIsRefusedOutsideAnOpenDraftOfTheProducer(): void
    {
        // * tout est créé avant le premier appel HTTP, qui vide l'EntityManager
        [$owner, $producer] = $this->makeProducer('producer');
        [$otherOwner] = $this->makeProducer('other', $producer->getCountry());
        $sent = new ProducerReply();
        $sent->setRequest($this->makeMatch($producer)->getRequest());
        $sent->setProducer($producer);
        $sent->setStatus(ReplyStatus::Sent);
        $this->em->persist($sent);
        $closed = $this->makeMatch($producer);
        $open = $this->makeMatch($producer);
        $this->em->flush();
        $sentId = $sent->getId()->toRfc4122();
        $token = $this->login($owner->getEmail());
        $otherToken = $this->login($otherOwner->getEmail());
        $draftOfClosed = $this->saveDraft($token, $closed);
        $ownDraft = $this->saveDraft($token, $open);
        $this->em->getConnection()->executeStatement("UPDATE matching.client_requests SET status = 'cancelled' WHERE id = :id", ['id' => $closed->getRequest()->getId()->toRfc4122()]);

        self::assertSame(409, $this->upload($token, $sentId));
        self::assertSame(409, $this->upload($token, $draftOfClosed));
        self::assertSame(404, $this->upload($otherToken, $ownDraft));
        self::assertSame(404, $this->upload($token, Uuid::v4()->toRfc4122()));
        self::assertSame(404, $this->upload($token, 'pas-un-identifiant'));
        self::assertSame(0, $this->rows('matching.reply_attachments'));
    }

    public function testUploadRejectsAccountWithoutProducerProfile(): void
    {
        $client = $this->makeUserWithPassword('client', 'motdepasse123');
        $this->em->flush();

        self::assertSame(403, $this->upload($this->login($client->getEmail()), Uuid::v4()->toRfc4122()));
    }

    public function testDeleteRemovesTheAttachmentOfTheDraftOnly(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $first = $this->makeMatch($producer);
        $second = $this->makeMatch($producer);
        $this->em->flush();
        $draftId = $this->saveDraft($token, $first);
        $otherDraftId = $this->saveDraft($token, $second);
        $this->upload($token, $draftId);
        $attachmentId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $delete = function (string $replyId, string $id) use ($token): int {
            $this->client->request('DELETE', '/api/producer/replies/'.$replyId.'/attachments/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

            return $this->client->getResponse()->getStatusCode();
        };

        // * une pièce jointe ne se supprime que par le brouillon auquel elle appartient
        self::assertSame(404, $delete($otherDraftId, $attachmentId));
        self::assertSame(204, $delete($draftId, $attachmentId));
        self::assertSame(0, $this->rows('matching.reply_attachments'));
        self::assertSame(404, $delete($draftId, $attachmentId));
        self::assertSame(404, $delete($draftId, 'pas-un-identifiant'));
    }

    public function testSendingTheDraftKeepsItsAttachmentsOnTheSentReply(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $this->makeActiveSubscription($producer);
        $match = $this->makeMatch($producer);
        $this->em->flush();
        $requestId = $match->getRequest()->getId()->toRfc4122();
        $draftId = $this->saveDraft($token, $match);
        $this->upload($token, $draftId);

        self::assertSame(201, $this->postJson($token, '/api/producer/requests/'.$requestId.'/reply', ['replyText' => 'Voici mon devis', 'priceAmount' => '12']));
        $sentId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        self::assertNotSame($draftId, $sentId);
        self::assertSame(0, $this->rows('matching.producer_replies', 'id = :id', ['id' => $draftId]));
        self::assertSame(1, $this->rows('matching.reply_attachments', 'reply_id = :id', ['id' => $sentId]));

        $this->client->request('GET', '/api/producer/quotes/'.$sentId, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $attachments = json_decode($this->client->getResponse()->getContent(), true)['attachments'];
        self::assertSame('sample.jpg', $attachments[0]['fileName']);
        self::assertStringStartsWith('https://attachments.test/', $attachments[0]['fileUrl']);
    }

    public function testDecliningAbandonsTheDraftAndItsAttachmentsAndCannotBeDoneTwice(): void
    {
        [$token, $producer] = $this->loginAsProducer();
        $match = $this->makeMatch($producer);
        $this->em->flush();
        $requestId = $match->getRequest()->getId()->toRfc4122();
        $draftId = $this->saveDraft($token, $match);
        $this->upload($token, $draftId);

        self::assertSame(201, $this->postJson($token, '/api/producer/requests/'.$requestId.'/decline'));

        self::assertSame(0, $this->rows('matching.producer_replies', 'id = :id', ['id' => $draftId]));
        self::assertSame(0, $this->rows('matching.reply_attachments'));
        self::assertSame(1, $this->rows('matching.producer_replies', "status = 'declined'"));
        self::assertSame(409, $this->postJson($token, '/api/producer/requests/'.$requestId.'/decline'));
        self::assertSame(1, $this->rows('matching.producer_replies'));
    }
}
