<?php

namespace App\Entity\Engagement;

use App\Entity\Identity\User;
use App\Repository\Engagement\ReferralRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ReferralRepository::class)]
#[ORM\Table(name: 'referrals', schema: 'engagement')]
class Referral
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    private ?User $referrer = null;

    #[ORM\ManyToOne]
    private ?User $referred = null;

    #[ORM\Column(type: 'citext')]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $status;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getReferrer(): ?User
    {
        return $this->referrer;
    }

    public function setReferrer(?User $referrer): static
    {
        $this->referrer = $referrer;

        return $this;
    }

    public function getReferred(): ?User
    {
        return $this->referred;
    }

    public function setReferred(?User $referred): static
    {
        $this->referred = $referred;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}