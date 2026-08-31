<?php

namespace App\Entity\Catalog;

use App\Entity\Producer\ProductAvailability;
use App\Repository\Catalog\UnitRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UnitRepository::class)]
#[ORM\Table(name: 'units', schema: 'catalog')]
class Unit
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'citext')]
    private string $code;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $unitType = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $localeLabels = null;

    /**
     * @var Collection<int, ProductAvailability>
     */
    #[ORM\OneToMany(targetEntity: ProductAvailability::class, mappedBy: 'unit')]
    private Collection $productAvailabilities;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->productAvailabilities = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getUnitType(): ?string
    {
        return $this->unitType;
    }

    public function setUnitType(?string $unitType): static
    {
        $this->unitType = $unitType;

        return $this;
    }

    public function getLocaleLabels(): ?array
    {
        return $this->localeLabels;
    }

    public function setLocaleLabels(?array $localeLabels): static
    {
        $this->localeLabels = $localeLabels;

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
            $productAvailability->setUnit($this);
        }

        return $this;
    }

    public function removeProductAvailability(ProductAvailability $productAvailability): static
    {
        if ($this->productAvailabilities->removeElement($productAvailability)) {
            // set the owning side to null (unless already changed)
            if ($productAvailability->getUnit() === $this) {
                $productAvailability->setUnit(null);
            }
        }

        return $this;
    }
}