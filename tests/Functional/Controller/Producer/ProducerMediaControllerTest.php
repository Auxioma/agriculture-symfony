<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Identity\User;
use App\Entity\Producer\ProducerMedia;
use App\Entity\Producer\ProducerProfile;
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

    private function sample(): UploadedFile
    {
        return new UploadedFile(__DIR__.'/../../../Fixtures/files/sample.jpg', 'sample.jpg', 'image/jpeg', null, true);
    }

    // * adaptateur local de test : le fichier est sous var/storage/test, à l'adresse de l'URL publique
    private function storedFile(string $url): string
    {
        return \dirname(__DIR__, 4).'/var/storage/test/'.substr($url, strlen('http://localhost/test-storage/'));
    }

    public function testUploadWithoutTypeStaysAGalleryPhoto(): void
    {
        [$token] = $this->registerProducerAndLogin();

        $this->client->request('POST', '/api/producer/photos', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token], files: ['photo' => $this->sample()]);

        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];
        self::assertSame('photo', $this->em->getConnection()->fetchOne('SELECT type FROM producer.producer_media WHERE id = :id', ['id' => $id]));
    }

    public function testUploadRejectsAnUnknownType(): void
    {
        [$token] = $this->registerProducerAndLogin();

        $this->client->request('POST', '/api/producer/photos', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token], parameters: ['type' => 'logo'], files: ['photo' => $this->sample()]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testANewCoverReplacesTheOldOneAndComesBeforeTheGalleryPhotos(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        $this->client->request('POST', '/api/producer/photos', server: $server, files: ['photo' => $this->sample()]);
        $gallery = json_decode($this->client->getResponse()->getContent(), true);
        // * une photo de galerie en position 0, comme celle des fixtures : la couverture doit passer devant, pas seulement devant « sans position »
        $positioned = (new ProducerMedia())->setProducer($this->em->getReference(ProducerProfile::class, $producer->getId()))->setType('photo')->setFileUrl('http://localhost/test-storage/galerie.jpg')->setPosition(0)->setIsPublic(true);
        $this->em->persist($positioned);
        $this->em->flush();
        $positionedId = $positioned->getId()->toRfc4122();

        $this->client->request('POST', '/api/producer/photos', server: $server, parameters: ['type' => 'cover'], files: ['photo' => $this->sample()]);
        self::assertResponseStatusCodeSame(201);
        $first = json_decode($this->client->getResponse()->getContent(), true);
        $this->client->request('POST', '/api/producer/photos', server: $server, parameters: ['type' => 'cover'], files: ['photo' => $this->sample()]);
        $second = json_decode($this->client->getResponse()->getContent(), true);

        // * une seule couverture : la première (ligne et fichier) est partie, la photo de galerie reste
        $rows = $this->em->getConnection()->fetchAllKeyValue('SELECT id, type FROM producer.producer_media WHERE producer_id = :id', ['id' => $producer->getId()->toRfc4122()]);
        self::assertEquals([$gallery['id'] => 'photo', $positionedId => 'photo', $second['id'] => 'cover'], $rows);
        self::assertFileDoesNotExist($this->storedFile($first['url']));
        self::assertFileExists($this->storedFile($second['url']));
        self::assertFileExists($this->storedFile($gallery['url']));

        // * c'est elle que la fiche publique et « Modifier mon profil » prennent en couverture
        $this->client->request('GET', '/api/producers/'.$producer->getId()->toRfc4122());
        self::assertSame($second['url'], json_decode($this->client->getResponse()->getContent(), true)['photoUrl']);
        $this->client->request('GET', '/api/producer/profile', server: $server);
        self::assertSame($second['url'], json_decode($this->client->getResponse()->getContent(), true)['photoUrl']);
    }

    public function testACoverNeverTouchesAnotherProducersPhotos(): void
    {
        [$token] = $this->registerProducerAndLogin();
        $other = $this->makeProducerProfile($this->makeUser('autre'), $this->makeCountry('BE', 'Belgique'), farmName: 'Ferme Autre');
        $this->em->persist((new ProducerMedia())->setProducer($other)->setType('cover')->setFileUrl('http://localhost/test-storage/autre.jpg')->setPosition(-1)->setIsPublic(true));
        $this->em->flush();

        $this->client->request('POST', '/api/producer/photos', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token], parameters: ['type' => 'cover'], files: ['photo' => $this->sample()]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT count(*) FROM producer.producer_media WHERE producer_id = :id AND type = 'cover'", ['id' => $other->getId()->toRfc4122()]));
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
