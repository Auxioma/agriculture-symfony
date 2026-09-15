<?php

namespace App\Controller\Api;

use App\Dto\Trust\CreateReviewRequest;
use App\Dto\Trust\RespondToReviewRequest;
use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Messaging\Conversation;
use App\Entity\Producer\ProducerProfile;
use App\Entity\Trust\Review;
use App\Enum\ReviewStatus;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Avis clients (cahier fonctionnel). Lecture publique par producteur : voir
 * ProducerController::listProducerReviews(). Modération (Publier/Rejeter) : ReviewCrudController
 * (back-office).
 */
final class ReviewController extends AbstractController
{
    #[Route('/api/reviews', methods: ['POST'])]
    public function createReview(
        #[MapRequestPayload] CreateReviewRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        NotificationService $notificationService,
    ): JsonResponse {
        $clientRequest = $em->find(ClientRequest::class, $request->requestId);
        if ($clientRequest === null || $clientRequest->getClient() !== $user) {
            return $this->json(['error' => 'Demande introuvable.'], 404);
        }

        $producer = $em->find(ProducerProfile::class, $request->producerId);
        if ($producer === null) {
            return $this->json(['error' => 'Producteur introuvable.'], 404);
        }

        // ! Un avis suppose un échange réel : on exige une Conversation entre ce client, ce producteur
        // ! et cette demande précise plutôt que de se fier à RequestMatch (qui ne prouve qu'un rapprochement
        // ! géographique automatique, pas un contact effectif) ou à DealOutcome (schéma présent mais
        // ! qu'aucune route ne branche encore).
        $hasInteracted = $em->getRepository(Conversation::class)->findOneBy([
            'request' => $clientRequest,
            'producer' => $producer,
        ]) !== null;
        if (!$hasInteracted) {
            return $this->json(['error' => "Vous ne pouvez laisser un avis que pour un producteur avec lequel vous avez échangé au sujet de cette demande."], 403);
        }

        $existing = $em->getRepository(Review::class)->findOneBy([
            'client' => $user,
            'request' => $clientRequest,
            'producer' => $producer,
        ]);
        if ($existing !== null) {
            return $this->json(['error' => 'Vous avez déjà laissé un avis pour ce producteur sur cette demande.'], 409);
        }

        $review = new Review();
        $review->setClient($user);
        $review->setProducer($producer);
        $review->setRequest($clientRequest);
        $review->setRating($request->rating);
        $review->setComment($request->comment);

        $em->persist($review);
        $em->flush();

        $owner = $producer->getOwner();
        if ($owner !== null) {
            $notificationService->notify($owner, 'new_review', 'Nouvel avis client', 'Un client a laissé un avis sur votre profil, en attente de modération.');
        }

        return $this->json(['id' => $review->getId()->toRfc4122(), 'status' => $review->getStatus()->value], 201);
    }

    #[Route('/api/reviews/{id}/response', methods: ['POST'])]
    public function respondToReview(
        string $id,
        #[MapRequestPayload] RespondToReviewRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $review = $em->find(Review::class, $id);
        if ($review === null) {
            return $this->json(['error' => 'Avis introuvable.'], 404);
        }

        $producer = $user->getProducerProfile();
        if ($producer === null || $review->getProducer() !== $producer) {
            return $this->json(['error' => 'Accès refusé.'], 403);
        }

        // * Répondre à un avis pas encore publié le rendrait visible en avant-première dès la modération --
        // * on attend que ReviewCrudController l'ait fait passer à Published.
        if ($review->getStatus() !== ReviewStatus::Published) {
            return $this->json(['error' => "Cet avis n'est pas encore publié."], 409);
        }

        $review->setProducerResponse($request->response);
        $em->flush();

        return $this->json(['id' => $review->getId()->toRfc4122()]);
    }
}
