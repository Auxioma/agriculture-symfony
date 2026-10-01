<?php

namespace App\Controller\Api;

use App\Dto\Request\SaveRecurringRuleRequest;
use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Matching\RecurringRequestRule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Cahier fonctionnel -- "demande récurrente" (NeedType::Recurring) : permet au client d'activer une
 * republication automatique périodique d'une de ses ClientRequest. Une seule règle par ClientRequest
 * (relation ManyToOne côté RecurringRequestRule, jamais plusieurs règles actives pour la même demande dans
 * ce MVP). RunRecurringRequestsCommand consomme ensuite ces règles pour republier via ClientRequest::duplicate().
 */

final class RecurringRequestRuleController extends AbstractController
{
    #[Route('/api/client/requests/{id}/recurring-rule', methods: ['POST'])]
    public function createRule(
        string $id,
        #[MapRequestPayload] SaveRecurringRuleRequest $request,
        #[CurrentUser] User $client,
        EntityManagerInterface $em,
    ): JsonResponse {
        $result = $this->findOwnedRequest($id, $client, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        if ($em->getRepository(RecurringRequestRule::class)->findOneBy(['request' => $result]) !== null) {
            return $this->json(['error' => 'Une règle de récurrence existe déjà pour cette demande.'], 409);
        }

        $rule = new RecurringRequestRule();
        $rule->setRequest($result);
        $this->applyRuleData($rule, $request);
        $rule->setIsActive(true);

        $em->persist($rule);
        $em->flush();

        return $this->json($this->serializeRule($rule), 201);
    }

    #[Route('/api/client/requests/{id}/recurring-rule', methods: ['GET'])]
    public function getRule(string $id, #[CurrentUser] User $client, EntityManagerInterface $em): JsonResponse
    {
        $result = $this->findOwnedRequest($id, $client, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        $rule = $this->findRuleOrResponse($result, $em);
        if ($rule instanceof JsonResponse) {
            return $rule;
        }

        return $this->json($this->serializeRule($rule));
    }

    #[Route('/api/client/requests/{id}/recurring-rule', methods: ['PUT'])]
    public function updateRule(
        string $id,
        #[MapRequestPayload] SaveRecurringRuleRequest $request,
        #[CurrentUser] User $client,
        EntityManagerInterface $em,
    ): JsonResponse {
        $result = $this->findOwnedRequest($id, $client, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        $rule = $this->findRuleOrResponse($result, $em);
        if ($rule instanceof JsonResponse) {
            return $rule;
        }

        $this->applyRuleData($rule, $request);
        $em->flush();

        return $this->json($this->serializeRule($rule));
    }

    #[Route('/api/client/requests/{id}/recurring-rule', methods: ['DELETE'])]
    public function deleteRule(string $id, #[CurrentUser] User $client, EntityManagerInterface $em): JsonResponse
    {
        $result = $this->findOwnedRequest($id, $client, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        $rule = $this->findRuleOrResponse($result, $em);
        if ($rule instanceof JsonResponse) {
            return $rule;
        }

        $em->remove($rule);
        $em->flush();

        return $this->json(null, 204);
    }

    // * frequency->nextRunAfter(now) : le premier cycle de republication démarre à la prochaine échéance de
    // * la fréquence choisie, pas immédiatement (créer la règle ne republie pas la demande courante).
    private function applyRuleData(RecurringRequestRule $rule, SaveRecurringRuleRequest $request): void
    {
        $rule->setFrequency($request->frequency);
        $rule->setEndAt($request->endAt);
        $rule->setNextRunAt($request->frequency->nextRunAfter(new \DateTimeImmutable()));
    }

    private function findOwnedRequest(string $id, User $client, EntityManagerInterface $em): ClientRequest|JsonResponse
    {
        $clientRequest = $em->find(ClientRequest::class, $id);

        if ($clientRequest === null) {
            return $this->json(['error' => 'Demande introuvable.'], 404);
        }

        if ($clientRequest->getClient() !== $client) {
            return $this->json(['error' => 'Accès refusé.'], 403);
        }

        return $clientRequest;
    }

    private function findRuleOrResponse(ClientRequest $clientRequest, EntityManagerInterface $em): RecurringRequestRule|JsonResponse
    {
        $rule = $em->getRepository(RecurringRequestRule::class)->findOneBy(['request' => $clientRequest]);
        if ($rule === null) {
            return $this->json(['error' => 'Aucune règle de récurrence pour cette demande.'], 404);
        }

        return $rule;
    }

    /** @return array{id: string, frequency: string, nextRunAt: ?string, endAt: ?string, isActive: bool} */
    private function serializeRule(RecurringRequestRule $rule): array
    {
        return [
            'id' => $rule->getId()->toRfc4122(),
            'frequency' => $rule->getFrequency()?->value,
            'nextRunAt' => $rule->getNextRunAt()?->format(DATE_ATOM),
            'endAt' => $rule->getEndAt()?->format('Y-m-d'),
            'isActive' => $rule->isActive(),
        ];
    }
}
