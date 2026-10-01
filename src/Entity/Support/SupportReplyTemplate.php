<?php

/**
 * Modèle de réponse partagé par l'équipe support (cahier fonctionnel, module Back-office "Support" :
 * "Tickets, priorités, assignation, modèles de réponse, pièces jointes"). Contrairement à
 * QuickReply (producteur, propre à sa messagerie client), ce modèle n'appartient à aucun producteur
 * précis : il est géré et consulté par toute l'équipe support (SupportReplyTemplateCrudController),
 * pour répondre plus vite aux tickets (TicketMessage) sans réécrire les réponses les plus fréquentes
 * à chaque fois. $position ordonne l'affichage, $isActive permet de désactiver un modèle sans le
 * supprimer -- mêmes conventions que QuickReply, pour rester cohérent entre les deux entités.
 */

namespace App\Entity\Support;

use App\Repository\Support\SupportReplyTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SupportReplyTemplateRepository::class)]
#[ORM\Table(name: 'reply_templates', schema: 'support')]
class SupportReplyTemplate
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    #[ORM\Column(nullable: true)]
    private ?int $position = null;

    #[ORM\Column]
    private bool $isActive = true;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): static
    {
        $this->position = $position;

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
}
