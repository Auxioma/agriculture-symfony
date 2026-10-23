<?php

namespace App\Service\Producer;

use App\Entity\Producer\ProducerMedia;

/**
 * Emplacement du fichier d'une photo de l'exploitation dans le stockage (producer_media.storage). Une seule définition, pour
 * l'envoi (ProducerMediaController), le remplacement de la couverture et l'effacement du compte (AccountEraser).
 */
final class ProducerMediaKey
{
    // * même clé qu'à l'envoi : producteur/photo.extension (l'extension se lit dans l'URL enregistrée)
    public static function of(ProducerMedia $media): string
    {
        $extension = pathinfo((string) parse_url((string) $media->getFileUrl(), PHP_URL_PATH), PATHINFO_EXTENSION);

        return \sprintf('%s/%s.%s', $media->getProducer()->getId()->toRfc4122(), $media->getId()->toRfc4122(), $extension);
    }
}
