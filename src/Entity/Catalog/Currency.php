<?php

namespace App\Entity\Catalog;

use App\Entity\Matching\ClientRequest;
use App\Entity\Matching\ProducerReply;
use App\Entity\Producer\ProducerProduct;
use App\Repository\Catalog\CurrencyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CurrencyRepository::class)]
#[ORM\Table(name: 'currencies', schema: 'catalog')]
class Currency
{
    #[ORM\Id]
    #[ORM\Column(length: 3)]
    private string $code;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $symbol = null;

    #[ORM\Column(nullable: true)]
    private ?int $decimals = null;

    #[ORM\Column]
    private bool $isActive = false;

    /**
     * @var Collection<int, ProducerProduct>
     */
    #[ORM\OneToMany(targetEntity: ProducerProduct::class, mappedBy: 'currency')]
    private Collection $producerProducts;

    /**
     * @var Collection<int, ClientRequest>
     */
    #[ORM\OneToMany(targetEntity: ClientRequest::class, mappedBy: 'currency')]
    private Collection $clientRequests;

    /**
     * @var Collection<int, ProducerReply>
     */
    #[ORM\OneToMany(targetEntity: ProducerReply::class, mappedBy: 'currency')]
    private Collection $producerReplies;

    public function __construct()
    {
        $this->producerProducts = new ArrayCollection();
        $this->clientRequests = new ArrayCollection();
        $this->producerReplies = new ArrayCollection();
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

    public function getSymbol(): ?string
    {
        return $this->symbol;
    }

    public function setSymbol(?string $symbol): static
    {
        $this->symbol = $symbol;

        return $this;
    }

    public function getDecimals(): ?int
    {
        return $this->decimals;
    }

    public function setDecimals(?int $decimals): static
    {
        $this->decimals = $decimals;

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
     * @return Collection<int, ProducerProduct>
     */
    public function getProducerProducts(): Collection
    {
        return $this->producerProducts;
    }

    public function addProducerProduct(ProducerProduct $producerProduct): static
    {
        if (!$this->producerProducts->contains($producerProduct)) {
            $this->producerProducts->add($producerProduct);
            $producerProduct->setCurrency($this);
        }

        return $this;
    }

    public function removeProducerProduct(ProducerProduct $producerProduct): static
    {
        if ($this->producerProducts->removeElement($producerProduct)) {
            // set the owning side to null (unless already changed)
            if ($producerProduct->getCurrency() === $this) {
                $producerProduct->setCurrency(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ClientRequest>
     */
    public function getClientRequests(): Collection
    {
        return $this->clientRequests;
    }

    public function addClientRequest(ClientRequest $clientRequest): static
    {
        if (!$this->clientRequests->contains($clientRequest)) {
            $this->clientRequests->add($clientRequest);
            $clientRequest->setCurrency($this);
        }

        return $this;
    }

    public function removeClientRequest(ClientRequest $clientRequest): static
    {
        if ($this->clientRequests->removeElement($clientRequest)) {
            // set the owning side to null (unless already changed)
            if ($clientRequest->getCurrency() === $this) {
                $clientRequest->setCurrency(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ProducerReply>
     */
    public function getProducerReplies(): Collection
    {
        return $this->producerReplies;
    }

    public function addProducerReply(ProducerReply $producerReply): static
    {
        if (!$this->producerReplies->contains($producerReply)) {
            $this->producerReplies->add($producerReply);
            $producerReply->setCurrency($this);
        }

        return $this;
    }

    public function removeProducerReply(ProducerReply $producerReply): static
    {
        if ($this->producerReplies->removeElement($producerReply)) {
            // set the owning side to null (unless already changed)
            if ($producerReply->getCurrency() === $this) {
                $producerReply->setCurrency(null);
            }
        }

        return $this;
    }
}