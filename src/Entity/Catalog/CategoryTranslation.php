<?php

/**
 * Traduction d'une Category dans une langue donnée (cahier fonctionnel, internationalisation : fr, en, es,
 * it, de). Clé primaire composite (category, locale) -- décision délibérée documentée dans
 * trouvemoi-agri-make-entity-guide.md, verrouillée par EntityPersistenceTest. C'est précisément cette clé
 * composite qui empêche d'utiliser un CollectionField EasyAdmin standard : l'édition passe par un
 * formulaire Symfony fait main (CategoryCrudController::editTranslations()). GET /api/categories ne lit
 * pas encore cette table -- elle renvoie toujours Category::$name (français).
 */

namespace App\Entity\Catalog;

use App\Repository\Catalog\CategoryTranslationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategoryTranslationRepository::class)]
#[ORM\Table(name: 'category_translations', schema: 'catalog')]
class CategoryTranslation
{
    #[ORM\Id]
    #[ORM\ManyToOne(inversedBy: 'categoryTranslations')]
    #[ORM\JoinColumn(nullable: false)]
    private Category $category;

    #[ORM\Id]
    #[ORM\Column(length: 10)]
    private string $locale;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $seoTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $seoDescription = null;

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getSeoTitle(): ?string
    {
        return $this->seoTitle;
    }

    public function setSeoTitle(?string $seoTitle): static
    {
        $this->seoTitle = $seoTitle;

        return $this;
    }

    public function getSeoDescription(): ?string
    {
        return $this->seoDescription;
    }

    public function setSeoDescription(?string $seoDescription): static
    {
        $this->seoDescription = $seoDescription;

        return $this;
    }
}
