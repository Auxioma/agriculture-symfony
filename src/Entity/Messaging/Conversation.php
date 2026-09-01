<?php

namespace App\Entity\Messaging;

use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Producer\ProducerProfile;
use App\Enum\ConversationStatus;
use App\Repository\Messaging\ConversationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConversationRepository::class)]
class Conversation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'conversations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?ClientRequest $request = null;

    #[ORM\ManyToOne(inversedBy: 'conversations')]
    private ?User $client = null;

    #[ORM\ManyToOne(inversedBy: 'conversations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?ProducerProfile $producer = null;

    #[ORM\Column(enumType: ConversationStatus::class)]
    private ?ConversationStatus $status = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastMessageAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): ?ClientRequest
    {
        return $this->request;
    }

    public function setRequest(?ClientRequest $request): static
    {
        $this->request = $request;

        return $this;
    }

    public function getClient(): ?User
    {
        return $this->client;
    }

    public function setClient(?User $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getProducer(): ?ProducerProfile
    {
        return $this->producer;
    }

    public function setProducer(?ProducerProfile $producer): static
    {
        $this->producer = $producer;

        return $this;
    }

    public function getStatus(): ?ConversationStatus
    {
        return $this->status;
    }

    public function setStatus(ConversationStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getLastMessageAt(): ?\DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function setLastMessageAt(?\DateTimeImmutable $lastMessageAt): static
    {
        $this->lastMessageAt = $lastMessageAt;

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
