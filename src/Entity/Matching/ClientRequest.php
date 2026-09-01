<?php

namespace App\Entity\Matching;

use App\Entity\Catalog\Category;
use App\Entity\Catalog\Country;
use App\Entity\Catalog\Currency;
use App\Entity\Catalog\Product;
use App\Entity\Catalog\Unit;
use App\Entity\Identity\User;
use App\Entity\Messaging\Conversation;
use App\Entity\Trust\Review;
use App\Enum\NeedType;
use App\Enum\RequestStatus;
use App\Repository\Matching\ClientRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    #[ORM\ManyToOne(inversedBy: 'clientRequests')]
    private ?Category $category = null;

    #[ORM\ManyToOne(inversedBy: 'clientRequests')]
    private ?Product $product = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $customProduct = null;

    #[ORM\Column(enumType: NeedType::class)]
    private NeedType $needType;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $quantity = null;

    #[ORM\ManyToOne(inversedBy: 'clientRequests')]
    private ?Unit $unit = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $budgetMin = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $budgetMax = null;

    #[ORM\ManyToOne(inversedBy: 'clientRequests')]
    #[ORM\JoinColumn(name: 'currency', referencedColumnName: 'code')]
    private ?Currency $currency = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $desiredDate = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $urgencyLevel = 0;

    #[ORM\ManyToOne(inversedBy: 'clientRequests')]
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

    /**
     * @var Collection<int, RequestAttachment>
     */
    #[ORM\OneToMany(targetEntity: RequestAttachment::class, mappedBy: 'request', orphanRemoval: true)]
    private Collection $attachments;

    /**
     * @var Collection<int, RequestLabel>
     */
    #[ORM\OneToMany(targetEntity: RequestLabel::class, mappedBy: 'request', orphanRemoval: true)]
    private Collection $labels;

    /**
     * @var Collection<int, RequestMatch>
     */
    #[ORM\OneToMany(targetEntity: RequestMatch::class, mappedBy: 'request', orphanRemoval: true)]
    private Collection $matches;

    /**
     * @var Collection<int, ProducerReply>
     */
    #[ORM\OneToMany(targetEntity: ProducerReply::class, mappedBy: 'request', orphanRemoval: true)]
    private Collection $replies;

    /**
     * @var Collection<int, RequestEvent>
     */
    #[ORM\OneToMany(targetEntity: RequestEvent::class, mappedBy: 'request', orphanRemoval: true)]
    private Collection $requestEvents;

    /**
     * @var Collection<int, RecurringRequestRule>
     */
    #[ORM\OneToMany(targetEntity: RecurringRequestRule::class, mappedBy: 'request', orphanRemoval: true)]
    private Collection $recurringRequestRules;

    /**
     * @var Collection<int, DealOutcome>
     */
    #[ORM\OneToMany(targetEntity: DealOutcome::class, mappedBy: 'request', orphanRemoval: true)]
    private Collection $dealOutcomes;

    /**
     * @var Collection<int, Conversation>
     */
    #[ORM\OneToMany(targetEntity: Conversation::class, mappedBy: 'request', orphanRemoval: true)]
    private Collection $conversations;

    /**
     * @var Collection<int, Review>
     */
    #[ORM\OneToMany(targetEntity: Review::class, mappedBy: 'request', orphanRemoval: true)]
    private Collection $reviews;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->radiusKm = '50';
        $this->attachments = new ArrayCollection();
        $this->labels = new ArrayCollection();
        $this->matches = new ArrayCollection();
        $this->replies = new ArrayCollection();
        $this->requestEvents = new ArrayCollection();
        $this->recurringRequestRules = new ArrayCollection();
        $this->dealOutcomes = new ArrayCollection();
        $this->conversations = new ArrayCollection();
        $this->reviews = new ArrayCollection();
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

    public function getAttachments(): Collection
    {
        return $this->attachments;
    }

    public function addAttachment(RequestAttachment $attachment): static
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
            $attachment->setRequest($this);
        }

        return $this;
    }

    public function removeAttachment(RequestAttachment $attachment): static
    {
        $this->attachments->removeElement($attachment);

        return $this;
    }

    public function getLabels(): Collection
    {
        return $this->labels;
    }

    public function addLabel(RequestLabel $label): static
    {
        if (!$this->labels->contains($label)) {
            $this->labels->add($label);
            $label->setRequest($this);
        }

        return $this;
    }

    public function removeLabel(RequestLabel $label): static
    {
        $this->labels->removeElement($label);

        return $this;
    }

    /**
     * @return Collection<int, RequestMatch>
     */
    public function getMatches(): Collection
    {
        return $this->matches;
    }

    public function addRequestMatch(RequestMatch $requestMatch): static
    {
        if (!$this->matches->contains($requestMatch)) {
            $this->matches->add($requestMatch);
            $requestMatch->setRequest($this);
        }

        return $this;
    }

    public function removeRequestMatch(RequestMatch $requestMatch): static
    {
        $this->matches->removeElement($requestMatch);

        return $this;
    }

    /**
     * @return Collection<int, ProducerReply>
     */
    public function getReplies(): Collection
    {
        return $this->replies;
    }

    public function addProducerReply(ProducerReply $producerReply): static
    {
        if (!$this->replies->contains($producerReply)) {
            $this->replies->add($producerReply);
            $producerReply->setRequest($this);
        }

        return $this;
    }

    public function removeProducerReply(ProducerReply $producerReply): static
    {
        $this->replies->removeElement($producerReply);

        return $this;
    }

    /**
     * @return Collection<int, RequestEvent>
     */
    public function getRequestEvents(): Collection
    {
        return $this->requestEvents;
    }

    public function addRequestEvent(RequestEvent $requestEvent): static
    {
        if (!$this->requestEvents->contains($requestEvent)) {
            $this->requestEvents->add($requestEvent);
            $requestEvent->setRequest($this);
        }

        return $this;
    }

    public function removeRequestEvent(RequestEvent $requestEvent): static
    {
        $this->requestEvents->removeElement($requestEvent);

        return $this;
    }

    /**
     * @return Collection<int, RecurringRequestRule>
     */
    public function getRecurringRequestRules(): Collection
    {
        return $this->recurringRequestRules;
    }

    public function addRecurringRequestRule(RecurringRequestRule $recurringRequestRule): static
    {
        if (!$this->recurringRequestRules->contains($recurringRequestRule)) {
            $this->recurringRequestRules->add($recurringRequestRule);
            $recurringRequestRule->setRequest($this);
        }

        return $this;
    }

    public function removeRecurringRequestRule(RecurringRequestRule $recurringRequestRule): static
    {
        $this->recurringRequestRules->removeElement($recurringRequestRule);

        return $this;
    }

    /**
     * @return Collection<int, DealOutcome>
     */
    public function getDealOutcomes(): Collection
    {
        return $this->dealOutcomes;
    }

    public function addDealOutcome(DealOutcome $dealOutcome): static
    {
        if (!$this->dealOutcomes->contains($dealOutcome)) {
            $this->dealOutcomes->add($dealOutcome);
            $dealOutcome->setRequest($this);
        }

        return $this;
    }

    public function removeDealOutcome(DealOutcome $dealOutcome): static
    {
        $this->dealOutcomes->removeElement($dealOutcome);

        return $this;
    }

    /**
     * @return Collection<int, Conversation>
     */
    public function getConversations(): Collection
    {
        return $this->conversations;
    }

    public function addConversation(Conversation $conversation): static
    {
        if (!$this->conversations->contains($conversation)) {
            $this->conversations->add($conversation);
            $conversation->setRequest($this);
        }

        return $this;
    }

    public function removeConversation(Conversation $conversation): static
    {
        if ($this->conversations->removeElement($conversation));

        return $this;
    }

    /**
     * @return Collection<int, Review>
     */
    public function getReviews(): Collection
    {
        return $this->reviews;
    }

    public function addReview(Review $review): static
    {
        if (!$this->reviews->contains($review)) {
            $this->reviews->add($review);
            $review->setRequest($this);
        }

        return $this;
    }

    public function removeReview(Review $review): static
    {
        if ($this->reviews->removeElement($review));

        return $this;
    }
}