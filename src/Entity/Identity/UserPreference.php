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

use App\Repository\Identity\UserPreferenceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserPreferenceRepository::class)]
#[ORM\Table(name: 'user_preferences', schema: 'identity')]
class UserPreference
{
    #[ORM\Id]
    #[ORM\OneToOne(inversedBy: 'preference', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private User $idUser;

    #[ORM\Column(length: 10)]
    private string $locale;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $distanceUnit = null;

    #[ORM\Column(nullable: true)]
    private ?array $notificationSettings = null;

    public function getIdUser(): User
    {
        return $this->idUser;
    }

    public function setIdUser(User $idUser): static
    {
        $this->idUser = $idUser;

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

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getDistanceUnit(): ?string
    {
        return $this->distanceUnit;
    }

    public function setDistanceUnit(?string $distanceUnit): static
    {
        $this->distanceUnit = $distanceUnit;

        return $this;
    }

    public function getNotificationSettings(): ?array
    {
        return $this->notificationSettings;
    }

    public function setNotificationSettings(?array $notificationSettings): static
    {
        $this->notificationSettings = $notificationSettings;

        return $this;
    }
}
