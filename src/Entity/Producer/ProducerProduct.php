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

use App\Entity\Catalog\Currency;
use App\Entity\Catalog\Product;
use App\Repository\Producer\ProducerProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    #[ORM\ManyToOne(inversedBy: 'producerProducts')]
    #[ORM\JoinColumn(name: 'currency', referencedColumnName: 'code')]
    private ?Currency $currency = null;

    #[ORM\Column]
    private bool $isActive = false;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    /**
     * @var Collection<int, ProducerProductMedia>
     */
    #[ORM\OneToMany(targetEntity: ProducerProductMedia::class, mappedBy: 'producerProduct')]
    private Collection $media;

    /**
     * @var Collection<int, ProductAvailability>
     */
    #[ORM\OneToMany(targetEntity: ProductAvailability::class, mappedBy: 'producerProduct', orphanRemoval: true)]
    private Collection $productAvailabilities;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->media = new ArrayCollection();
        $this->productAvailabilities = new ArrayCollection();
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

    /**
     * @return Collection<int, ProducerProductMedia>
     */
    public function getMedia(): Collection
    {
        return $this->media;
    }

    public function addMedium(ProducerProductMedia $medium): static
    {
        if (!$this->media->contains($medium)) {
            $this->media->add($medium);
            $medium->setProducerProduct($this);
        }

        return $this;
    }

    public function removeMedium(ProducerProductMedia $medium): static
    {
        if ($this->media->removeElement($medium)) {
            if ($medium->getProducerProduct() === $this) {
                $medium->setProducerProduct(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ProductAvailability>
     */
    public function getProductAvailabilities(): Collection
    {
        return $this->productAvailabilities;
    }

    public function addProductAvailability(ProductAvailability $productAvailability): static
    {
        if (!$this->productAvailabilities->contains($productAvailability)) {
            $this->productAvailabilities->add($productAvailability);
            $productAvailability->setProducerProduct($this);
        }

        return $this;
    }

    public function removeProductAvailability(ProductAvailability $productAvailability): static
    {
        $this->productAvailabilities->removeElement($productAvailability);

        return $this;
    }
}
