<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use App\Entity\Notification\Notification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Contrôleur API pour la gestion des notifications utilisateur.
 *
 * Fournit les points d'entrée (endpoints) REST permettant d'interroger 
 * la liste des notifications et de mettre à jour leur état de lecture.
 * Toutes les actions sont restreintes au périmètre de l'utilisateur connecté via #[CurrentUser].
 */
final class NotificationController extends AbstractController
{
    /**
     * Récupère la liste des notifications de l'utilisateur connecté.
     *
     * Permet le filtrage optionnel des notifications non lues via le paramètre de requête `unreadOnly`.
     * Les résultats sont ordonnés de la plus récente à la plus ancienne.
     *
     * @param Request                $request Paramètres HTTP (query parameter `unreadOnly=true|false`).
     * @param User                   $user    Utilisateur authentifié effectuant la requête.
     * @param EntityManagerInterface $em      Gestionnaire d'entités Doctrine.
     *
     * @return JsonResponse Liste des notifications sérialisées en JSON.
     */
    #[Route('/api/notifications', methods: ['GET'])]
    public function listNotifications(Request $request, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $criteria = ['idUser' => $user];
        if (filter_var($request->query->get('unreadOnly'), FILTER_VALIDATE_BOOLEAN)) {
            $criteria['readAt'] = null;
        }

        $notifications = $em->getRepository(Notification::class)->findBy($criteria, ['createdAt' => 'DESC']);

        return $this->json(array_map(
            static fn (Notification $n) => [
                'id' => $n->getId()->toRfc4122(),
                'type' => $n->getType(),
                'title' => $n->getTitle(),
                'body' => $n->getBody(),
                'data' => $n->getData(),
                'readAt' => $n->getReadAt()?->format(DATE_ATOM),
                'createdAt' => $n->getCreatedAt()->format(DATE_ATOM),
            ],
            $notifications
        ));
    }

    /**
     * Marque une notification spécifique comme lue.
     *
     * Vérifie que la notification existe et qu'elle appartient bien à l'utilisateur connecté
     * avant de mettre à jour la date de lecture (`readAt`).
     *
     * @param string                 $id   Identifiant UUID de la notification à marquer comme lue.
     * @param User                   $user Utilisateur authentifié effectuant la requête.
     * @param EntityManagerInterface $em   Gestionnaire d'entités Doctrine.
     *
     * @return JsonResponse Réponse vide (200 OK) ou erreur d'accès/non trouvée (404 Not Found).
     */

    #[Route('/api/notifications/{id}/read', methods: ['POST'])]
    public function markAsRead(string $id, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $notification = $em->find(Notification::class, $id);
        if ($notification === null || $notification->getIdUser() !== $user) {
            return $this->json(['error' => 'Notification introuvable.'], 404);
        }

        if ($notification->getReadAt() === null) {
            $notification->setReadAt(new \DateTimeImmutable());
            $em->flush();
        }

        return $this->json(null, 200);
    }

    /**
     * Marque l'ensemble des notifications non lues de l'utilisateur comme lues.
     *
     * Exécute une requête DQL UPDATE directe en base de données pour passer 
     * en une seule opération toutes les notifications non lues (`readAt IS NULL`) à la date courante.
     *
     * @param User                   $user Utilisateur authentifié effectuant la requête.
     * @param EntityManagerInterface $em   Gestionnaire d'entités Doctrine.
     *
     * @return JsonResponse Réponse vide (200 OK).
     */
    #[Route('/api/notifications/read-all', methods: ['POST'])]
    public function markAllAsRead(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $em->createQueryBuilder()
            ->update(Notification::class, 'n')
            ->set('n.readAt', ':now')
            ->where('n.idUser = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();

        return $this->json(null, 200);
    }
}