<?php

namespace App\Controller\Api;

use App\Dto\Request\CreateClientRequestRequest;
use App\Entity\Catalog\Category;
use App\Entity\Catalog\Country;
use App\Entity\Catalog\Currency;
use App\Entity\Catalog\Product;
use App\Entity\Catalog\Unit;
use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Enum\RequestStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ClientRequestController extends AbstractController
{
    #[Route('/api/requests', methods: ['POST'])]
    public function createRequest(
        #[MapRequestPayload] CreateClientRequestRequest $request,
        #[CurrentUser] User $client,
        EntityManagerInterface $em,
    ): JsonResponse {
        if ($request->categoryId === null && $request->productId === null && $request->customProduct === null) {
            return $this->json(['error' => 'category, product ou customProduct est requis.'], 422);
        }

        $clientRequest = new ClientRequest();
        $clientRequest->setClient($client);
        $clientRequest->setNeedType($request->needType);
        // ! RequestStatus n'a pas de valeur par défaut dans le constructeur de ClientRequest : l'oublier
        // ! plante le flush() avec propriété typée non initialisée
        $clientRequest->setStatus(RequestStatus::Sent);
        $clientRequest->setCustomProduct($request->customProduct);
        $clientRequest->setQuantity($request->quantity);
        $clientRequest->setBudgetMin($request->budgetMin);
        $clientRequest->setBudgetMax($request->budgetMax);
        $clientRequest->setDesiredDate($request->desiredDate);
        $clientRequest->setUrgencyLevel($request->urgencyLevel);
        $clientRequest->setCity($request->city);
        $clientRequest->setPostalCode($request->postalCode);
        $clientRequest->setPickupWanted($request->pickupWanted);
        $clientRequest->setDeliveryWanted($request->deliveryWanted);
        $clientRequest->setMessage($request->message);

        if ($request->radiusKm !== null) {
            $clientRequest->setRadiusKm($request->radiusKm);
        }

        if ($request->categoryId !== null) {
            $category = $em->find(Category::class, $request->categoryId);
            if ($category === null) {
                return $this->json(['error' => 'Catégorie inconnue.'], 422);
            }
            $clientRequest->setCategory($category);
        }

        if ($request->productId !== null) {
            $product = $em->find(Product::class, $request->productId);
            if ($product === null) {
                return $this->json(['error' => 'Produit inconnu.'], 422);
            }
            $clientRequest->setProduct($product);
        }

        if ($request->unitId !== null) {
            $unit = $em->find(Unit::class, $request->unitId);
            if ($unit === null) {
                return $this->json(['error' => 'Unité inconnue.'], 422);
            }
            $clientRequest->setUnit($unit);
        }

        if ($request->currencyCode !== null) {
            $currency = $em->find(Currency::class, strtoupper($request->currencyCode));
            if ($currency === null) {
                return $this->json(['error' => 'Devise inconnue.'], 422);
            }
            $clientRequest->setCurrency($currency);
        }

        if ($request->countryCode !== null) {
            $country = $em->find(Country::class, strtoupper($request->countryCode));
            if ($country === null) {
                return $this->json(['error' => 'Pays inconnu.'], 422);
            }
            $clientRequest->setCountry($country);
        }

        // * Même format EWKT que pour ProducerProfile/DeliveryZone (PostGisMappingTest) : "SRID=4326;POINT(lon lat)".
        if ($request->latitude !== null && $request->longitude !== null) {
            $clientRequest->setLocation(sprintf('SRID=4326;POINT(%F %F)', $request->longitude, $request->latitude));
        }

        $em->persist($clientRequest);
        $em->flush();

        $em->getConnection()->executeStatement(
            'SELECT matching.populate_request_matches(:id)',
            ['id' => $clientRequest->getId()->toRfc4122()]
        );

        return $this->json(['id' => $clientRequest->getId()->toRfc4122()], 201);
    }

    #[Route('/api/client/requests', methods: ['GET'])]
    public function listMyRequests(#[CurrentUser] User $client, EntityManagerInterface $em): JsonResponse
    {
        $requests = $em->getRepository(ClientRequest::class)->findBy(
            ['client' => $client],
            ['createdAt' => 'DESC']
        );

        return $this->json(array_map(
            static fn (ClientRequest $r) => [
                'id' => $r->getId()->toRfc4122(),
                'needType' => $r->getNeedType()->value,
                'status' => $r->getStatus()->value,
                'customProduct' => $r->getCustomProduct(),
                'productId' => $r->getProduct()?->getId()->toRfc4122(),
                'categoryId' => $r->getCategory()?->getId()->toRfc4122(),
                'createdAt' => $r->getCreatedAt()->format(DATE_ATOM),
            ],
            $requests
        ));
    }

    #[Route('/api/client/requests/{id}', methods: ['GET'])]
    public function getRequestDetail(string $id, #[CurrentUser] User $client, EntityManagerInterface $em): JsonResponse
    {
        $result = $this->findOwnedRequest($id, $client, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $clientRequest = $result;

        return $this->json([
            'id' => $clientRequest->getId()->toRfc4122(),
            'needType' => $clientRequest->getNeedType()->value,
            'status' => $clientRequest->getStatus()->value,
            'customProduct' => $clientRequest->getCustomProduct(),
            'productId' => $clientRequest->getProduct()?->getId()->toRfc4122(),
            'categoryId' => $clientRequest->getCategory()?->getId()->toRfc4122(),
            'quantity' => $clientRequest->getQuantity(),
            'budgetMin' => $clientRequest->getBudgetMin(),
            'budgetMax' => $clientRequest->getBudgetMax(),
            'desiredDate' => $clientRequest->getDesiredDate()?->format('Y-m-d'),
            'urgencyLevel' => $clientRequest->getUrgencyLevel(),
            'city' => $clientRequest->getCity(),
            'postalCode' => $clientRequest->getPostalCode(),
            'pickupWanted' => $clientRequest->isPickupWanted(),
            'deliveryWanted' => $clientRequest->isDeliveryWanted(),
            'message' => $clientRequest->getMessage(),
            'createdAt' => $clientRequest->getCreatedAt()->format(DATE_ATOM),
        ]);
    }

    private function findOwnedRequest(string $id, User $client, EntityManagerInterface $em): ClientRequest|JsonResponse
    {
        $clientRequest = $em->find(ClientRequest::class, $id);

        if ($clientRequest === null) {
            return $this->json(['error' => 'Demande introuvable.'], 404);
        }

        if ($clientRequest->getClient() !== $client) {
            return $this->json(['error' => 'Accès refusé.'], 403);
        }

        return $clientRequest;
    }

    #[Route('/api/client/requests/{id}/cancel', methods: ['POST'])]
    public function cancelRequest(string $id, #[CurrentUser] User $client, EntityManagerInterface $em): JsonResponse
    {
        $result = $this->findOwnedRequest($id, $client, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        $result->setStatus(RequestStatus::Cancelled);
        $em->flush();

        return $this->json(null, 200);
    }

    #[Route('/api/client/requests/{id}/archive', methods: ['POST'])]
    public function archiveRequest(string $id, #[CurrentUser] User $client, EntityManagerInterface $em): JsonResponse
    {
        $result = $this->findOwnedRequest($id, $client, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        $result->setStatus(RequestStatus::Archived);
        $em->flush();

        return $this->json(null, 200);
    }

    #[Route('/api/client/requests/{id}/duplicate', methods: ['POST'])]
    public function duplicateRequest(string $id, #[CurrentUser] User $client, EntityManagerInterface $em): JsonResponse
    {
        $result = $this->findOwnedRequest($id, $client, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        $duplicate = $result->duplicate();
        $em->persist($duplicate);
        $em->flush();

        return $this->json(['id' => $duplicate->getId()->toRfc4122()], 201);
    }
}
