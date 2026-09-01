<?php

namespace App\Entity\Producer;

use App\Entity\Billing\CouponRedemption;
use App\Entity\Billing\Subscription;
use App\Entity\Matching\DealOutcome;
use App\Entity\Matching\ProducerReply;
use App\Entity\Matching\RequestMatch;
use App\Entity\Messaging\Conversation;
use App\Entity\Trust\VerificationDocument;
use App\Entity\Catalog\Country;
use App\Entity\Identity\User;
use App\Enum\VerificationStatus;
use App\Repository\Producer\ProducerProfileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProducerProfileRepository::class)]
#[ORM\Table(name: 'producer_profiles', schema: 'producer')]
class ProducerProfile
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(inversedBy: 'producerProfile', cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'owner_user_id', referencedColumnName: 'id', nullable: false, unique: true)]
    private User $owner;

    #[ORM\Column(length: 255)]
    private string $farmName;

    #[ORM\Column(type: 'citext', unique: true)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $story = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, name: 'country_code', referencedColumnName: 'code')]
    private ?Country $country = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $addressVisibility = null;

    #[ORM\Column(enumType: VerificationStatus::class)]
    private VerificationStatus $verificationStatus = VerificationStatus::Pending;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\OneToOne(mappedBy: 'producer', cascade: ['persist'])]
    private ?ProducerSetting $settings = null;

    /**
     * @var Collection<int, ProducerMedia>
     */
    #[ORM\OneToMany(targetEntity: ProducerMedia::class, mappedBy: 'producer', orphanRemoval: true)]
    private Collection $producerMedia;

    /**
     * @var Collection<int, ProducerProduct>
     */
    #[ORM\OneToMany(targetEntity: ProducerProduct::class, mappedBy: 'producer', orphanRemoval: true)]
    private Collection $products;

    /**
     * @var Collection<int, ProducerLabel>
     */
    #[ORM\OneToMany(targetEntity: ProducerLabel::class, mappedBy: 'producer', orphanRemoval: true)]
    private Collection $labels;

    /**
     * @var Collection<int, VerificationDocument>
     */
    #[ORM\OneToMany(targetEntity: VerificationDocument::class, mappedBy: 'producer', orphanRemoval: true)]
    private Collection $verificationDocuments;

    /**
     * @var Collection<int, TeamMember>
     */
    #[ORM\OneToMany(targetEntity: TeamMember::class, mappedBy: 'producer', orphanRemoval: true)]
    private Collection $teamMembers;

    /**
     * @var Collection<int, DeliveryZone>
     */
    #[ORM\OneToMany(targetEntity: DeliveryZone::class, mappedBy: 'producer', orphanRemoval: true)]
    private Collection $deliveryZones;

    /**
     * @var Collection<int, OpeningHour>
     */
    #[ORM\OneToMany(targetEntity: OpeningHour::class, mappedBy: 'producer', orphanRemoval: true)]
    private Collection $openingHours;

    /**
     * @var Collection<int, QuickReply>
     */
    #[ORM\OneToMany(targetEntity: QuickReply::class, mappedBy: 'producer', orphanRemoval: true)]
    private Collection $quickReplies;

    /**
     * @var Collection<int, RequestMatch>
     */
    #[ORM\OneToMany(targetEntity: RequestMatch::class, mappedBy: 'producer', orphanRemoval: true)]
    private Collection $matches;

    /**
     * @var Collection<int, ProducerReply>
     */
    #[ORM\OneToMany(targetEntity: ProducerReply::class, mappedBy: 'producer', orphanRemoval: true)]
    private Collection $replies;

    /**
     * @var Collection<int, DealOutcome>
     */
    #[ORM\OneToMany(targetEntity: DealOutcome::class, mappedBy: 'producer', orphanRemoval: true)]
    private Collection $dealOutcomes;

    /**
     * @var Collection<int, Conversation>
     */
    #[ORM\OneToMany(targetEntity: Conversation::class, mappedBy: 'producer', orphanRemoval: true)]
    private Collection $conversations;

    /**
     * @var Collection<int, Subscription>
     */
    #[ORM\OneToMany(targetEntity: Subscription::class, mappedBy: 'producer', orphanRemoval: true)]
    private Collection $subscriptions;

    /**
     * @var Collection<int, CouponRedemption>
     */
    #[ORM\OneToMany(targetEntity: CouponRedemption::class, mappedBy: 'producer', orphanRemoval: true)]
    private Collection $couponRedemptions;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->producerMedia = new ArrayCollection();
        $this->products = new ArrayCollection();
        $this->labels = new ArrayCollection();
        $this->verificationDocuments = new ArrayCollection();
        $this->teamMembers = new ArrayCollection();
        $this->deliveryZones = new ArrayCollection();
        $this->openingHours = new ArrayCollection();
        $this->quickReplies = new ArrayCollection();
        $this->matches = new ArrayCollection();
        $this->replies = new ArrayCollection();
        $this->dealOutcomes = new ArrayCollection();
        $this->conversations = new ArrayCollection();
        $this->subscriptions = new ArrayCollection();
        $this->couponRedemptions = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getFarmName(): string
    {
        return $this->farmName;
    }

    public function setFarmName(string $farmName): static
    {
        $this->farmName = $farmName;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStory(): ?string
    {
        return $this->story;
    }

    public function setStory(?string $story): static
    {
        $this->story = $story;

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

    public function getAddressVisibility(): ?string
    {
        return $this->addressVisibility;
    }

    public function setAddressVisibility(?string $addressVisibility): static
    {
        $this->addressVisibility = $addressVisibility;

        return $this;
    }

    public function getVerificationStatus(): VerificationStatus
    {
        return $this->verificationStatus;
    }

    public function setVerificationStatus(VerificationStatus $verificationStatus): static
    {
        $this->verificationStatus = $verificationStatus;

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
     * @return Collection<int, ProducerMedia>
     */
    public function getProducerMedia(): Collection
    {
        return $this->producerMedia;
    }

    public function addProducerMedium(ProducerMedia $producerMedium): static
    {
        if (!$this->producerMedia->contains($producerMedium)) {
            $this->producerMedia->add($producerMedium);
            $producerMedium->setProducer($this);
        }

        return $this;
    }

    public function removeProducerMedium(ProducerMedia $producerMedium): static
    {
        $this->producerMedia->removeElement($producerMedium);

        return $this;
    }

    /**
     * @return Collection<int, ProducerProduct>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(ProducerProduct $product): static
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->setProducer($this);
        }

        return $this;
    }

    public function removeProduct(ProducerProduct $product): static
    {
        $this->products->removeElement($product);

        return $this;
    }

    public function getLabels(): Collection
    {
        return $this->labels;
    }

    public function addLabel(ProducerLabel $label): static
    {
        if (!$this->labels->contains($label)) {
            $this->labels->add($label);
            $label->setProducer($this);
        }

        return $this;
    }

    public function removeLabel(ProducerLabel $label): static
    {
        $this->labels->removeElement($label);

        return $this;
    }

    public function getVerificationDocuments(): Collection
    {
        return $this->verificationDocuments;
    }

    public function addVerificationDocument(VerificationDocument $verificationDocument): static
    {
        if (!$this->verificationDocuments->contains($verificationDocument)) {
            $this->verificationDocuments->add($verificationDocument);
            $verificationDocument->setProducer($this);
        }

        return $this;
    }

    public function removeVerificationDocument(VerificationDocument $verificationDocument): static
    {
        $this->verificationDocuments->removeElement($verificationDocument);

        return $this;
    }

    public function getTeamMembers(): Collection
    {
        return $this->teamMembers;
    }

    public function addTeamMember(TeamMember $teamMember): static
    {
        if (!$this->teamMembers->contains($teamMember)) {
            $this->teamMembers->add($teamMember);
            $teamMember->setProducer($this);
        }

        return $this;
    }

    public function removeTeamMember(TeamMember $teamMember): static
    {
        $this->teamMembers->removeElement($teamMember);

        return $this;
    }

    public function getDeliveryZones(): Collection
    {
        return $this->deliveryZones;
    }

    public function addDeliveryZone(DeliveryZone $deliveryZone): static
    {
        if (!$this->deliveryZones->contains($deliveryZone)) {
            $this->deliveryZones->add($deliveryZone);
            $deliveryZone->setProducer($this);
        }

        return $this;
    }

    public function removeDeliveryZone(DeliveryZone $deliveryZone): static
    {
        $this->deliveryZones->removeElement($deliveryZone);

        return $this;
    }

    public function getOpeningHours(): Collection
    {
        return $this->openingHours;
    }

    public function addOpeningHour(OpeningHour $openingHour): static
    {
        if (!$this->openingHours->contains($openingHour)) {
            $this->openingHours->add($openingHour);
            $openingHour->setProducer($this);
        }

        return $this;
    }

    public function removeOpeningHour(OpeningHour $openingHour): static
    {
        $this->openingHours->removeElement($openingHour);

        return $this;
    }

    public function getSettings(): ?ProducerSetting
    {
        return $this->settings;
    }

    public function setSettings(ProducerSetting $settings): static
    {
        if ($settings->getProducer() !== $this) {
            $settings->setProducer($this);
        }
        $this->settings = $settings;

        return $this;
    }

    public function getQuickReplies(): Collection
    {
        return $this->quickReplies;
    }

    public function addQuickReply(QuickReply $quickReply): static
    {
        if (!$this->quickReplies->contains($quickReply)) {
            $this->quickReplies->add($quickReply);
            $quickReply->setProducer($this);
        }

        return $this;
    }

    public function removeQuickReply(QuickReply $quickReply): static
    {
        $this->quickReplies->removeElement($quickReply);

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
            $requestMatch->setProducer($this);
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
            $producerReply->setProducer($this);
        }

        return $this;
    }

    public function removeProducerReply(ProducerReply $producerReply): static
    {
        $this->replies->removeElement($producerReply);

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
            $dealOutcome->setProducer($this);
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
            $conversation->setProducer($this);
        }

        return $this;
    }

    public function removeConversation(Conversation $conversation): static
    {
        if ($this->conversations->removeElement($conversation));

        return $this;
    }

    /**
     * @return Collection<int, Subscription>
     */
    public function getSubscriptions(): Collection
    {
        return $this->subscriptions;
    }

    public function addSubscription(Subscription $subscription): static
    {
        if (!$this->subscriptions->contains($subscription)) {
            $this->subscriptions->add($subscription);
            $subscription->setProducer($this);
        }

        return $this;
    }

    public function removeSubscription(Subscription $subscription): static
    {
        if ($this->subscriptions->removeElement($subscription));

        return $this;
    }

    /**
     * @return Collection<int, CouponRedemption>
     */
    public function getCouponRedemptions(): Collection
    {
        return $this->couponRedemptions;
    }

    public function addCouponRedemption(CouponRedemption $couponRedemption): static
    {
        if (!$this->couponRedemptions->contains($couponRedemption)) {
            $this->couponRedemptions->add($couponRedemption);
            $couponRedemption->setProducer($this);
        }

        return $this;
    }

    public function removeCouponRedemption(CouponRedemption $couponRedemption): static
    {
        if ($this->couponRedemptions->removeElement($couponRedemption)) {
            // set the owning side to null (unless already changed)
            if ($couponRedemption->getProducer() === $this) {
                $couponRedemption->setProducer(null);
            }
        }

        return $this;
    }
}