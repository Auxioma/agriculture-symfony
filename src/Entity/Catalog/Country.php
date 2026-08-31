<?php

namespace App\Entity\Catalog;

use App\Entity\Producer\ProducerProfile;
use App\Repository\Catalog\CountryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    /**
     * @var Collection<int, ProducerProfile>
     */
    #[ORM\OneToMany(targetEntity: ProducerProfile::class, mappedBy: 'country')]
    private Collection $producerProfiles;

    public function __construct()
    {
        $this->producerProfiles = new ArrayCollection();
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

    /**
     * @return Collection<int, ProducerProfile>
     */
    public function getProducerProfiles(): Collection
    {
        return $this->producerProfiles;
    }

    public function addProducerProfile(ProducerProfile $producerProfile): static
    {
        if (!$this->producerProfiles->contains($producerProfile)) {
            $this->producerProfiles->add($producerProfile);
            $producerProfile->setCountry($this);
        }

        return $this;
    }

    public function removeProducerProfile(ProducerProfile $producerProfile): static
    {
        if ($this->producerProfiles->removeElement($producerProfile)) {
            // set the owning side to null (unless already changed)
            if ($producerProfile->getCountry() === $this) {
                $producerProfile->setCountry(null);
            }
        }

        return $this;
    }
}