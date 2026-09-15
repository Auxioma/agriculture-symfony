<?php

namespace App\Controller\Api;

use App\Dto\Producer\SaveOpeningHourRequest;
use App\Entity\Identity\User;
use App\Entity\Producer\OpeningHour;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Horaires d'ouverture du producteur connecté (cahier fonctionnel, profil producteur enrichi).
 * Lecture publique : voir ProducerController::getProducer(). Un jour peut avoir plusieurs créneaux
 * (ex. matin + après-midi) : plusieurs lignes avec le même $weekday, comme documenté sur l'entité.
 */
final class ProducerOpeningHourController extends AbstractController
{
    #[Route('/api/producer/opening-hours', methods: ['GET'])]
    public function listMyOpeningHours(#[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $hours = $em->getRepository(OpeningHour::class)->findBy(['producer' => $producer], ['weekday' => 'ASC']);

        return $this->json(array_map($this->describe(...), $hours));
    }

    #[Route('/api/producer/opening-hours', methods: ['POST'])]
    public function createOpeningHour(
        #[MapRequestPayload] SaveOpeningHourRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $hour = new OpeningHour();
        $hour->setProducer($producer);
        $this->applyRequest($hour, $request);

        $em->persist($hour);
        $em->flush();

        return $this->json($this->describe($hour), 201);
    }

    #[Route('/api/producer/opening-hours/{id}', methods: ['PUT'])]
    public function updateOpeningHour(
        string $id,
        #[MapRequestPayload] SaveOpeningHourRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): JsonResponse {
        $hour = $em->find(OpeningHour::class, $id);
        if ($hour === null || $hour->getProducer() !== $user->getProducerProfile()) {
            return $this->json(['error' => "Créneau d'ouverture introuvable."], 404);
        }

        $this->applyRequest($hour, $request);
        $em->flush();

        return $this->json($this->describe($hour));
    }

    #[Route('/api/producer/opening-hours/{id}', methods: ['DELETE'])]
    public function deleteOpeningHour(string $id, #[CurrentUser] User $user, EntityManagerInterface $em): JsonResponse
    {
        $hour = $em->find(OpeningHour::class, $id);
        if ($hour === null || $hour->getProducer() !== $user->getProducerProfile()) {
            return $this->json(['error' => "Créneau d'ouverture introuvable."], 404);
        }

        $em->remove($hour);
        $em->flush();

        return $this->json(null, 204);
    }

    private function applyRequest(OpeningHour $hour, SaveOpeningHourRequest $request): void
    {
        $hour->setWeekday($request->weekday);
        $hour->setIsClosed($request->isClosed);
        $hour->setOpensAt($request->isClosed ? null : $this->parseTime($request->opensAt));
        $hour->setClosesAt($request->isClosed ? null : $this->parseTime($request->closesAt));
    }

    private function parseTime(?string $time): ?\DateTimeImmutable
    {
        if ($time === null) {
            return null;
        }

        // * Le format "HH:MM" est déjà garanti par SaveOpeningHourRequest::$opensAt/$closesAt (Assert\Regex) --
        // * createFromFormat ne peut donc pas échouer ici, mais son type de retour reste
        // * DateTimeImmutable|false ; "?:" ramène ça au type ?DateTimeImmutable attendu par l'entité.
        return \DateTimeImmutable::createFromFormat('H:i', $time) ?: null;
    }

    /**
     * @return array{id: string, weekday: int|null, opensAt: string|null, closesAt: string|null, isClosed: bool}
     */
    private function describe(OpeningHour $hour): array
    {
        return [
            'id' => $hour->getId()->toRfc4122(),
            'weekday' => $hour->getWeekday(),
            'opensAt' => $hour->getOpensAt()?->format('H:i'),
            'closesAt' => $hour->getClosesAt()?->format('H:i'),
            'isClosed' => $hour->isClosed(),
        ];
    }
}
