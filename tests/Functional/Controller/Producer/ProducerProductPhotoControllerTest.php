<?php

namespace App\Tests\Functional\Controller\Producer;

use App\Entity\Catalog\Country;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProduct;
use App\Entity\Producer\ProducerProductMedia;
use App\Entity\Producer\ProducerProfile;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Teste POST et DELETE /api/producer/products/{id}/photos : les photos d'un produit du producteur.
 * Le stockage réel (MinIO/S3) est remplacé par l'adaptateur Flysystem "local" en environnement de test
 * (voir ProducerMediaControllerTest).
 */
final class ProducerProductPhotoControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    /**
     * @return array{0: string, 1: ProducerProfile}
     */
    private function registerProducerAndLogin(string $emailPrefix = 'producer', string $countryCode = 'FR'): array
    {
        $country = $this->em->getRepository(Country::class)->find($countryCode) ?? $this->makeCountry($countryCode);
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

        // * chaque requête HTTP vide l'identity map : on repart d'une entité "managed" (voir ProducerProductControllerTest)
        return [$token, $this->em->getRepository(ProducerProfile::class)->find($producer->getId())];
    }

    private function sampleJpeg(): UploadedFile
    {
        // * dernier argument : mode test, le fichier n'a pas transité par une vraie requête HTTP
        return new UploadedFile(__DIR__.'/../../../Fixtures/files/sample.jpg', 'sample.jpg', 'image/jpeg', null, true);
    }

    private function newProduct(ProducerProfile $producer): ProducerProduct
    {
        $item = $this->makeProducerProduct($producer, $this->makeProduct($this->makeCategory(), 'Tomates'));
        $this->em->flush();

        return $item;
    }

    public function testUploadPhotoToOwnProduct(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $id = $this->newProduct($producer)->getId()->toRfc4122();

        $this->client->request('POST', '/api/producer/products/'.$id.'/photos', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token], files: ['photo' => $this->sampleJpeg()]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertStringContainsString($producer->getId()->toRfc4122().'/products/', $data['url']);
        $row = $this->em->getConnection()->fetchAssociative('SELECT producer_product_id, file_url, position FROM producer.producer_product_media WHERE id = :id', ['id' => $data['id']]);
        self::assertSame($id, $row['producer_product_id']);
        self::assertSame($data['url'], $row['file_url']);
        self::assertSame(0, (int) $row['position']);

        // * la deuxième photo passe derrière la première, et la liste des produits les rend dans l'ordre
        $this->client->request('POST', '/api/producer/products/'.$id.'/photos', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token], files: ['photo' => $this->sampleJpeg()]);
        self::assertResponseStatusCodeSame(201);
        $second = json_decode($this->client->getResponse()->getContent(), true);

        $this->client->request('GET', '/api/producer/products', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $photos = json_decode($this->client->getResponse()->getContent(), true)[0]['photos'];
        self::assertSame([$data['id'], $second['id']], array_column($photos, 'id'));
    }

    public function testUploadRejectsAnythingButAnImage(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $id = $this->newProduct($producer)->getId()->toRfc4122();
        $text = new UploadedFile(__DIR__.'/../../../Fixtures/files/sample.txt', 'sample.txt', 'text/plain', null, true);

        $this->client->request('POST', '/api/producer/products/'.$id.'/photos', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token], files: ['photo' => $text]);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/api/producer/products/'.$id.'/photos', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUploadRefusesAFifthPlusOnePhoto(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $item = $this->newProduct($producer);
        for ($i = 0; $i < 5; ++$i) {
            $item->addMedium((new ProducerProductMedia())->setFileUrl('http://localhost/test-storage/'.$i.'.jpg')->setPosition($i));
        }
        foreach ($item->getMedia() as $photo) {
            $this->em->persist($photo);
        }
        $this->em->flush();

        $this->client->request('POST', '/api/producer/products/'.$item->getId()->toRfc4122().'/photos', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token], files: ['photo' => $this->sampleJpeg()]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Cinq photos', $this->client->getResponse()->getContent());
    }

    public function testCannotTouchTheProductOfAnotherProducerOrAnUnknownOne(): void
    {
        $this->makeCountry();
        $this->em->flush();
        [, $producerA] = $this->registerProducerAndLogin('producerA');
        [$tokenB] = $this->registerProducerAndLogin('producerB');
        $producerA = $this->em->getRepository(ProducerProfile::class)->find($producerA->getId());
        $item = $this->makeProducerProduct($producerA, $this->makeProduct($this->makeCategory(), 'Tomates'));
        $photo = (new ProducerProductMedia())->setFileUrl('http://localhost/test-storage/a.jpg')->setPosition(0);
        $item->addMedium($photo);
        $this->em->persist($photo);
        $this->em->flush();
        $id = $item->getId()->toRfc4122();
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$tokenB];

        $this->client->request('POST', '/api/producer/products/'.$id.'/photos', server: $server, files: ['photo' => $this->sampleJpeg()]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('DELETE', '/api/producer/products/'.$id.'/photos/'.$photo->getId()->toRfc4122(), server: $server);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/api/producer/products/'.\Symfony\Component\Uid\Uuid::v4()->toRfc4122().'/photos', server: $server, files: ['photo' => $this->sampleJpeg()]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/api/producer/products/pas-un-identifiant/photos', server: $server, files: ['photo' => $this->sampleJpeg()]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAccountWithoutProducerProfileIsRefused(): void
    {
        $token = $this->registerClientAndLogin();

        $this->client->request('POST', '/api/producer/products/'.\Symfony\Component\Uid\Uuid::v4()->toRfc4122().'/photos', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeletePhotoRemovesItsRowAndItsFile(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $id = $this->newProduct($producer)->getId()->toRfc4122();
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        $this->client->request('POST', '/api/producer/products/'.$id.'/photos', server: $server, files: ['photo' => $this->sampleJpeg()]);
        $photo = json_decode($this->client->getResponse()->getContent(), true);
        // * adaptateur local de test : le fichier est sous var/storage/test, à l'adresse de l'URL publique
        $file = \dirname(__DIR__, 4).'/var/storage/test/'.substr($photo['url'], strlen('http://localhost/test-storage/'));
        self::assertFileExists($file);

        $this->client->request('DELETE', '/api/producer/products/'.$id.'/photos/'.$photo['id'], server: $server);

        self::assertResponseStatusCodeSame(204);
        self::assertFileDoesNotExist($file);
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT id FROM producer.producer_product_media WHERE id = :id', ['id' => $photo['id']]));

        $this->client->request('DELETE', '/api/producer/products/'.$id.'/photos/'.$photo['id'], server: $server);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletingTheProductDeletesItsPhotoFiles(): void
    {
        [$token, $producer] = $this->registerProducerAndLogin();
        $id = $this->newProduct($producer)->getId()->toRfc4122();
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        $this->client->request('POST', '/api/producer/products/'.$id.'/photos', server: $server, files: ['photo' => $this->sampleJpeg()]);
        $photo = json_decode($this->client->getResponse()->getContent(), true);
        $file = \dirname(__DIR__, 4).'/var/storage/test/'.substr($photo['url'], strlen('http://localhost/test-storage/'));
        self::assertFileExists($file);

        $this->client->request('DELETE', '/api/producer/products/'.$id, server: $server);

        self::assertResponseStatusCodeSame(204);
        self::assertFileDoesNotExist($file);
    }
}
