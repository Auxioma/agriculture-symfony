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

namespace App\Entity\Identity;

use App\Entity\Audit\AuditLog;
use App\Entity\Engagement\Favorite;
use App\Entity\Engagement\Referral;
use App\Entity\Engagement\SavedSearch;
use App\Entity\Matching\ClientRequest;
use App\Entity\Matching\DealOutcome;
use App\Entity\Matching\RequestAttachment;
use App\Entity\Matching\RequestEvent;
use App\Entity\Messaging\BlockedUser;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\ConversationParticipant;
use App\Entity\Messaging\Message;
use App\Entity\Messaging\MessageRead;
use App\Entity\Messaging\UserPresence;
use App\Entity\Notification\Notification;
use App\Entity\Producer\ProducerProfile;
use App\Entity\Producer\TeamMember;
use App\Entity\Support\Ticket;
use App\Entity\Support\TicketMessage;
use App\Entity\Trust\ModerationAction;
use App\Entity\Trust\Report;
use App\Entity\Trust\Review;
use App\Entity\Trust\UserSanction;
use App\Enum\UserStatus;
use App\Repository\Identity\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

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

    #[ORM\Column(type: 'citext', unique: true)] // * Equivalent JS ToLowerCase
    private string $email;

    #[ORM\Column(type: Types::TEXT)]
    private string $passwordHash;

    #[ORM\Column(type: 'simple_array')]
    private array $roles;

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

    /**
     * @var Collection<int, TeamMember>
     */
    #[ORM\OneToMany(targetEntity: TeamMember::class, mappedBy: 'idUser', orphanRemoval: true)]
    private Collection $teamMemberships;

    /**
     * @var Collection<int, ClientRequest>
     */
    #[ORM\OneToMany(targetEntity: ClientRequest::class, mappedBy: 'client', orphanRemoval: true)]
    private Collection $requests;

    /**
     * @var Collection<int, RequestAttachment>
     */
    #[ORM\OneToMany(targetEntity: RequestAttachment::class, mappedBy: 'uploadedBy')]
    private Collection $requestAttachments;

    /**
     * @var Collection<int, RequestEvent>
     */
    #[ORM\OneToMany(targetEntity: RequestEvent::class, mappedBy: 'actor')]
    private Collection $requestEvents;

    /**
     * @var Collection<int, DealOutcome>
     */
    #[ORM\OneToMany(targetEntity: DealOutcome::class, mappedBy: 'declaredBy')]
    private Collection $dealOutcomes;

    /**
     * @var Collection<int, Conversation>
     */
    #[ORM\OneToMany(targetEntity: Conversation::class, mappedBy: 'client')]
    private Collection $conversations;

    /**
     * @var Collection<int, ConversationParticipant>
     */
    #[ORM\OneToMany(targetEntity: ConversationParticipant::class, mappedBy: 'idUser', orphanRemoval: true)]
    private Collection $conversationParticipants;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'sender')]
    private Collection $messages;

    /**
     * @var Collection<int, MessageRead>
     */
    #[ORM\OneToMany(targetEntity: MessageRead::class, mappedBy: 'idUser', orphanRemoval: true)]
    private Collection $messageReads;

    #[ORM\OneToOne(mappedBy: 'idUser', cascade: ['persist', 'remove'])]
    private ?UserPresence $presence = null;

    /**
     * @var Collection<int, BlockedUser>
     */
    #[ORM\OneToMany(targetEntity: BlockedUser::class, mappedBy: 'blocker', orphanRemoval: true)]
    private Collection $blockedUsers;

    /**
     * @var Collection<int, Review>
     */
    #[ORM\OneToMany(targetEntity: Review::class, mappedBy: 'client', orphanRemoval: true)]
    private Collection $reviews;

    /**
     * @var Collection<int, Report>
     */
    #[ORM\OneToMany(targetEntity: Report::class, mappedBy: 'reporter')]
    private Collection $reports;

    /**
     * @var Collection<int, ModerationAction>
     */
    #[ORM\OneToMany(targetEntity: ModerationAction::class, mappedBy: 'admin')]
    private Collection $moderationActions;

    /**
     * @var Collection<int, UserSanction>
     */
    #[ORM\OneToMany(targetEntity: UserSanction::class, mappedBy: 'idUser', orphanRemoval: true)]
    private Collection $userSanctions;

    /**
     * @var Collection<int, Ticket>
     */
    #[ORM\OneToMany(targetEntity: Ticket::class, mappedBy: 'idUser', orphanRemoval: true)]
    private Collection $tickets;

    /**
     * @var Collection<int, TicketMessage>
     */
    #[ORM\OneToMany(targetEntity: TicketMessage::class, mappedBy: 'sender')]
    private Collection $ticketMessages;

    /**
     * @var Collection<int, Favorite>
     */
    #[ORM\OneToMany(targetEntity: Favorite::class, mappedBy: 'idUser', orphanRemoval: true)]
    private Collection $favorites;

    /**
     * @var Collection<int, SavedSearch>
     */
    #[ORM\OneToMany(targetEntity: SavedSearch::class, mappedBy: 'idUser', orphanRemoval: true)]
    private Collection $savedSearches;

    /**
     * @var Collection<int, Referral>
     */
    #[ORM\OneToMany(targetEntity: Referral::class, mappedBy: 'referrer')]
    private Collection $referrals;

    /**
     * @var Collection<int, Notification>
     */
    #[ORM\OneToMany(targetEntity: Notification::class, mappedBy: 'idUser', orphanRemoval: true)]
    private Collection $notifications;

    /**
     * @var Collection<int, AuditLog>
     */
    #[ORM\OneToMany(targetEntity: AuditLog::class, mappedBy: 'actor')]
    private Collection $auditLogs;

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
        $this->teamMemberships = new ArrayCollection();
        $this->requests = new ArrayCollection();
        $this->roles = ['ROLE_CLIENT'];
        $this->requestAttachments = new ArrayCollection();
        $this->requestEvents = new ArrayCollection();
        $this->dealOutcomes = new ArrayCollection();
        $this->conversations = new ArrayCollection();
        $this->conversationParticipants = new ArrayCollection();
        $this->messages = new ArrayCollection();
        $this->messageReads = new ArrayCollection();
        $this->blockedUsers = new ArrayCollection();
        $this->reviews = new ArrayCollection();
        $this->reports = new ArrayCollection();
        $this->moderationActions = new ArrayCollection();
        $this->userSanctions = new ArrayCollection();
        $this->tickets = new ArrayCollection();
        $this->ticketMessages = new ArrayCollection();
        $this->favorites = new ArrayCollection();
        $this->savedSearches = new ArrayCollection();
        $this->referrals = new ArrayCollection();
        $this->notifications = new ArrayCollection();
        $this->auditLogs = new ArrayCollection();
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

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): static
    {
        $this->passwordHash = $passwordHash;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
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
        $producerProfile->setOwner($this);
        $this->producerProfile = $producerProfile;

        return $this;
    }

    public function getTeamMemberships(): Collection
    {
        return $this->teamMemberships;
    }

    public function addTeamMembership(TeamMember $teamMembership): static
    {
        if (!$this->teamMemberships->contains($teamMembership)) {
            $this->teamMemberships->add($teamMembership);
            $teamMembership->setIdUser($this);
        }

        return $this;
    }

    public function removeTeamMembership(TeamMember $teamMembership): static
    {
        $this->teamMemberships->removeElement($teamMembership);

        return $this;
    }

    /**
     * @return Collection<int, ClientRequest>
     */
    public function getRequests(): Collection
    {
        return $this->requests;
    }

    public function addRequest(ClientRequest $request): static
    {
        if (!$this->requests->contains($request)) {
            $this->requests->add($request);
            $request->setClient($this);
        }

        return $this;
    }

    public function removeRequest(ClientRequest $request): static
    {
        $this->requests->removeElement($request);

        return $this;
    }

    /**
     * @return Collection<int, RequestAttachment>
     */
    public function getRequestAttachments(): Collection
    {
        return $this->requestAttachments;
    }

    public function addRequestAttachment(RequestAttachment $requestAttachment): static
    {
        if (!$this->requestAttachments->contains($requestAttachment)) {
            $this->requestAttachments->add($requestAttachment);
            $requestAttachment->setUploadedBy($this);
        }

        return $this;
    }

    public function removeRequestAttachment(RequestAttachment $requestAttachment): static
    {
        if ($this->requestAttachments->removeElement($requestAttachment)) {
            // set the owning side to null (unless already changed)
            if ($requestAttachment->getUploadedBy() === $this) {
                $requestAttachment->setUploadedBy(null);
            }
        }

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
            $requestEvent->setActor($this);
        }

        return $this;
    }

    public function removeRequestEvent(RequestEvent $requestEvent): static
    {
        if ($this->requestEvents->removeElement($requestEvent)) {
            // set the owning side to null (unless already changed)
            if ($requestEvent->getActor() === $this) {
                $requestEvent->setActor(null);
            }
        }

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
            $dealOutcome->setDeclaredBy($this);
        }

        return $this;
    }

    public function removeDealOutcome(DealOutcome $dealOutcome): static
    {
        if ($this->dealOutcomes->removeElement($dealOutcome)) {
            // set the owning side to null (unless already changed)
            if ($dealOutcome->getDeclaredBy() === $this) {
                $dealOutcome->setDeclaredBy(null);
            }
        }

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
            $conversation->setClient($this);
        }

        return $this;
    }

    public function removeConversation(Conversation $conversation): static
    {
        if ($this->conversations->removeElement($conversation)) {
            // set the owning side to null (unless already changed)
            if ($conversation->getClient() === $this) {
                $conversation->setClient(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ConversationParticipant>
     */
    public function getConversationParticipants(): Collection
    {
        return $this->conversationParticipants;
    }

    public function addConversationParticipant(ConversationParticipant $conversationParticipant): static
    {
        if (!$this->conversationParticipants->contains($conversationParticipant)) {
            $this->conversationParticipants->add($conversationParticipant);
            $conversationParticipant->setIdUser($this);
        }

        return $this;
    }

    public function removeConversationParticipant(ConversationParticipant $conversationParticipant): static
    {
        if ($this->conversationParticipants->removeElement($conversationParticipant));

        return $this;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getmessages(): Collection
    {
        return $this->messages;
    }

    public function addmessages(Message $messages): static
    {
        if (!$this->messages->contains($messages)) {
            $this->messages->add($messages);
            $messages->setSender($this);
        }

        return $this;
    }

    public function removemessages(Message $messages): static
    {
        if ($this->messages->removeElement($messages)) {
            // set the owning side to null (unless already changed)
            if ($messages->getSender() === $this) {
                $messages->setSender(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, MessageRead>
     */
    public function getMessageReads(): Collection
    {
        return $this->messageReads;
    }

    public function addMessageRead(MessageRead $messageRead): static
    {
        if (!$this->messageReads->contains($messageRead)) {
            $this->messageReads->add($messageRead);
            $messageRead->setIdUser($this);
        }

        return $this;
    }

    public function removeMessageRead(MessageRead $messageRead): static
    {
        if ($this->messageReads->removeElement($messageRead));

        return $this;
    }

    public function getPresence(): ?UserPresence
    {
        return $this->presence;
    }

    public function setPresence(UserPresence $presence): static
    {
        // set the owning side of the relation if necessary
        if ($presence->getIdUser() !== $this) {
            $presence->setIdUser($this);
        }

        $this->presence = $presence;

        return $this;
    }

    /**
     * @return Collection<int, BlockedUser>
     */
    public function getBlockedUsers(): Collection
    {
        return $this->blockedUsers;
    }

    public function addBlockedUser(BlockedUser $blockedUser): static
    {
        if (!$this->blockedUsers->contains($blockedUser)) {
            $this->blockedUsers->add($blockedUser);
            $blockedUser->setBlocker($this);
        }

        return $this;
    }

    public function removeBlockedUser(BlockedUser $blockedUser): static
    {
        if ($this->blockedUsers->removeElement($blockedUser)) {
            // set the owning side to null (unless already changed)
            if ($blockedUser->getBlocker() === $this) {
                $blockedUser->setBlocker(null);
            }
        }

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
            $review->setClient($this);
        }

        return $this;
    }

    public function removeReview(Review $review): static
    {
        if ($this->reviews->removeElement($review)) {
            // set the owning side to null (unless already changed)
            if ($review->getClient() === $this) {
                $review->setClient(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Report>
     */
    public function getReports(): Collection
    {
        return $this->reports;
    }

    public function addReport(Report $report): static
    {
        if (!$this->reports->contains($report)) {
            $this->reports->add($report);
            $report->setReporter($this);
        }

        return $this;
    }

    public function removeReport(Report $report): static
    {
        if ($this->reports->removeElement($report)) {
            // set the owning side to null (unless already changed)
            if ($report->getReporter() === $this) {
                $report->setReporter(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, ModerationAction>
     */
    public function getModerationActions(): Collection
    {
        return $this->moderationActions;
    }

    public function addModerationAction(ModerationAction $moderationAction): static
    {
        if (!$this->moderationActions->contains($moderationAction)) {
            $this->moderationActions->add($moderationAction);
            $moderationAction->setAdmin($this);
        }

        return $this;
    }

    public function removeModerationAction(ModerationAction $moderationAction): static
    {
        if ($this->moderationActions->removeElement($moderationAction)) {
            // set the owning side to null (unless already changed)
            if ($moderationAction->getAdmin() === $this) {
                $moderationAction->setAdmin(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, UserSanction>
     */
    public function getUserSanctions(): Collection
    {
        return $this->userSanctions;
    }

    public function addUserSanction(UserSanction $userSanction): static
    {
        if (!$this->userSanctions->contains($userSanction)) {
            $this->userSanctions->add($userSanction);
            $userSanction->setIdUser($this);
        }

        return $this;
    }

    public function removeUserSanction(UserSanction $userSanction): static
    {
        if ($this->userSanctions->removeElement($userSanction));

        return $this;
    }

    /**
     * @return Collection<int, Ticket>
     */
    public function getTickets(): Collection
    {
        return $this->tickets;
    }

    public function addTicket(Ticket $ticket): static
    {
        if (!$this->tickets->contains($ticket)) {
            $this->tickets->add($ticket);
            $ticket->setIdUser($this);
        }

        return $this;
    }

    public function removeTicket(Ticket $ticket): static
    {
        $this->tickets->removeElement($ticket);

        return $this;
    }

    /**
     * @return Collection<int, TicketMessage>
     */
    public function getTicketMessages(): Collection
    {
        return $this->ticketMessages;
    }

    public function addTicketMessage(TicketMessage $ticketMessage): static
    {
        if (!$this->ticketMessages->contains($ticketMessage)) {
            $this->ticketMessages->add($ticketMessage);
            $ticketMessage->setSender($this);
        }

        return $this;
    }

    public function removeTicketMessage(TicketMessage $ticketMessage): static
    {
        if ($this->ticketMessages->removeElement($ticketMessage)) {
            // set the owning side to null (unless already changed)
            if ($ticketMessage->getSender() === $this) {
                $ticketMessage->setSender(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Favorite>
     */
    public function getFavorites(): Collection
    {
        return $this->favorites;
    }

    public function addFavorite(Favorite $favorite): static
    {
        if (!$this->favorites->contains($favorite)) {
            $this->favorites->add($favorite);
            $favorite->setIdUser($this);
        }

        return $this;
    }

    public function removeFavorite(Favorite $favorite): static
    {
        $this->favorites->removeElement($favorite);

        return $this;
    }

    /**
     * @return Collection<int, SavedSearch>
     */
    public function getSavedSearches(): Collection
    {
        return $this->savedSearches;
    }

    public function addSavedSearch(SavedSearch $savedSearch): static
    {
        if (!$this->savedSearches->contains($savedSearch)) {
            $this->savedSearches->add($savedSearch);
            $savedSearch->setIdUser($this);
        }

        return $this;
    }

    public function removeSavedSearch(SavedSearch $savedSearch): static
    {
        $this->savedSearches->removeElement($savedSearch);

        return $this;
    }

    /**
     * @return Collection<int, Referral>
     */
    public function getReferrals(): Collection
    {
        return $this->referrals;
    }

    public function addReferral(Referral $referral): static
    {
        if (!$this->referrals->contains($referral)) {
            $this->referrals->add($referral);
            $referral->setReferrer($this);
        }

        return $this;
    }

    public function removeReferral(Referral $referral): static
    {
        if ($this->referrals->removeElement($referral)) {
            // set the owning side to null (unless already changed)
            if ($referral->getReferrer() === $this) {
                $referral->setReferrer(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Notification>
     */
    public function getNotifications(): Collection
    {
        return $this->notifications;
    }

    public function addNotification(Notification $notification): static
    {
        if (!$this->notifications->contains($notification)) {
            $this->notifications->add($notification);
            $notification->setIdUser($this);
        }

        return $this;
    }

    public function removeNotification(Notification $notification): static
    {
        $this->notifications->removeElement($notification);

        return $this;
    }

    /**
     * @return Collection<int, AuditLog>
     */
    public function getAuditLogs(): Collection
    {
        return $this->auditLogs;
    }

    public function addAuditLog(AuditLog $auditLog): static
    {
        if (!$this->auditLogs->contains($auditLog)) {
            $this->auditLogs->add($auditLog);
            $auditLog->setActor($this);
        }

        return $this;
    }

    public function removeAuditLog(AuditLog $auditLog): static
    {
        if ($this->auditLogs->removeElement($auditLog)) {
            // set the owning side to null (unless already changed)
            if ($auditLog->getActor() === $this) {
                $auditLog->setActor(null);
            }
        }

        return $this;
    }
}
