<?php

namespace App\Entity\Messaging;

use App\Entity\Identity\User;
use App\Repository\Messaging\MessageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MessageRepository::class)]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Conversation $conversation = null;

    #[ORM\ManyToOne(inversedBy: 'content')]
    private ?User $sender = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    #[ORM\Column]
    private array $metadata = [];

    #[ORM\Column]
    private ?bool $isSystem = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $moderatedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * @var Collection<int, MessageAttachment>
     */
    #[ORM\OneToMany(targetEntity: MessageAttachment::class, mappedBy: 'message', orphanRemoval: true)]
    private Collection $messageAttachments;

    public function __construct()
    {
        $this->messageAttachments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): ?Conversation
    {
        return $this->conversation;
    }

    public function setConversation(?Conversation $conversation): static
    {
        $this->conversation = $conversation;

        return $this;
    }

    public function getSender(): ?User
    {
        return $this->sender;
    }

    public function setSender(?User $sender): static
    {
        $this->sender = $sender;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function isSystem(): ?bool
    {
        return $this->isSystem;
    }

    public function setIsSystem(bool $isSystem): static
    {
        $this->isSystem = $isSystem;

        return $this;
    }

    public function getModeratedAt(): ?\DateTimeImmutable
    {
        return $this->moderatedAt;
    }

    public function setModeratedAt(?\DateTimeImmutable $moderatedAt): static
    {
        $this->moderatedAt = $moderatedAt;

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

    /**
     * @return Collection<int, MessageAttachment>
     */
    public function getMessageAttachments(): Collection
    {
        return $this->messageAttachments;
    }

    public function addMessageAttachment(MessageAttachment $messageAttachment): static
    {
        if (!$this->messageAttachments->contains($messageAttachment)) {
            $this->messageAttachments->add($messageAttachment);
            $messageAttachment->setMessage($this);
        }

        return $this;
    }

    public function removeMessageAttachment(MessageAttachment $messageAttachment): static
    {
        if ($this->messageAttachments->removeElement($messageAttachment)) {
            // set the owning side to null (unless already changed)
            if ($messageAttachment->getMessage() === $this) {
                $messageAttachment->setMessage(null);
            }
        }

        return $this;
    }
}
