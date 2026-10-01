<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProfile;
use App\Entity\Producer\TeamMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Côté invité des invitations d'équipe (voir ProducerTeamController pour le côté producteur et le
 * périmètre volontairement restreint de cette fonctionnalité). Distinct de /api/producer/team :
 * l'invité n'a pas de ProducerProfile propre, ces routes ne peuvent donc pas vivre sous /api/producer.
 */
final class TeamInvitationController extends AbstractController
{
    #[Route('/api/team-invitations', methods: ['GET'])]
    public function listMyInvitations(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $memberships = $em->getRepository(TeamMember::class)->findBy(['idUser' => $user]);

        return $this->json(array_map(
            static fn (TeamMember $m) => [
                'producerId' => $m->getProducer()->getId()->toRfc4122(),
                'farmName' => $m->getProducer()->getFarmName(),
                'role' => $m->getRole(),
                'isActive' => $m->isActive(),
                'invitedAt' => $m->getInvitedAt()?->format(DATE_ATOM),
                'acceptedAt' => $m->getAcceptedAt()?->format(DATE_ATOM),
            ],
            $memberships
        ));
    }

    #[Route('/api/team-invitations/{producerId}/accept', methods: ['POST'])]
    public function acceptInvitation(string $producerId, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $em->find(ProducerProfile::class, $producerId);
        $membership = $producer !== null ? $em->find(TeamMember::class, ['producer' => $producer, 'idUser' => $user]) : null;
        if ($membership === null) {
            return $this->json(['error' => 'Invitation introuvable.'], 404);
        }
        if ($membership->isActive()) {
            return $this->json(['error' => 'Invitation déjà acceptée.'], 409);
        }

        $membership->setAcceptedAt(new \DateTimeImmutable());
        $membership->setIsActive(true);
        $em->flush();

        return $this->json(['producerId' => $producer->getId()->toRfc4122(), 'acceptedAt' => $membership->getAcceptedAt()->format(DATE_ATOM)]);
    }
}
