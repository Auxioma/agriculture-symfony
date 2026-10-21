<?php

namespace App\Service\Producer;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Ce qu'on accepte comme photo envoyée par un producteur, pour toutes les photos (exploitation et produits) : une seule
 * source, jpeg, png ou webp, 5 Mo au plus.
 *
 * ! Cahier devops : « Contrôle type MIME, taille maximale » avant tout envoi vers le stockage objet. Le type est lu dans le
 * ! contenu du fichier (fileinfo), pas dans le Content-Type déclaré par le client.
 */
final class PhotoUploadRules
{
    public const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    public const MAX_SIZE_BYTES = 5 * 1024 * 1024;

    /**
     * null si le fichier peut partir au stockage, sinon le message à renvoyer avec le 422.
     */
    public static function error(mixed $file): ?string
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return 'Aucun fichier "photo" valide reçu.';
        }
        if (!\in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            return 'Format non supporté (jpeg, png ou webp uniquement).';
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            return 'Fichier trop volumineux (5 Mo maximum).';
        }

        return null;
    }
}
