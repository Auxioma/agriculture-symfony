<?php

namespace App\Entity\Catalog;

use App\Repository\Catalog\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products', schema: 'catalog')]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: false)]
    private Category $category;

    #[ORM\Column(type: 'citext', nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\ManyToOne]
    private ?Unit $defaultUnit = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $seasonStartMonth = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $seasonEndMonth = null;

    #[ORM\Column]
    private bool $isActive = false;

    /**
     * @var Collection<int, ProductTranslation>
     */
    #[ORM\OneToMany(targetEntity: ProductTranslation::class, mappedBy: 'product', orphanRemoval: true)]
    private Collection $productTranslations;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->productTranslations = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDefaultUnit(): ?Unit
    {
        return $this->defaultUnit;
    }

    public function setDefaultUnit(?Unit $defaultUnit): static
    {
        $this->defaultUnit = $defaultUnit;

        return $this;
    }

    public function getSeasonStartMonth(): ?int
    {
        return $this->seasonStartMonth;
    }

    public function setSeasonStartMonth(?int $seasonStartMonth): static
    {
        $this->seasonStartMonth = $seasonStartMonth;

        return $this;
    }

    public function getSeasonEndMonth(): ?int
    {
        return $this->seasonEndMonth;
    }

    public function setSeasonEndMonth(?int $seasonEndMonth): static
    {
        $this->seasonEndMonth = $seasonEndMonth;

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

    /**
     * @return Collection<int, ProductTranslation>
     */
    public function getProductTranslations(): Collection
    {
        return $this->productTranslations;
    }

    public function addProductTranslation(ProductTranslation $productTranslation): static
    {
        if (!$this->productTranslations->contains($productTranslation)) {
            $this->productTranslations->add($productTranslation);
            $productTranslation->setProduct($this);
        }

        return $this;
    }

    public function removeProductTranslation(ProductTranslation $productTranslation): static
    {
        $this->productTranslations->removeElement($productTranslation);

        return $this;
    }
}