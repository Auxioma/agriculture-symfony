<?php

namespace App\Entity\Trust;

use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProfile;
use App\Repository\Trust\VerificationDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: VerificationDocumentRepository::class)]
#[ORM\Table(name: 'verification_documents', schema: 'trust')]
class VerificationDocument
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'verificationDocuments')]
    #[ORM\JoinColumn(nullable: false)]
    private ProducerProfile $producer;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $fileUrl = null;

    #[ORM\Column(length: 255)]
    private string $status;

    #[ORM\ManyToOne]
    private ?User $reviewedBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProducer(): ProducerProfile
    {
        return $this->producer;
    }

    public function setProducer(ProducerProfile $producer): static
    {
        $this->producer = $producer;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getFileUrl(): ?string
    {
        return $this->fileUrl;
    }

    public function setFileUrl(?string $fileUrl): static
    {
        $this->fileUrl = $fileUrl;

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

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?User $reviewedBy): static
    {
        $this->reviewedBy = $reviewedBy;

        return $this;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?\DateTimeImmutable $reviewedAt): static
    {
        $this->reviewedAt = $reviewedAt;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }
}