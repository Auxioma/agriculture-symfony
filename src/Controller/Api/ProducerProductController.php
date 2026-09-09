<?php

namespace App\Controller\Api;

use App\Dto\Producer\CreateProducerProductRequest;
use App\Dto\Producer\UpdateProducerProductRequest;
use App\Entity\Catalog\Currency;
use App\Entity\Catalog\Product;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProduct;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ProducerProductController extends AbstractController
{
    #[Route('/api/producer/products', methods: ['POST'])]
    public function createProduct(
        #[MapRequestPayload] CreateProducerProductRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $product = $em->find(Product::class, $request->productId);
        if ($product === null) {
            return $this->json(['error' => 'Produit inconnu.'], 422);
        }

        $existing = $em->getRepository(ProducerProduct::class)->findOneBy(['producer' => $producer, 'product' => $product]);
        if ($existing !== null) {
            return $this->json(['error' => 'Ce produit est déjà dans votre catalogue.'], 409);
        }

        $producerProduct = new ProducerProduct();
        $producerProduct->setProducer($producer);
        $producerProduct->setProduct($product);
        $producerProduct->setVariety($request->variety);
        $producerProduct->setDescription($request->description);
        $producerProduct->setEstimatedVolume($request->estimatedVolume);
        $producerProduct->setDefaultPrice($request->defaultPrice);
        $producerProduct->setIsActive($request->isActive);

        if ($request->currencyCode !== null) {
            $currency = $em->find(Currency::class, strtoupper($request->currencyCode));
            if ($currency === null) {
                return $this->json(['error' => 'Devise inconnue.'], 422);
            }
            $producerProduct->setCurrency($currency);
        }

        $em->persist($producerProduct);
        $em->flush();

        return $this->json(['id' => $producerProduct->getId()->toRfc4122()], 201);
    }

    #[Route('/api/producer/products/{id}', methods: ['PUT'])]
    public function updateProduct(
        string $id,
        #[MapRequestPayload] UpdateProducerProductRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $result = $this->findOwnedProducerProduct($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $producerProduct = $result;

        $producerProduct->setVariety($request->variety);
        $producerProduct->setDescription($request->description);
        $producerProduct->setEstimatedVolume($request->estimatedVolume);
        $producerProduct->setDefaultPrice($request->defaultPrice);
        $producerProduct->setIsActive($request->isActive);

        if ($request->currencyCode !== null) {
            $currency = $em->find(Currency::class, strtoupper($request->currencyCode));
            if ($currency === null) {
                return $this->json(['error' => 'Devise inconnue.'], 422);
            }
            $producerProduct->setCurrency($currency);
        }

        $em->flush();

        return $this->json(null, 200);
    }

    #[Route('/api/producer/products/{id}', methods: ['DELETE'])]
    public function deleteProduct(string $id, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $result = $this->findOwnedProducerProduct($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        $em->remove($result);
        $em->flush();

        return $this->json(null, 204);
    }

    private function findOwnedProducerProduct(string $id, User $user, EntityManagerInterface $em): ProducerProduct|JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $producerProduct = $em->find(ProducerProduct::class, $id);
        if ($producerProduct === null) {
            return $this->json(['error' => 'Produit introuvable.'], 404);
        }

        if ($producerProduct->getProducer() !== $producer) {
            return $this->json(['error' => 'Accès refusé.'], 403);
        }

        return $producerProduct;
    }
}