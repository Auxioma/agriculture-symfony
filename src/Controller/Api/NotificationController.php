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

final class NotificationController extends AbstractController
{
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