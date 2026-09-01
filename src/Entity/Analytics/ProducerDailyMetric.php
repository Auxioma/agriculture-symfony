<?php

namespace App\Entity\Analytics;

use App\Entity\Producer\ProducerProfile;
use App\Repository\Analytics\ProducerDailyMetricRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProducerDailyMetricRepository::class)]
#[ORM\Table(name: 'producer_daily_metrics', schema: 'analytics')]
class ProducerDailyMetric
{
    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ProducerProfile $producer;

    #[ORM\Id]
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $metricDate;

    #[ORM\Column(nullable: true)]
    private ?int $profileViews = null;

    #[ORM\Column(nullable: true)]
    private ?int $matches = null;

    #[ORM\Column(nullable: true)]
    private ?int $replies = null;

    #[ORM\Column(nullable: true)]
    private ?int $conversations = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $responseRate = null;

    public function getProducer(): ProducerProfile
    {
        return $this->producer;
    }

    public function setProducer(ProducerProfile $producer): static
    {
        $this->producer = $producer;

        return $this;
    }

    public function getMetricDate(): \DateTimeImmutable
    {
        return $this->metricDate;
    }

    public function setMetricDate(\DateTimeImmutable $metricDate): static
    {
        $this->metricDate = $metricDate;

        return $this;
    }

    public function getProfileViews(): ?int
    {
        return $this->profileViews;
    }

    public function setProfileViews(?int $profileViews): static
    {
        $this->profileViews = $profileViews;

        return $this;
    }

    public function getMatches(): ?int
    {
        return $this->matches;
    }

    public function setMatches(?int $matches): static
    {
        $this->matches = $matches;

        return $this;
    }

    public function getReplies(): ?int
    {
        return $this->replies;
    }

    public function setReplies(?int $replies): static
    {
        $this->replies = $replies;

        return $this;
    }

    public function getConversations(): ?int
    {
        return $this->conversations;
    }

    public function setConversations(?int $conversations): static
    {
        $this->conversations = $conversations;

        return $this;
    }

    public function getResponseRate(): ?string
    {
        return $this->responseRate;
    }

    public function setResponseRate(?string $responseRate): static
    {
        $this->responseRate = $responseRate;

        return $this;
    }
}