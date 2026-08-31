<?php

namespace App\Entity\Matching;

use App\Entity\Catalog\Currency;
use App\Entity\Catalog\Unit;
use App\Entity\Producer\ProducerProfile;
use App\Enum\ReplyStatus;
use App\Repository\Matching\ProducerReplyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProducerReplyRepository::class)]
#[ORM\Table(name: 'producer_replies', schema: 'matching')]
class ProducerReply
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'replies')]
    #[ORM\JoinColumn(nullable: false)]
    private ClientRequest $request;

    #[ORM\ManyToOne(inversedBy: 'replies')]
    #[ORM\JoinColumn(nullable: false)]
    private ProducerProfile $producer;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $replyText = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $priceAmount = null;

    #[ORM\ManyToOne]
    private ?Unit $priceUnit = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'currency', referencedColumnName: 'code')]
    private ?Currency $currency = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $availabilityDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $conditions = null;

    #[ORM\Column(enumType: ReplyStatus::class)]
    private ReplyStatus $status = ReplyStatus::Draft;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getReplyText(): ?string
    {
        return $this->replyText;
    }

    public function setReplyText(?string $replyText): static
    {
        $this->replyText = $replyText;

        return $this;
    }

    public function getPriceAmount(): ?string
    {
        return $this->priceAmount;
    }

    public function setPriceAmount(?string $priceAmount): static
    {
        $this->priceAmount = $priceAmount;

        return $this;
    }

    public function getPriceUnit(): ?Unit
    {
        return $this->priceUnit;
    }

    public function setPriceUnit(?Unit $priceUnit): static
    {
        $this->priceUnit = $priceUnit;

        return $this;
    }

    public function getCurrency(): ?Currency
    {
        return $this->currency;
    }

    public function setCurrency(?Currency $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getAvailabilityDate(): ?\DateTimeImmutable
    {
        return $this->availabilityDate;
    }

    public function setAvailabilityDate(?\DateTimeImmutable $availabilityDate): static
    {
        $this->availabilityDate = $availabilityDate;

        return $this;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function setValidUntil(?\DateTimeImmutable $validUntil): static
    {
        $this->validUntil = $validUntil;

        return $this;
    }

    public function getConditions(): ?string
    {
        return $this->conditions;
    }

    public function setConditions(?string $conditions): static
    {
        $this->conditions = $conditions;

        return $this;
    }

    public function getStatus(): ReplyStatus
    {
        return $this->status;
    }

    public function setStatus(ReplyStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}