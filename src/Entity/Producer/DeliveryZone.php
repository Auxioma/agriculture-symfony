<?php

namespace App\Entity\Producer;

use App\Repository\Producer\DeliveryZoneRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DeliveryZoneRepository::class)]
#[ORM\Table(name: 'delivery_zones', schema: 'producer')]
class DeliveryZone
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'deliveryZones')]
    #[ORM\JoinColumn(nullable: false)]
    private ProducerProfile $producer;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $radiusKm = null;

    #[ORM\Column(type: 'geography', options: ['geometry_type' => 'polygon', 'srid' => 4326], nullable: true)]
    private mixed $zone = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rules = null;

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

    public function getRadiusKm(): ?string
    {
        return $this->radiusKm;
    }

    public function setRadiusKm(?string $radiusKm): static
    {
        $this->radiusKm = $radiusKm;

        return $this;
    }

    public function getZone(): mixed
    {
        return $this->zone;
    }

    public function setZone(mixed $zone): static
    {
        $this->zone = $zone;

        return $this;
    }

    public function getRules(): ?array
    {
        return $this->rules;
    }

    public function setRules(?array $rules): static
    {
        $this->rules = $rules;

        return $this;
    }
}