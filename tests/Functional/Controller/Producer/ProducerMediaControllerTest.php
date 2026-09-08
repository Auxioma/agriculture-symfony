<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Teste POST /api/producer/photos (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf, round 4).
 * Le stockage réel (MinIO/S3) est remplacé par l'adaptateur Flysystem "local" en environnement de test
 * (config/packages/flysystem.yaml, bloc when@test) -- écrit dans var/storage/test, jamais commité.
 */
final class ProducerMediaControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private function registerProducerAndLogin(string $emailPrefix = 'producer'): array
    {
        $country = $this->makeCountry();
        // * makeUserWithPassword() (pas makeUser()) : ce compte doit pouvoir se logger pour de vrai
        // * via /api/auth/login plus bas.
        $owner = $this->makeUserWithPassword($emailPrefix, 'motdepasse123');
        $owner->setRoles([User::ROLE_PRODUCER]);
        $producer = $this->makeProducerProfile($owner, $country, farmName: 'Ferme '.$emailPrefix);
        $this->em->flush();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $owner->getEmail(),
            'password' => 'motdepasse123',
        ]));
        self::assertResponseIsSuccessful();
        $token = json_decode($this->client->getResponse()->getContent(), true)['token'];

        return [$token, $producer];
    }

    public function testUploadPhotoSucceeds(): void
    {
        [$token] = $this->registerProducerAndLogin();

        $file = new UploadedFile(
            __DIR__.'/../../../Fixtures/files/sample.jpg',
            'sample.jpg',
            'image/jpeg',
            null,
            true // * test mode : ne vérifie pas is_uploaded_file(), le fichier n'a pas transité par une vraie requête HTTP
        );

        $this->client->request('POST', '/api/producer/photos', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token], files: ['photo' => $file]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('url', $data);

        $row = $this->em->getConnection()->fetchAssociative('SELECT file_url, is_public FROM producer.producer_media WHERE id = :id', ['id' => $data['id']]);
        self::assertNotFalse($row);
        self::assertTrue((bool) $row['is_public']);
    }

    public function testUploadPhotoRejectsUnsupportedMimeType(): void
    {
        [$token] = $this->registerProducerAndLogin();

        $file = new UploadedFile(
            __DIR__.'/../../../Fixtures/files/sample.txt',
            'sample.txt',
            'text/plain',
            null,
            true
        );

        $this->client->request('POST', '/api/producer/photos', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token], files: ['photo' => $file]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUploadPhotoRejectsAccountWithoutProducerProfile(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('POST', '/api/producer/photos', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(403);
    }
}
