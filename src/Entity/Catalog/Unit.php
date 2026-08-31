<?php

namespace App\Entity\Catalog;

use App\Repository\Catalog\UnitRepository;
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

    public function __construct()
    {
        $this->id = Uuid::v4();
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
}