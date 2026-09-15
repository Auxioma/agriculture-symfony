<?php

/**
 * Avis client sur un producteur suite à une ClientRequest précise, posé par
 * ReviewController::createReview() (exige une Conversation existante entre ce client, ce producteur
 * et cette demande -- pas d'avis sans échange réel). La contrainte unique uniq_client_request_producer
 * (client+request+producer) limite un client à un seul avis par demande et par producteur. $status
 * (ReviewStatus) part toujours de Pending ; ReviewCrudController (back-office) le fait passer à
 * Published ou Rejected. $producerResponse permet au producteur de répondre publiquement à l'avis, une
 * fois publié (ReviewController::respondToReview()).
 */

namespace App\Entity\Trust;

use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Producer\ProducerProfile;
use App\Enum\ReviewStatus;
use App\Repository\Trust\ReviewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ReviewRepository::class)]
#[ORM\Table(name: 'reviews', schema: 'trust')]
// * Anticipe le futur écran de modération des avis (aucun contrôleur ne filtre encore par $status
// * aujourd'hui, voir la note plus haut) -- ajouté maintenant par cohérence avec les autres colonnes de
// * statut du projet, pour ne pas avoir à repenser l'indexation le jour où cet écran arrivera.
#[ORM\Index(name: 'idx_reviews_status', columns: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_client_request_producer', columns: ['client_id', 'request_id', 'producer_id'])]
class Review
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'reviews')]
    private ?User $client = null;

    #[ORM\ManyToOne(inversedBy: 'reviews')]
    #[ORM\JoinColumn(nullable: false)]
    private ProducerProfile $producer;

    #[ORM\ManyToOne(inversedBy: 'reviews')]
    #[ORM\JoinColumn(nullable: false)]
    private ClientRequest $request;

    #[ORM\Column(nullable: true)]
    private ?int $rating = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(enumType: ReviewStatus::class)]
    private ReviewStatus $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $producerResponse = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->status = ReviewStatus::Pending;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getClient(): ?User
    {
        return $this->client;
    }

    public function setClient(?User $client): static
    {
        $this->client = $client;

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

    public function getRequest(): ClientRequest
    {
        return $this->request;
    }

    public function setRequest(ClientRequest $request): static
    {
        $this->request = $request;

        return $this;
    }

    public function getRating(): ?int
    {
        return $this->rating;
    }

    public function setRating(?int $rating): static
    {
        $this->rating = $rating;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getStatus(): ReviewStatus
    {
        return $this->status;
    }

    public function setStatus(ReviewStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getProducerResponse(): ?string
    {
        return $this->producerResponse;
    }

    public function setProducerResponse(?string $producerResponse): static
    {
        $this->producerResponse = $producerResponse;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
