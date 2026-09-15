<?php

namespace App\Controller\Api;

use App\Dto\Producer\InviteTeamMemberRequest;
use App\Entity\Identity\User;
use App\Entity\Producer\TeamMember;
use App\Service\Notification\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Équipe multi-utilisateurs du producteur connecté (cahier fonctionnel, "Équipe exploitation" --
 * classée V2/Premium dans le cahier, section 3). Version volontairement restreinte : invitation et
 * liste des membres seulement. N'accorde encore AUCUN droit d'agir sur les autres routes producteur
 * (ConversationController, ProducerRequestController, etc.) -- toutes résolvent "le" producteur via
 * User::getProducerProfile(), une relation un-compte-un-profil qu'un membre d'équipe invité n'a
 * jamais. Étendre ces droits est un chantier séparé (retoucher l'autorisation de chaque contrôleur
 * producteur), volontairement hors périmètre ici. Acceptation d'une invitation : voir
 * TeamInvitationController (l'invité n'a pas de profil producteur, ces routes-ci ne lui sont pas
 * accessibles).
 */

final class ProducerTeamController extends AbstractController
{
    #[Route('/api/producer/team', methods: ['GET'])]
    public function listMyTeam(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $members = $em->getRepository(TeamMember::class)->findBy(['producer' => $producer]);

        return $this->json(array_map($this->describe(...), $members));
    }

    #[Route('/api/producer/team', methods: ['POST'])]
    public function inviteMember(
        #[MapRequestPayload] InviteTeamMemberRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        NotificationService $notificationService,
    ): JsonResponse {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $invitee = $em->getRepository(User::class)->findOneBy(['email' => $request->email]);
        if ($invitee === null) {
            return $this->json(['error' => 'Aucun compte ne correspond à cet email.'], 404);
        }
        if ($invitee === $user) {
            return $this->json(['error' => 'Vous ne pouvez pas vous inviter vous-même.'], 422);
        }

        $existing = $em->find(TeamMember::class, ['producer' => $producer, 'idUser' => $invitee]);
        if ($existing !== null) {
            return $this->json(['error' => 'Cette personne est déjà membre (ou invitée) de votre équipe.'], 409);
        }

        $member = new TeamMember();
        $member->setProducer($producer);
        $member->setIdUser($invitee);
        $member->setRole($request->role);
        $member->setInvitedAt(new \DateTimeImmutable());

        $em->persist($member);
        $em->flush();

        $notificationService->notify(
            $invitee,
            'team_invitation',
            'Invitation à rejoindre une équipe',
            sprintf('%s vous invite à rejoindre son équipe sur TrouveMoi Agri.', $producer->getFarmName()),
        );

        return $this->json($this->describe($member), 201);
    }

    #[Route('/api/producer/team/{userId}', methods: ['DELETE'])]
    public function removeMember(string $userId, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $invitee = $em->find(User::class, $userId);
        $member = $invitee !== null ? $em->find(TeamMember::class, ['producer' => $producer, 'idUser' => $invitee]) : null;
        if ($member === null) {
            return $this->json(['error' => 'Membre introuvable.'], 404);
        }

        $em->remove($member);
        $em->flush();

        return $this->json(null, 204);
    }

    /**
     * @return array{userId: string, email: string, role: string|null, isActive: bool, invitedAt: string|null, acceptedAt: string|null}
     */
    private function describe(TeamMember $member): array
    {
        return [
            'userId' => $member->getIdUser()->getId()->toRfc4122(),
            'email' => $member->getIdUser()->getEmail(),
            'role' => $member->getRole(),
            'isActive' => $member->isActive(),
            'invitedAt' => $member->getInvitedAt()?->format(DATE_ATOM),
            'acceptedAt' => $member->getAcceptedAt()?->format(DATE_ATOM),
        ];
    }
}
