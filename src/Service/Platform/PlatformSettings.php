<?php

namespace App\Service\Platform;

use App\Entity\Content\PlatformSetting;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accès typé aux paramètres globaux de la plateforme (table content.platform_settings). Une clé jamais
 * enregistrée renvoie sa valeur par défaut (DEFAULTS), donc l'application fonctionne sans aucune ligne en
 * base. save() ne fait que persist() -- le flush() reste à la charge de l'appelant, comme AuditLogger.
 */
class PlatformSettings
{
    public const NAME = 'platform_name';
    public const SUPPORT_EMAIL = 'support_email';
    public const DEFAULT_LOCALE = 'default_locale';
    public const DEFAULT_CURRENCY = 'default_currency';
    public const SESSION_MINUTES = 'session_minutes';

    public const DEFAULTS = [
        self::NAME => 'TrouveMoi Agri',
        self::SUPPORT_EMAIL => 'support@trouvemoi.com',
        self::DEFAULT_LOCALE => 'fr',
        self::DEFAULT_CURRENCY => 'EUR',
        self::SESSION_MINUTES => '60',
    ];

    public const LOCALES = ['fr' => 'Français', 'en' => 'English', 'es' => 'Español', 'it' => 'Italiano', 'de' => 'Deutsch'];

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function get(string $name): string
    {
        return $this->all()[$name] ?? self::DEFAULTS[$name] ?? throw new \InvalidArgumentException(sprintf('Paramètre inconnu : %s', $name));
    }

    public function defaultLocale(): string
    {
        return $this->get(self::DEFAULT_LOCALE);
    }

    public function sessionMinutes(): int
    {
        return (int) $this->get(self::SESSION_MINUTES);
    }

    /**
     * @return array<string, string> toutes les clés connues, valeur enregistrée ou à défaut valeur par défaut
     */
    public function all(): array
    {
        if (null === $this->cache) {
            $stored = [];
            foreach ($this->em->getRepository(PlatformSetting::class)->findAll() as $setting) {
                $stored[$setting->getName()] = (string) $setting->getValue();
            }
            $this->cache = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        }

        return $this->cache;
    }

    /**
     * @param array<string, string|int> $values clés connues uniquement, les autres sont ignorées
     */
    public function save(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $name => $value) {
            $setting = $this->em->find(PlatformSetting::class, $name) ?? new PlatformSetting($name);
            $setting->setValue((string) $value);
            $this->em->persist($setting);
        }
        $this->cache = null;
    }
}
