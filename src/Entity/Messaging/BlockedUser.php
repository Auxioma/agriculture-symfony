<?php

namespace App\Entity\Messaging;

use App\Entity\Identity\User;
use App\Repository\Messaging\BlockedUserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BlockedUserRepository::class)]
class BlockedUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'blockedUsers')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $blocker = null;

    #[ORM\ManyToOne(inversedBy: 'blockedUsers')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $blocked = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

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
