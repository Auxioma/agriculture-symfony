<?php

namespace App\Entity\Producer;

use App\Entity\Catalog\Unit;
use App\Repository\Producer\ProductAvailabilityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProductAvailabilityRepository::class)]
#[ORM\Table(name: 'product_availabilities', schema: 'producer')]
class ProductAvailability
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'productAvailabilities')]
    #[ORM\JoinColumn(nullable: false)]
    private ProducerProduct $producerProduct;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $availableFrom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $availableTo = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $quantityEstimate = null;

    #[ORM\ManyToOne(inversedBy: 'productAvailabilities')]
    private ?Unit $unit = null;

    #[ORM\Column]
    private bool $pickupAvailable = false;

    #[ORM\Column]
    private bool $deliveryAvailable = false;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProducerProduct(): ProducerProduct
    {
        return $this->producerProduct;
    }

    public function setProducerProduct(ProducerProduct $producerProduct): static
    {
        $this->producerProduct = $producerProduct;

        return $this;
    }

    public function getAvailableFrom(): ?\DateTimeImmutable
    {
        return $this->availableFrom;
    }

    public function setAvailableFrom(?\DateTimeImmutable $availableFrom): static
    {
        $this->availableFrom = $availableFrom;

        return $this;
    }

    public function getAvailableTo(): ?\DateTimeImmutable
    {
        return $this->availableTo;
    }

    public function setAvailableTo(?\DateTimeImmutable $availableTo): static
    {
        $this->availableTo = $availableTo;

        return $this;
    }

    public function getQuantityEstimate(): ?string
    {
        return $this->quantityEstimate;
    }

    public function setQuantityEstimate(?string $quantityEstimate): static
    {
        $this->quantityEstimate = $quantityEstimate;

        return $this;
    }

    public function getUnit(): ?Unit
    {
        return $this->unit;
    }

    public function setUnit(?Unit $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function isPickupAvailable(): bool
    {
        return $this->pickupAvailable;
    }

    public function setPickupAvailable(bool $pickupAvailable): static
    {
        $this->pickupAvailable = $pickupAvailable;

        return $this;
    }

    public function isDeliveryAvailable(): bool
    {
        return $this->deliveryAvailable;
    }

    public function setDeliveryAvailable(bool $deliveryAvailable): static
    {
        $this->deliveryAvailable = $deliveryAvailable;

        return $this;
    }
}