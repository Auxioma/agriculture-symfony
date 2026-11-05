<?php

namespace App\Service\Account;

use App\Entity\Identity\DataRequest;
use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Producer\ProducerProfile;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Export des données d'un utilisateur (cahier fonctionnel : « export des données »). Pour un producteur : son exploitation,
 * ses produits, labels, zones, horaires, réponses envoyées et abonnements, pas seulement l'en-tête de la fiche. Chaque export
 * est tracé dans identity.data_requests (MPD).
 */
final class AccountExporter
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $data = [
            'user' => [
                'id' => $user->getId()->toRfc4122(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'phone' => $user->getPhone(),
                'locale' => $user->getLocale(),
                'roles' => $user->getRoles(),
                'createdAt' => $user->getCreatedAt()->format(DATE_ATOM),
            ],
        ];

        $preference = $user->getPreference();
        if ($preference !== null) {
            $data['preferences'] = [
                'locale' => $preference->getLocale(),
                'currency' => $preference->getCurrency(),
                'distanceUnit' => $preference->getDistanceUnit(),
                'notificationSettings' => $preference->getNotificationSettings(),
            ];
        }

        $producer = $user->getProducerProfile();
        if ($producer !== null) {
            $data += $this->producerData($producer);
        }

        $data['clientRequests'] = array_map(
            static fn (ClientRequest $r) => [
                'id' => $r->getId()->toRfc4122(),
                'needType' => $r->getNeedType()->value,
                'status' => $r->getStatus()->value,
                'customProduct' => $r->getCustomProduct(),
                'message' => $r->getMessage(),
                'createdAt' => $r->getCreatedAt()->format(DATE_ATOM),
            ],
            $this->em->getRepository(ClientRequest::class)->findBy(['client' => $user])
        );

        $request = new DataRequest();
        $request->setIdUser($user);
        $request->setRequestType('export');
        $request->setStatus('done');
        $request->setRequestedAt(new \DateTimeImmutable());
        $request->setProcessedAt(new \DateTimeImmutable());
        $this->em->persist($request);
        $this->em->flush();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function producerData(ProducerProfile $producer): array
    {
        $settings = $producer->getSettings();

        return [
            'producerProfile' => [
                'id' => $producer->getId()->toRfc4122(),
                'farmName' => $producer->getFarmName(),
                'slug' => $producer->getSlug(),
                'description' => $producer->getDescription(),
                'story' => $producer->getStory(),
                'city' => $producer->getCity(),
                'postalCode' => $producer->getPostalCode(),
                'verificationStatus' => $producer->getVerificationStatus()->value,
                'settings' => $settings === null ? null : [
                    'pickupEnabled' => $settings->isPickupEnabled(),
                    'deliveryEnabled' => $settings->isDeliveryEnabled(),
                    'acceptsIndividuals' => $settings->isAcceptsIndividuals(),
                    'acceptsProfessionals' => $settings->isAcceptsProfessionals(),
                ],
            ],
            'products' => array_map(
                static fn ($p) => [
                    'product' => $p->getProduct()->getName(),
                    'variety' => $p->getVariety(),
                    'description' => $p->getDescription(),
                    'estimatedVolume' => $p->getEstimatedVolume(),
                    'defaultPrice' => $p->getDefaultPrice(),
                    'currency' => $p->getCurrency()?->getCode(),
                    'isActive' => $p->isActive(),
                    'labels' => array_map(static fn ($label) => $label->getName(), $p->getLabels()->toArray()),
                ],
                $producer->getProducts()->toArray()
            ),
            'labels' => array_map(
                static fn ($l) => [
                    'label' => $l->getLabel()->getName(),
                    'verifiedAt' => $l->getVerifiedAt()?->format(DATE_ATOM),
                    'expiresAt' => $l->getExpiresAt()?->format(DATE_ATOM),
                ],
                $producer->getLabels()->toArray()
            ),
            'deliveryZones' => array_map(
                static fn ($z) => ['radiusKm' => $z->getRadiusKm(), 'rules' => $z->getRules()],
                $producer->getDeliveryZones()->toArray()
            ),
            'openingHours' => array_map(
                static fn ($h) => [
                    'weekday' => $h->getWeekday(),
                    'opensAt' => $h->getOpensAt()?->format('H:i'),
                    'closesAt' => $h->getClosesAt()?->format('H:i'),
                    'isClosed' => $h->isClosed(),
                ],
                $producer->getOpeningHours()->toArray()
            ),
            'replies' => array_map(
                static fn ($r) => [
                    'id' => $r->getId()->toRfc4122(),
                    'requestId' => $r->getRequest()->getId()->toRfc4122(),
                    'replyText' => $r->getReplyText(),
                    'priceAmount' => $r->getPriceAmount(),
                    'conditions' => $r->getConditions(),
                    'status' => $r->getStatus()->value,
                    'createdAt' => $r->getCreatedAt()->format(DATE_ATOM),
                ],
                $producer->getReplies()->toArray()
            ),
            'subscriptions' => array_map(
                static fn ($s) => [
                    'plan' => $s->getPlanPrice()->getPlan()->getName(),
                    'billingCycle' => $s->getPlanPrice()->getBillingCycle()->value,
                    'status' => $s->getStatus()->value,
                    'currentPeriodStart' => $s->getCurrentPeriodStart()->format(DATE_ATOM),
                    'currentPeriodEnd' => $s->getCurrentPeriodEnd()->format(DATE_ATOM),
                    'createdAt' => $s->getCreatedAt()->format(DATE_ATOM),
                ],
                $producer->getSubscriptions()->toArray()
            ),
        ];
    }
}
