<?php

/**
 * Paramètre global de la plateforme (cahier fonctionnel : "Paramètres plateforme"), stocké en clé/valeur :
 * $name est la clé naturelle (ex. "platform_name"), $value la valeur sérialisée en chaîne. Les clés connues,
 * leurs valeurs par défaut et leur conversion de type sont portées par PlatformSettings ; une clé absente de
 * cette table retombe sur la valeur par défaut du service.
 */

namespace App\Entity\Content;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'platform_settings', schema: 'content')]
class PlatformSetting
{
    #[ORM\Id]
    #[ORM\Column(name: 'setting_key', length: 64)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $value = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): static
    {
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
