<?php

/**
 * Association producteur <-> label (ex. "Bio", "AOP"), avec preuve à l'appui. Clé composite
 * (producer, label) -- l'une des 10 entités à clé composite intentionnelle du projet, voir
 * trouvemoi-agri-make-entity-guide.md. $document pointe vers le VerificationDocument justificatif
 * (facultatif tant que non fourni) ; $verifiedAt/$expiresAt portent la validation admin et sa durée de
 * validité (un label type "Bio" se renouvelle, d'où l'expiration).
 */

namespace App\Entity\Producer;

use App\Entity\Catalog\Label;
use App\Entity\Trust\VerificationDocument;
use App\Repository\Producer\ProducerLabelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProducerLabelRepository::class)]
#[ORM\Table(name: 'producer_labels', schema: 'producer')]
class ProducerLabel
{
    #[ORM\Id]
    #[ORM\ManyToOne(inversedBy: 'labels')]
    #[ORM\JoinColumn(nullable: false)]
    private ProducerProfile $producer;

    #[ORM\Id]
    #[ORM\ManyToOne(inversedBy: 'producerLabels')]
    #[ORM\JoinColumn(nullable: false)]
    private Label $label;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\ManyToOne]
    private ?VerificationDocument $document = null;

    public function getProducer(): ProducerProfile
    {
        return $this->producer;
    }

    public function setProducer(ProducerProfile $producer): static
    {
        $this->producer = $producer;

        return $this;
    }

    public function getLabel(): Label
    {
        return $this->label;
    }

    public function setLabel(Label $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeImmutable $verifiedAt): static
    {
        $this->verifiedAt = $verifiedAt;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getDocument(): ?VerificationDocument
    {
        return $this->document;
    }

    public function setDocument(?VerificationDocument $document): static
    {
        $this->document = $document;

        return $this;
    }
}
