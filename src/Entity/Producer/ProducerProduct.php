<?php

namespace App\Entity\Producer;

use App\Entity\Catalog\Currency;
use App\Entity\Catalog\Product;
use App\Repository\Producer\ProducerProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProducerProductRepository::class)]
#[ORM\Table(name: 'producer_products', schema: 'producer')]
class ProducerProduct
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: false)]
    private ProducerProfile $producer;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Product $product;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $variety = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $estimatedVolume = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $defaultPrice = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'currency', referencedColumnName: 'code')]
    private ?Currency $currency = null;

    #[ORM\Column]
    private bool $isActive = false;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

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

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getVariety(): ?string
    {
        return $this->variety;
    }

    public function setVariety(?string $variety): static
    {
        $this->variety = $variety;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getEstimatedVolume(): ?string
    {
        return $this->estimatedVolume;
    }

    public function setEstimatedVolume(?string $estimatedVolume): static
    {
        $this->estimatedVolume = $estimatedVolume;

        return $this;
    }

    public function getDefaultPrice(): ?string
    {
        return $this->defaultPrice;
    }

    public function setDefaultPrice(?string $defaultPrice): static
    {
        $this->defaultPrice = $defaultPrice;

        return $this;
    }

    public function getCurrency(): ?Currency
    {
        return $this->currency;
    }

    public function setCurrency(?Currency $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }
}