<?php

namespace App\Entity\Matching;

use App\Entity\Catalog\Category;
use App\Entity\Catalog\Country;
use App\Entity\Catalog\Currency;
use App\Entity\Catalog\Product;
use App\Entity\Catalog\Unit;
use App\Entity\Identity\User;
use App\Enum\NeedType;
use App\Enum\RequestStatus;
use App\Repository\Matching\ClientRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ClientRequestRepository::class)]
#[ORM\Table(name: 'client_requests', schema: 'matching')]
class ClientRequest
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'requests')]
    #[ORM\JoinColumn(nullable: false)]
    private User $client;

    #[ORM\ManyToOne]
    private ?Category $category = null;

    #[ORM\ManyToOne]
    private ?Product $product = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $customProduct = null;

    #[ORM\Column(enumType: NeedType::class)]
    private NeedType $needType;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $quantity = null;

    #[ORM\ManyToOne]
    private ?Unit $unit = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $budgetMin = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $budgetMax = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'currency', referencedColumnName: 'code')]
    private ?Currency $currency = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $desiredDate = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $urgencyLevel = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'country_code', referencedColumnName: 'code')]
    private ?Country $country = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $radiusKm;

    #[ORM\Column]
    private bool $pickupWanted = false;

    #[ORM\Column]
    private bool $deliveryWanted = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(enumType: RequestStatus::class)]
    private RequestStatus $status;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

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

    public function getClient(): User
    {
        return $this->client;
    }

    public function setClient(User $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getCustomProduct(): ?string
    {
        return $this->customProduct;
    }

    public function setCustomProduct(?string $customProduct): static
    {
        $this->customProduct = $customProduct;

        return $this;
    }

    public function getNeedType(): NeedType
    {
        return $this->needType;
    }

    public function setNeedType(NeedType $needType): static
    {
        $this->needType = $needType;

        return $this;
    }

    public function getQuantity(): ?string
    {
        return $this->quantity;
    }

    public function setQuantity(?string $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnit(): ?Unit
    {
        return $this->unit;
    }

    public function setUnit(?Unit $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getBudgetMin(): ?string
    {
        return $this->budgetMin;
    }

    public function setBudgetMin(?string $budgetMin): static
    {
        $this->budgetMin = $budgetMin;

        return $this;
    }

    public function getBudgetMax(): ?string
    {
        return $this->budgetMax;
    }

    public function setBudgetMax(?string $budgetMax): static
    {
        $this->budgetMax = $budgetMax;

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

    public function getDesiredDate(): ?\DateTimeImmutable
    {
        return $this->desiredDate;
    }

    public function setDesiredDate(?\DateTimeImmutable $desiredDate): static
    {
        $this->desiredDate = $desiredDate;

        return $this;
    }

    public function getUrgencyLevel(): int
    {
        return $this->urgencyLevel;
    }

    public function setUrgencyLevel(int $urgencyLevel): static
    {
        $this->urgencyLevel = $urgencyLevel;

        return $this;
    }

    public function getCountry(): ?Country
    {
        return $this->country;
    }

    public function setCountry(?Country $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): static
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function getRadiusKm(): string
    {
        return $this->radiusKm;
    }

    public function setRadiusKm(string $radiusKm): static
    {
        $this->radiusKm = $radiusKm;

        return $this;
    }

    public function isPickupWanted(): bool
    {
        return $this->pickupWanted;
    }

    public function setPickupWanted(bool $pickupWanted): static
    {
        $this->pickupWanted = $pickupWanted;

        return $this;
    }

    public function isDeliveryWanted(): bool
    {
        return $this->deliveryWanted;
    }

    public function setDeliveryWanted(bool $deliveryWanted): static
    {
        $this->deliveryWanted = $deliveryWanted;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getStatus(): RequestStatus
    {
        return $this->status;
    }

    public function setStatus(RequestStatus $status): static
    {
        $this->status = $status;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}