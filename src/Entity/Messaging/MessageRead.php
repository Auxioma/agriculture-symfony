<?php

/**
 * Accusé de lecture d'un Message par un utilisateur précis. Clé composite (message, idUser) -- l'une des
 * 10 entités à clé composite intentionnelle du projet, voir trouvemoi-agri-make-entity-guide.md. Aucune
 * route n'écrit encore de ligne ici -- prévu pour un futur indicateur "lu/non lu" par message (plus
 * précis que $lastSeenAt sur ConversationParticipant, qui ne marque que la dernière consultation globale).
 */

namespace App\Entity\Messaging;

use App\Entity\Identity\User;
use App\Repository\Messaging\MessageReadRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MessageReadRepository::class)]
#[ORM\Table(name: 'message_reads', schema: 'messaging')]
class MessageRead
{
    #[ORM\Id]
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Message $message;

    #[ORM\Id]
    #[ORM\ManyToOne(inversedBy: 'messageReads')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $idUser;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function getMessage(): Message
    {
        return $this->message;
    }

    public function setMessage(Message $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getIdUser(): User
    {
        return $this->idUser;
    }

    public function setIdUser(User $idUser): static
    {
        $this->idUser = $idUser;

        return $this;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function setReadAt(?\DateTimeImmutable $readAt): static
    {
        $this->readAt = $readAt;

        return $this;
    }
}
