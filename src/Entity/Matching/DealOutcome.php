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

namespace App\Entity\Matching;

use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProfile;
use App\Repository\Matching\DealOutcomeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DealOutcomeRepository::class)]
#[ORM\Table(name: 'deal_outcomes', schema: 'matching')]
class DealOutcome
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'dealOutcomes')]
    #[ORM\JoinColumn(nullable: false)]
    private ClientRequest $request;

    #[ORM\ManyToOne(inversedBy: 'dealOutcomes')]
    #[ORM\JoinColumn(nullable: false)]
    private ProducerProfile $producer;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $outcome = null;

    #[ORM\ManyToOne(inversedBy: 'dealOutcomes')]
    private ?User $declaredBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $declaredAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRequest(): ClientRequest
    {
        return $this->request;
    }

    public function setRequest(ClientRequest $request): static
    {
        $this->request = $request;

        return $this;
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

    public function getOutcome(): ?string
    {
        return $this->outcome;
    }

    public function setOutcome(?string $outcome): static
    {
        $this->outcome = $outcome;

        return $this;
    }

    public function getDeclaredBy(): ?User
    {
        return $this->declaredBy;
    }

    public function setDeclaredBy(?User $declaredBy): static
    {
        $this->declaredBy = $declaredBy;

        return $this;
    }

    public function getDeclaredAt(): ?\DateTimeImmutable
    {
        return $this->declaredAt;
    }

    public function setDeclaredAt(?\DateTimeImmutable $declaredAt): static
    {
        $this->declaredAt = $declaredAt;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }
}
