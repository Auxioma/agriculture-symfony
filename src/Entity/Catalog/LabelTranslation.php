<?php

/**
 * Traduction d'un Label dans une langue donnée -- même principe que CategoryTranslation/ProductTranslation
 * (clé composite label+locale, trouvemoi-agri-make-entity-guide.md). Contrairement aux deux autres, aucun
 * formulaire d'édition n'existe encore dans le back-office pour celle-ci (pas de LabelCrudController) :
 * table présente dans le schéma mais sans écran pour la remplir.
 */

namespace App\Entity\Catalog;

use App\Repository\Catalog\LabelTranslationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LabelTranslationRepository::class)]
#[ORM\Table(name: 'label_translations', schema: 'catalog')]
class LabelTranslation
{
    #[ORM\Id]
    #[ORM\ManyToOne(inversedBy: 'labelTranslations')]
    #[ORM\JoinColumn(nullable: false)]
    private Label $label;

    #[ORM\Id]
    #[ORM\Column(length: 10)]
    private string $locale;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    public function getLabel(): Label
    {
        return $this->label;
    }

    public function setLabel(Label $label): static
    {
        $this->label = $label;

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
}
