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

final class ProducerMediaController extends AbstractController
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_SIZE_BYTES = 5 * 1024 * 1024;

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