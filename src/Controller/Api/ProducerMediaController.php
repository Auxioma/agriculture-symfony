<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use App\Entity\Producer\ProducerMedia;
use App\Service\Producer\PhotoUploadRules;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Contrôleur API pour la gestion des médias des producteurs.
 *
 * Gère le téléversement, la validation et le stockage des fichiers médias (ex: photos de profil/bannière)
 * associés au profil d'un producteur sur un service de stockage objet (Flysystem/S3).
 */
final class ProducerMediaController extends AbstractController
{
    /**
     * Téléverse une photo pour le profil du producteur connecté.
     * Effectue les validations de sécurité essentielles avant l'envoi vers le stockage :
     *
     * Le fichier est ensuite stocké sur le système de fichiers objet via Flysystem (`producer_media.storage`)
     * et l'entité `ProducerMedia` est enregistrée en base de données.
     *
     * @param Request                $request Paramètres HTTP (champ `photo` dans `files` et `altText` optionnel dans `request`).
     * @param User                   $user    Utilisateur authentifié effectuant la requête.
     * @param FilesystemOperator     $storage Service Flysystem injecté pour la gestion du stockage d'objets.
     *
     * @return JsonResponse Identifiant et URL publique de l'image créée (201 Created), ou un message d'erreur (403 / 422).
     */
    #[Route('/api/producer/photos', methods: ['POST'])]
    public function uploadPhoto(
        Request $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        #[Autowire(service: 'producer_media.storage')] FilesystemOperator $storage,
    ): JsonResponse {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        // * mêmes règles que les photos de produit : jpeg, png ou webp, 5 Mo (voir PhotoUploadRules)
        $file = $request->files->get('photo');
        if (null !== ($error = PhotoUploadRules::error($file))) {
            return $this->json(['error' => $error], 422);
        }
        \assert($file instanceof UploadedFile);

        // * "cover" = la photo de couverture de « Modifier mon profil » --> sans champ type, photo de galerie 
        $type = (string) $request->request->get('type', 'photo');
        if (!\in_array($type, ['photo', 'cover'], true)) {
            return $this->json(['error' => 'Type de photo inconnu (photo ou cover).'], 422);
        }

        $media = new ProducerMedia();
        $media->setProducer($producer);
        $media->setType($type);
        $media->setAltText($request->request->get('altText'));
        $media->setIsPublic(true);

        $extension = $file->guessExtension() ?? 'bin';
        $key = sprintf('%s/%s.%s', $producer->getId()->toRfc4122(), $media->getId()->toRfc4122(), $extension);
        $storage->write($key, file_get_contents($file->getPathname()));
        $media->setFileUrl($storage->publicUrl($key));

        // * une seule couverture : l'ancienne part, et la nouvelle passe devant les autres photos (position la plus basse), qui est
        // * l'ordre de la fiche publique. Les fichiers de l'ancienne partent une fois la base enregistrée.
        $oldKeys = [];
        if ('cover' === $type) {
            $positions = [0];
            foreach ($em->getRepository(ProducerMedia::class)->findBy(['producer' => $producer]) as $other) {
                if ('cover' === $other->getType()) {
                    $oldKeys[] = $this->keyOf($other);
                    $em->remove($other);
                } else {
                    $positions[] = $other->getPosition() ?? 0;
                }
            }
            $media->setPosition(min($positions) - 1);
        }

        $em->persist($media);
        $em->flush();

        foreach ($oldKeys as $oldKey) {
            $storage->delete($oldKey);
        }

        return $this->json(['id' => $media->getId()->toRfc4122(), 'url' => $media->getFileUrl()], 201);
    }

    // * même clé qu'à l'envoi : producteur/photo.extension (l'extension se lit dans l'URL enregistrée)
    private function keyOf(ProducerMedia $media): string
    {
        $extension = pathinfo((string) parse_url((string) $media->getFileUrl(), PHP_URL_PATH), PATHINFO_EXTENSION);

        return sprintf('%s/%s.%s', $media->getProducer()->getId()->toRfc4122(), $media->getId()->toRfc4122(), $extension);
    }
}