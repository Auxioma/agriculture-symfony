<?php

namespace App\Controller\Api;

use App\Entity\Identity\User;
use App\Entity\Producer\ProducerMedia;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
    // * Types MIME d'images autorisés pour le téléversement 
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    // * Taille maximale autorisée pour un fichier (5 Mo en octets)
    private const MAX_SIZE_BYTES = 5 * 1024 * 1024;

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

        $file = $request->files->get('photo');
        if ($file === null || !$file->isValid()) {
            return $this->json(['error' => 'Aucun fichier "photo" valide reçu.'], 422);
        }

        // ! Cahier devops : "Contrôle type MIME, taille maximale" avant tout envoi vers le stockage objet.
        // ! getMimeType() lit le contenu réel du fichier (fileinfo), pas le Content-Type déclaré par le client.
        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            return $this->json(['error' => 'Format non supporté (jpeg, png ou webp uniquement).'], 422);
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            return $this->json(['error' => 'Fichier trop volumineux (5 Mo maximum).'], 422);
        }

        $media = new ProducerMedia();
        $media->setProducer($producer);
        $media->setType('photo');
        $media->setAltText($request->request->get('altText'));
        $media->setIsPublic(true);

        $extension = $file->guessExtension() ?? 'bin';
        $key = sprintf('%s/%s.%s', $producer->getId()->toRfc4122(), $media->getId()->toRfc4122(), $extension);
        $storage->write($key, file_get_contents($file->getPathname()));
        $media->setFileUrl($storage->publicUrl($key));

        $em->persist($media);
        $em->flush();

        return $this->json(['id' => $media->getId()->toRfc4122(), 'url' => $media->getFileUrl()], 201);
    }
}