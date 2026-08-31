<?php

namespace App\Entity\Identity;

use App\Repository\Identity\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use App\Enum\UserStatus;
use App\Entity\Producer\ProducerProfile;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users', schema: 'identity')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    // un visiteur non authentifié n'a pas de ligne en base.
    public const ROLE_CLIENT = 'ROLE_CLIENT';
    public const ROLE_PRODUCER = 'ROLE_PRODUCER';
    public const ROLE_PRODUCER_TEAM = 'ROLE_PRODUCER_TEAM';
    public const ROLE_SUPPORT = 'ROLE_SUPPORT';
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'citext', unique: true)] //* Equivalent JS ToLowerCase
    private string $email;

    #[ORM\Column(length: 255)]
    private string $password;

    #[ORM\Column(type: 'simple_array', nullable: true)]
    private array $roles = [];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 10)]
    private string $locale = 'fr';

    #[ORM\Column(enumType: UserStatus::class)] // *attribut de type énumération
    private UserStatus $status = UserStatus::Pending; // = En attente de validation // *propriété

    #[ORM\Column(type: 'datetimetz_immutable')] // tz = Time Zone
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /**
     * @var Collection<int, RefreshToken>
     */
    #[ORM\OneToMany(targetEntity: RefreshToken::class, mappedBy: 'idUser', orphanRemoval: true)]
    private Collection $refreshTokens;

    /**
     * @var Collection<int, PasswordResetToken>
     */
    #[ORM\OneToMany(targetEntity: PasswordResetToken::class, mappedBy: 'idUser', orphanRemoval: true)]
    private Collection $passwordResetTokens;

    #[ORM\OneToOne(mappedBy: 'idUser', cascade: ['persist', 'remove'])]
    private ?UserPreference $preference = null;

    #[ORM\OneToOne(mappedBy: 'owner', cascade: ['persist'])]
    private ?ProducerProfile $producerProfile = null;

    /**
     * @var Collection<int, UserAddress>
     */
    #[ORM\OneToMany(targetEntity: UserAddress::class, mappedBy: 'idUser', orphanRemoval: true)]
    private Collection $userAddresses;

    /**
     * @var Collection<int, UserConsent>
     */
    #[ORM\OneToMany(targetEntity: UserConsent::class, mappedBy: 'idUser', orphanRemoval: true)]
    private Collection $userConsents;

    /**
     * @var Collection<int, DataRequest>
     */
    #[ORM\OneToMany(targetEntity: DataRequest::class, mappedBy: 'idUser', orphanRemoval: true)]
    private Collection $dataRequests;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->refreshTokens = new ArrayCollection();
        $this->passwordResetTokens = new ArrayCollection();
        $this->userAddresses = new ArrayCollection();
        $this->userConsents = new ArrayCollection();
        $this->dataRequests = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getRoles(): array
    {
        return array_unique($this->roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;
        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;
        return $this;
    }

    public function getStatus(): UserStatus // *getter + setter
    {
        return $this->status;
    }

    public function setStatus(UserStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function eraseCredentials(): void
    {
        // rien à effacer, pas de champ temporaire en clair
    }

    /**
     * @return Collection<int, RefreshToken>
     */
    public function getRefreshTokens(): Collection
    {
        return $this->refreshTokens;
    }

    public function addRefreshToken(RefreshToken $refreshToken): static
    {
        if (!$this->refreshTokens->contains($refreshToken)) {
            $this->refreshTokens->add($refreshToken);
            $refreshToken->setIdUser($this);
        }

        return $this;
    }

    public function removeRefreshToken(RefreshToken $refreshToken): static
    {
        $this->refreshTokens->removeElement($refreshToken);

        return $this;
    }

    /**
     * @return Collection<int, PasswordResetToken>
     */
    public function getPasswordResetTokens(): Collection
    {
        return $this->passwordResetTokens;
    }

    public function addPasswordResetToken(PasswordResetToken $passwordResetToken): static
    {
        if (!$this->passwordResetTokens->contains($passwordResetToken)) {
            $this->passwordResetTokens->add($passwordResetToken);
            $passwordResetToken->setIdUser($this);
        }

        return $this;
    }

    public function removePasswordResetToken(PasswordResetToken $passwordResetToken): static
    {
        $this->passwordResetTokens->removeElement($passwordResetToken);

        return $this;
    }

    public function getPreference(): ?UserPreference
    {
        return $this->preference;
    }

    public function setPreference(UserPreference $preference): static
    {
        // set the owning side of the relation if necessary
        if ($preference->getIdUser() !== $this) {
            $preference->setIdUser($this);
        }

        $this->preference = $preference;

        return $this;
    }

    /**
     * @return Collection<int, UserAddress>
     */
    public function getUserAddresses(): Collection
    {
        return $this->userAddresses;
    }

    public function addUserAddress(UserAddress $userAddress): static
    {
        if (!$this->userAddresses->contains($userAddress)) {
            $this->userAddresses->add($userAddress);
            $userAddress->setIdUser($this);
        }

        return $this;
    }

    public function removeUserAddress(UserAddress $userAddress): static
    {
        $this->userAddresses->removeElement($userAddress);

        return $this;
    }

    /**
     * @return Collection<int, UserConsent>
     */
    public function getUserConsents(): Collection
    {
        return $this->userConsents;
    }

    public function addUserConsent(UserConsent $userConsent): static
    {
        if (!$this->userConsents->contains($userConsent)) {
            $this->userConsents->add($userConsent);
            $userConsent->setIdUser($this);
        }

        return $this;
    }

    public function removeUserConsent(UserConsent $userConsent): static
    {
        $this->userConsents->removeElement($userConsent);

        return $this;
    }


    /**
     * @return Collection<int, DataRequest>
     */
    public function getDataRequests(): Collection
    {
        return $this->dataRequests;
    }

    public function addDataRequest(DataRequest $dataRequest): static
    {
        if (!$this->dataRequests->contains($dataRequest)) {
            $this->dataRequests->add($dataRequest);
            $dataRequest->setIdUser($this);
        }

        return $this;
    }

    public function removeDataRequest(DataRequest $dataRequest): static
    {
        $this->dataRequests->removeElement($dataRequest);

        return $this;
    }
    
    public function getProducerProfile(): ?ProducerProfile
    {
        return $this->producerProfile;
    }

    public function setProducerProfile(ProducerProfile $producerProfile): static
    {
        if ($producerProfile->getOwner() !== $this) {
            $producerProfile->setOwner($this);
        }
        $this->producerProfile = $producerProfile;

        return $this;
    }
    
}