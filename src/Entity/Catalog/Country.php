<?php

namespace App\Entity\Catalog;

use App\Repository\Catalog\CountryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CountryRepository::class)]
#[ORM\Table(name: 'countries', schema: 'catalog')]
class Country
{
    #[ORM\Id]
    #[ORM\Column(length: 2)]
    private string $code;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $distanceUnit = null;

    #[ORM\Column]
    private bool $isActive = false;

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

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

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getDistanceUnit(): ?string
    {
        return $this->distanceUnit;
    }

    public function setDistanceUnit(?string $distanceUnit): static
    {
        $this->distanceUnit = $distanceUnit;

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
}