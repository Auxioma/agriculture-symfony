<?php

/**
 * Résultat de l'envoi d'une Notification sur un canal précis, créé par NotificationService::notify() --
 * une ligne $channel="in_app" toujours $status="sent" (l'enregistrement en base suffit), une ligne
 * $channel="email" à $status="sent" ou "failed" (avec $failedAt) selon que MailerInterface a levé une
 * TransportExceptionInterface ou non. NotificationService avale toujours cette exception : un échec
 * d'email n'interrompt jamais le flux appelant, il est seulement tracé ici.
 */

namespace App\Entity\Notification;

use App\Repository\Notification\NotificationDeliveryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: NotificationDeliveryRepository::class)]
#[ORM\Table(name: 'notification_deliveries', schema: 'notification')]
class NotificationDelivery
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'deliveries')]
    #[ORM\JoinColumn(nullable: false)]
    private Notification $notification;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $channel = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $destination = null;

    #[ORM\Column(length: 255)]
    private string $status;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerId = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNotification(): Notification
    {
        return $this->notification;
    }

    public function setNotification(Notification $notification): static
    {
        $this->notification = $notification;

        return $this;
    }

    public function getChannel(): ?string
    {
        return $this->channel;
    }

    public function setChannel(?string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getDestination(): ?string
    {
        return $this->destination;
    }

    public function setDestination(?string $destination): static
    {
        $this->destination = $destination;

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

    public function getProviderId(): ?string
    {
        return $this->providerId;
    }

    public function setProviderId(?string $providerId): static
    {
        $this->providerId = $providerId;

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getFailedAt(): ?\DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function setFailedAt(?\DateTimeImmutable $failedAt): static
    {
        $this->failedAt = $failedAt;

        return $this;
    }
}
