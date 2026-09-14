<?php

/**
 * Type Doctrine personnalisé pour la colonne PostgreSQL CITEXT (texte insensible à la casse nativement en
 * base, ex. email/slug/code un peu partout dans le schéma). Enregistré dans config/packages/doctrine.yaml
 * (dbal.types.citext et dbal.connections.default.mapping_types.citext) -- sans ce mapping, Doctrine ne
 * reconnaîtrait pas le type CITEXT lu depuis la base et échouerait au démarrage.
 */

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

class CitextType extends StringType
{
    public function getName(): string
    {
        return 'citext';
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'CITEXT';
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
