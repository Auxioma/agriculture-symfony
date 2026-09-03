<?php

namespace App\Entity\Analytics;

use App\Repository\Analytics\PlatformDailyMetricRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlatformDailyMetricRepository::class)]
#[ORM\Table(name: 'platform_daily_metrics', schema: 'analytics')]
class PlatformDailyMetric
{
    #[ORM\Id]
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $metricDate;

    #[ORM\Column(nullable: true)]
    private ?int $users = null;

    #[ORM\Column(nullable: true)]
    private ?int $producers = null;

    #[ORM\Column(nullable: true)]
    private ?int $requests = null;

    #[ORM\Column(nullable: true)]
    private ?int $replies = null;

    #[ORM\Column(nullable: true)]
    private ?int $conversations = null;

    #[ORM\Column(nullable: true)]
    private ?int $subscriptions = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $revenue = null;

    public function getMetricDate(): \DateTimeImmutable
    {
        return $this->metricDate;
    }

    public function setMetricDate(\DateTimeImmutable $metricDate): static
    {
        $this->metricDate = $metricDate;

        return $this;
    }

    public function getUsers(): ?int
    {
        return $this->users;
    }

    public function setUsers(?int $users): static
    {
        $this->users = $users;

        return $this;
    }

    public function getProducers(): ?int
    {
        return $this->producers;
    }

    public function setProducers(?int $producers): static
    {
        $this->producers = $producers;

        return $this;
    }

    public function getRequests(): ?int
    {
        return $this->requests;
    }

    public function setRequests(?int $requests): static
    {
        $this->requests = $requests;

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

    public function getSubscriptions(): ?int
    {
        return $this->subscriptions;
    }

    public function setSubscriptions(?int $subscriptions): static
    {
        $this->subscriptions = $subscriptions;

        return $this;
    }

    public function getRevenue(): ?string
    {
        return $this->revenue;
    }

    public function setRevenue(?string $revenue): static
    {
        $this->revenue = $revenue;

        return $this;
    }
}