<?php

namespace App\Controller\Api;

use App\Dto\Producer\AttachLabelDocumentRequest;
use App\Dto\Producer\ClaimLabelRequest;
use App\Entity\Catalog\Label;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerLabel;
use App\Entity\Producer\ProducerProfile;
use App\Entity\Trust\VerificationDocument;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Vérification avancée du producteur connecté (cahier fonctionnel, "Vérification
 * avancée : labels/certifications avec preuve"). Un document justificatif (Kbis, certificat...)
 * s'uploade indépendamment, puis se rattache à un label revendiqué -- ou l'inverse (le document
 * peut être fourni après coup, voir ProducerLabel::$document). Modération (Approuver/Rejeter) :
 * VerificationDocumentCrudController (back-office), seul endroit qui pose le statut Approved d'un
 * document et, par ricochet, ProducerLabel::$verifiedAt.
 */
final class ProducerVerificationController extends AbstractController
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    #[Route('/api/producer/verification-documents', methods: ['GET'])]
    public function listMyDocuments(
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        #[Autowire(service: 'verification_documents.storage')] FilesystemOperator $storage,
    ): JsonResponse {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $documents = $em->getRepository(VerificationDocument::class)->findBy(['producer' => $producer]);

        return $this->json(array_map(
            static fn (VerificationDocument $d) => [
                'id' => $d->getId()->toRfc4122(),
                'type' => $d->getType(),
                'status' => $d->getStatus()->value,
                'expiresAt' => $d->getExpiresAt()?->format(DATE_ATOM),
                // ! Jamais d'URL persistée : $fileUrl en base est une clé objet privée, l'URL signée
                // ! est générée à la demande (même principe que MessageAttachment).
                'url' => $d->getFileUrl() !== null ? $storage->temporaryUrl($d->getFileUrl(), new \DateTimeImmutable('+1 hour')) : null,
            ],
            $documents
        ));
    }

    #[Route('/api/producer/verification-documents', methods: ['POST'])]
    public function uploadDocument(
        Request $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        #[Autowire(service: 'verification_documents.storage')] FilesystemOperator $storage,
    ): JsonResponse {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $file = $request->files->get('file');
        if ($file === null || !$file->isValid()) {
            return $this->json(['error' => 'Aucun fichier "file" valide reçu.'], 422);
        }
        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            return $this->json(['error' => 'Format non supporté (jpeg, png, webp ou pdf uniquement).'], 422);
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            return $this->json(['error' => 'Fichier trop volumineux (10 Mo maximum).'], 422);
        }

        $document = new VerificationDocument();
        $document->setProducer($producer);
        $document->setType($request->request->get('type'));

        $key = sprintf('%s/%s', $producer->getId()->toRfc4122(), $document->getId()->toRfc4122());
        $storage->write($key, file_get_contents($file->getPathname()));
        $document->setFileUrl($key);

        $em->persist($document);
        $em->flush();

        return $this->json(['id' => $document->getId()->toRfc4122(), 'status' => $document->getStatus()->value], 201);
    }

    #[Route('/api/producer/labels', methods: ['GET'])]
    public function listMyLabels(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $labels = $em->getRepository(ProducerLabel::class)->findBy(['producer' => $producer]);

        return $this->json(array_map($this->describeLabel(...), $labels));
    }

    #[Route('/api/producer/labels', methods: ['POST'])]
    public function claimLabel(
        #[MapRequestPayload] ClaimLabelRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $label = $em->find(Label::class, $request->labelId);
        if ($label === null || !$label->isActive()) {
            return $this->json(['error' => 'Label introuvable.'], 404);
        }

        if ($em->find(ProducerLabel::class, ['producer' => $producer, 'label' => $label]) !== null) {
            return $this->json(['error' => 'Ce label a déjà été revendiqué.'], 409);
        }

        $document = null;
        if ($request->documentId !== null) {
            $document = $this->findOwnDocument($request->documentId, $producer, $em);
            if ($document === null) {
                return $this->json(['error' => 'Document justificatif introuvable.'], 404);
            }
        }

        $producerLabel = new ProducerLabel();
        $producerLabel->setProducer($producer);
        $producerLabel->setLabel($label);
        $producerLabel->setDocument($document);

        $em->persist($producerLabel);
        $em->flush();

        return $this->json($this->describeLabel($producerLabel), 201);
    }

    #[Route('/api/producer/labels/{labelId}', methods: ['PUT'])]
    public function attachLabelDocument(
        string $labelId,
        #[MapRequestPayload] AttachLabelDocumentRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $label = $em->find(Label::class, $labelId);
        $producerLabel = $label !== null ? $em->find(ProducerLabel::class, ['producer' => $producer, 'label' => $label]) : null;
        if ($producerLabel === null) {
            return $this->json(['error' => 'Label non revendiqué.'], 404);
        }

        $document = $this->findOwnDocument($request->documentId, $producer, $em);
        if ($document === null) {
            return $this->json(['error' => 'Document justificatif introuvable.'], 404);
        }

        $producerLabel->setDocument($document);
        $em->flush();

        return $this->json($this->describeLabel($producerLabel));
    }

    #[Route('/api/producer/labels/{labelId}', methods: ['DELETE'])]
    public function removeLabel(string $labelId, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $label = $em->find(Label::class, $labelId);
        $producerLabel = $label !== null ? $em->find(ProducerLabel::class, ['producer' => $producer, 'label' => $label]) : null;
        if ($producerLabel === null) {
            return $this->json(['error' => 'Label non revendiqué.'], 404);
        }

        $em->remove($producerLabel);
        $em->flush();

        return $this->json(null, 204);
    }

    private function findOwnDocument(string $documentId, ProducerProfile $producer, EntityManagerInterface $em): ?VerificationDocument
    {
        $document = $em->find(VerificationDocument::class, $documentId);

        return $document !== null && $document->getProducer() === $producer ? $document : null;
    }

    /**
     * @return array{labelId: string, code: string, name: string, verifiedAt: string|null, expiresAt: string|null, documentId: string|null, documentStatus: string|null}
     */
    private function describeLabel(ProducerLabel $producerLabel): array
    {
        $document = $producerLabel->getDocument();

        return [
            'labelId' => $producerLabel->getLabel()->getId()->toRfc4122(),
            'code' => $producerLabel->getLabel()->getCode(),
            'name' => $producerLabel->getLabel()->getName(),
            'verifiedAt' => $producerLabel->getVerifiedAt()?->format(DATE_ATOM),
            'expiresAt' => $producerLabel->getExpiresAt()?->format(DATE_ATOM),
            'documentId' => $document?->getId()->toRfc4122(),
            'documentStatus' => $document?->getStatus()->value,
        ];
    }
}
