<?php

/**
 * Copyright(c)2026 TrouveMoi (https://trouvemoi.com)
 *
 * Ce fichier fait partie d’un projet développé par Auxioma Web Agency pour l’entreprise.
 * Tous droits réservés.
 *
 * Ce code source est la propriété exclusive de Auxioma Web Agency et.
 * Toute reproduction, modification, distribution ou utilisation sans autorisation préalable est interdite.
 */

namespace App\Entity\Producer;

use App\Repository\Producer\ProducerSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProducerSettingRepository::class)]
#[ORM\Table(name: 'producer_settings', schema: 'producer')]
class ProducerSetting
{
    #[ORM\Id]
    #[ORM\OneToOne(inversedBy: 'settings', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private ProducerProfile $producer;

    #[ORM\Column]
    private bool $acceptsIndividuals = false;

    #[ORM\Column]
    private bool $acceptsProfessionals = false;

    #[ORM\Column]
    private bool $pickupEnabled = false;

    #[ORM\Column]
    private bool $deliveryEnabled = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $minOrderInfo = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $settings = null;

    public function getProducer(): ProducerProfile
    {
        return $this->producer;
    }

    public function setProducer(ProducerProfile $producer): static
    {
        $this->producer = $producer;

        return $this;
    }

    public function isAcceptsIndividuals(): bool
    {
        return $this->acceptsIndividuals;
    }

    public function setAcceptsIndividuals(bool $acceptsIndividuals): static
    {
        $this->acceptsIndividuals = $acceptsIndividuals;

        return $this;
    }

    public function isAcceptsProfessionals(): bool
    {
        return $this->acceptsProfessionals;
    }

    public function setAcceptsProfessionals(bool $acceptsProfessionals): static
    {
        $this->acceptsProfessionals = $acceptsProfessionals;

        return $this;
    }

    public function isPickupEnabled(): bool
    {
        return $this->pickupEnabled;
    }

    public function setPickupEnabled(bool $pickupEnabled): static
    {
        $this->pickupEnabled = $pickupEnabled;

        return $this;
    }

    public function isDeliveryEnabled(): bool
    {
        return $this->deliveryEnabled;
    }

    public function setDeliveryEnabled(bool $deliveryEnabled): static
    {
        $this->deliveryEnabled = $deliveryEnabled;

        return $this;
    }

    public function getMinOrderInfo(): ?string
    {
        return $this->minOrderInfo;
    }

    public function setMinOrderInfo(?string $minOrderInfo): static
    {
        $this->minOrderInfo = $minOrderInfo;

        return $this;
    }

    public function getSettings(): ?array
    {
        return $this->settings;
    }

    public function setSettings(?array $settings): static
    {
        $this->settings = $settings;

        return $this;
    }
}
