<?php

namespace App\Entity\Producer;

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
    #[ORM\JoinColumn(name: 'owner_user_id', referencedColumnName: 'id', nullable: false)]
    private User $owner;

    #[ORM\Column(length: 255)]
    private string $farmName;

    #[ORM\Column(type: 'citext', nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $story = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'country_code', referencedColumnName: 'code', nullable: true)]
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
    private bool $isActive = false;

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

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->producerMedia = new ArrayCollection();
        $this->products = new ArrayCollection();
        $this->labels = new ArrayCollection();
        $this->verificationDocuments = new ArrayCollection();
        $this->teamMembers = new ArrayCollection();
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

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
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
}