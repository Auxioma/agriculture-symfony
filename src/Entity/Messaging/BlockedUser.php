<?php

/**
 * Blocage entre deux utilisateurs (cahier fonctionnel : un client bloque un producteur ou inversement).
 * Clé composite (blocker, blocked) -- l'une des 10 entités à clé composite intentionnelle du projet, voir
 * trouvemoi-agri-make-entity-guide.md. Déjà lue par ConversationController::sendMessage()/uploadAttachment()
 * pour refuser l'envoi si le destinataire a bloqué l'expéditeur, mais aucune route ne crée encore de
 * BlockedUser : il n'existe pas encore d'action "bloquer cet utilisateur" côté client.
 */

namespace App\Entity\Messaging;

use App\Entity\Identity\User;
use App\Repository\Messaging\BlockedUserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BlockedUserRepository::class)]
#[ORM\Table(name: 'blocked_users', schema: 'messaging')]
class BlockedUser
{
    #[ORM\Id]
    #[ORM\ManyToOne(inversedBy: 'blockedUsers')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $blocker = null;

    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $blocked = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function getBlocker(): ?User
    {
        return $this->blocker;
    }

    public function setBlocker(?User $blocker): static
    {
        $this->blocker = $blocker;

        return $this;
    }

    public function getBlocked(): ?User
    {
        return $this->blocked;
    }

    public function setBlocked(?User $blocked): static
    {
        $this->blocked = $blocked;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
