<?php

namespace App\Entity\Trust;

use App\Entity\Identity\User;
use App\Enum\ReportStatus;
use App\Repository\Trust\ReportRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ReportRepository::class)]
#[ORM\Table(name: 'reports', schema: 'trust')]
class Report
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'reports')]
    private ?User $reporter = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $targetType = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $targetId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(enumType: ReportStatus::class)]
    private ReportStatus $status = ReportStatus::Open;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $decision = null;

    #[ORM\ManyToOne]
    private ?User $reviewedBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, ModerationAction>
     */
    #[ORM\OneToMany(targetEntity: ModerationAction::class, mappedBy: 'report', orphanRemoval: true)]
    private Collection $moderationActions;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->moderationActions = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getReporter(): ?User
    {
        return $this->reporter;
    }

    public function setReporter(?User $reporter): static
    {
        $this->reporter = $reporter;

        return $this;
    }

    public function getTargetType(): ?string
    {
        return $this->targetType;
    }

    public function setTargetType(?string $targetType): static
    {
        $this->targetType = $targetType;

        return $this;
    }

    public function getTargetId(): ?Uuid
    {
        return $this->targetId;
    }

    public function setTargetId(?Uuid $targetId): static
    {
        $this->targetId = $targetId;

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

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getStatus(): ReportStatus
    {
        return $this->status;
    }

    public function setStatus(ReportStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getDecision(): ?string
    {
        return $this->decision;
    }

    public function setDecision(?string $decision): static
    {
        $this->decision = $decision;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, ModerationAction>
     */
    public function getModerationActions(): Collection
    {
        return $this->moderationActions;
    }

    public function addModerationAction(ModerationAction $moderationAction): static
    {
        if (!$this->moderationActions->contains($moderationAction)) {
            $this->moderationActions->add($moderationAction);
            $moderationAction->setReport($this);
        }

        return $this;
    }

    public function removeModerationAction(ModerationAction $moderationAction): static
    {
        $this->moderationActions->removeElement($moderationAction);

        return $this;
    }
}