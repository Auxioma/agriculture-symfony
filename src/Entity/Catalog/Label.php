<?php

namespace App\Entity\Catalog;

use App\Entity\Producer\ProducerLabel;
use App\Repository\Catalog\LabelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: LabelRepository::class)]
#[ORM\Table(name: 'labels', schema: 'catalog')]
class Label
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'citext')]
    private string $code;

    #[ORM\Column(length: 120)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::SIMPLE_ARRAY, nullable: true)]
    private ?array $countryScope = null;

    #[ORM\Column(nullable: true)]
    private ?bool $requiresDocument = null;

    /**
     * @var Collection<int, LabelTranslation>
     */
    #[ORM\OneToMany(targetEntity: LabelTranslation::class, mappedBy: 'label', orphanRemoval: true)]
    private Collection $labelTranslations;

    /**
     * @var Collection<int, ProducerLabel>
     */
    #[ORM\OneToMany(targetEntity: ProducerLabel::class, mappedBy: 'label', orphanRemoval: true)]
    private Collection $producerLabels;
    
    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->labelTranslations = new ArrayCollection();
        $this->producerLabels = new ArrayCollection();
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

    public function getName(): ?string
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

    public function getCountryScope(): ?array
    {
        return $this->countryScope;
    }

    public function setCountryScope(?array $countryScope): static
    {
        $this->countryScope = $countryScope;

        return $this;
    }

    public function isRequiresDocument(): ?bool
    {
        return $this->requiresDocument;
    }

    public function setRequiresDocument(?bool $requiresDocument): static
    {
        $this->requiresDocument = $requiresDocument;

        return $this;
    }

    /**
     * @return Collection<int, LabelTranslation>
     */
    public function getLabelTranslations(): Collection
    {
        return $this->labelTranslations;
    }

    public function addLabelTranslation(LabelTranslation $labelTranslation): static
    {
        if (!$this->labelTranslations->contains($labelTranslation)) {
            $this->labelTranslations->add($labelTranslation);
            $labelTranslation->setLabel($this);
        }

        return $this;
    }

    public function removeLabelTranslation(LabelTranslation $labelTranslation): static
    {
        $this->labelTranslations->removeElement($labelTranslation);

        return $this;
    }

    /**
     * @return Collection<int, ProducerLabel>
     */
    public function getProducerLabels(): Collection
    {
        return $this->producerLabels;
    }

    public function addProducerLabel(ProducerLabel $producerLabel): static
    {
        if (!$this->producerLabels->contains($producerLabel)) {
            $this->producerLabels->add($producerLabel);
            $producerLabel->setLabel($this);
        }

        return $this;
    }

    public function removeProducerLabel(ProducerLabel $producerLabel): static
    {
        if ($this->producerLabels->removeElement($producerLabel)) {
            // set the owning side to null (unless already changed)
            if ($producerLabel->getLabel() === $this) {
                $producerLabel->setLabel(null);
            }
        }

        return $this;
    }
}
