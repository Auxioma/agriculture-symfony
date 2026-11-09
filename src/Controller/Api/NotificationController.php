<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use App\Entity\Notification\Notification;
use App\Service\Pagination\Pagination;
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
    private const MAX_LIMIT = 100;

    /**
     * Récupère la liste des notifications de l'utilisateur connecté.
     *
     * Permet le filtrage optionnel des notifications non lues via le paramètre de requête `unreadOnly`.
     * Les résultats sont ordonnés de la plus récente à la plus ancienne, par pages (?page=, ?pageSize= : 20 par défaut, 100 au
     * plus ; le total de toutes les pages dans X-Total-Count). `limit` reste accepté : c'est la taille de page par défaut
     * quand pageSize est absent (la cloche de la navbar n'en veut que quelques-unes, inutile de renvoyer tout l'historique).
     *
     * @param Request                $request Paramètres HTTP (query parameters `unreadOnly=true|false`, `limit=N`).
     * @param User                   $user    Utilisateur authentifié effectuant la requête.
     * @param EntityManagerInterface $em      Gestionnaire d'entités Doctrine.
     *
     * @return JsonResponse Liste des notifications sérialisées en JSON.
     */
    #[Route('/api/notifications', methods: ['GET'])]
    public function listNotifications(Request $request, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        // * limit absent, illisible, 0 ou négatif : la taille par défaut ; plafonné pour qu'un grand nombre ne la contourne pas.
        // * filter_var plutôt que getInt() : getInt() répond 400 sur "abc" au lieu de l'ignorer.
        $limit = (int) filter_var($request->query->get('limit'), FILTER_VALIDATE_INT);
        $pagination = Pagination::fromRequest(
            $request,
            defaultPageSize: $limit > 0 ? min($limit, self::MAX_LIMIT) : Pagination::DEFAULT_PAGE_SIZE,
            maxPageSize: self::MAX_LIMIT,
        );
        if ($pagination === null) {
            return $this->json(['error' => Pagination::invalidMessage(self::MAX_LIMIT)], 400);
        }

        $queryBuilder = $em->getRepository(Notification::class)->createQueryBuilder('n')
            ->where('n.idUser = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            // * n.id en dernier tri : deux notifications de la même seconde ne doivent pas changer de page d'un appel à l'autre
            ->addOrderBy('n.id', 'ASC');
        if (filter_var($request->query->get('unreadOnly'), FILTER_VALIDATE_BOOLEAN)) {
            $queryBuilder->andWhere('n.readAt IS NULL');
        }
        [$notifications, $total] = $pagination->slice($queryBuilder);

        return $pagination->withTotal($this->json(array_map(
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
        )), $total);
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