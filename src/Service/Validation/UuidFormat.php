<?php

namespace App\Service\Validation;

/**
 * Vérifie qu'une valeur reçue dans l'URL ou la query string ressemble à un UUID AVANT de la transmettre à
 * Doctrine/PostgreSQL : une colonne de type uuid refuse toute autre valeur ("abc") en levant une erreur de
 * conversion, ce qui se traduirait par une réponse HTTP 500 au lieu d'un 400/404 propre.
 *
 * Contrôle du format uniquement (8-4-4-4-12 caractères hexadécimaux), sans dépendre de symfony/uid.
 */
final class UuidFormat
{
    // * Modificateur D : sans lui, "$" accepterait aussi un retour à la ligne final ("<uuid>\n").
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }
}