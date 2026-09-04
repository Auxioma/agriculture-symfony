<?php

namespace App\Controller\Api;

use App\Entity\Producer\ProducerProfile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lecture publique de l'annuaire des producteurs (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf §20.3).
 * Routes non authentifiées (voir security.yaml, access_control ^/api/producers) : liste et fiche détail.
 * Les routes réservées au producteur propriétaire (profil, produits, photos) suivront dans un contrôleur séparé.
 */
final class ProducerController extends AbstractController
{
    #[Route('/api/producers', methods: ['GET'])]
    public function listProducers(EntityManagerInterface $em): JsonResponse
    {
        // * Seuls les producteurs actifs sont visibles publiquement (même logique que Category/Product) :
        // * isActive=false correspond à un compte pas encore validé ou volontairement masqué.
        $producers = $em->getRepository(ProducerProfile::class)->findBy(['isActive' => true], ['farmName' => 'ASC']);

        return $this->json(array_map(
            static fn (ProducerProfile $p) => [
                'id' => $p->getId()->toRfc4122(),
                'farmName' => $p->getFarmName(),
                'slug' => $p->getSlug(),
                'city' => $p->getCity(),
                'countryCode' => $p->getCountry()?->getCode(),
                'verificationStatus' => $p->getVerificationStatus()->value,
            ],
            $producers
        ));
    }

    #[Route('/api/producers/{id}', methods: ['GET'])]
    public function getProducer(string $id, EntityManagerInterface $em): JsonResponse
    {
        $producer = $em->find(ProducerProfile::class, $id);
        // * Un producteur désactivé n'est pas listé, et son lien direct ne doit pas non plus être consultable.
        if ($producer === null || !$producer->isActive()) {
            return $this->json(['error' => 'Producteur introuvable.'], 404);
        }

        // ! Pas de coordonnées GPS précises exposées ici : ProducerProfile::$addressVisibility est prévu pour
        // ! contrôler la précision affichée publiquement (ville seule vs adresse complète), mais cette logique
        // ! n'est pas encore implémentée. Se limiter à city/countryCode évite d'exposer une position exacte
        // ! par défaut tant que la règle de confidentialité n'existe pas.
        return $this->json([
            'id' => $producer->getId()->toRfc4122(),
            'farmName' => $producer->getFarmName(),
            'slug' => $producer->getSlug(),
            'description' => $producer->getDescription(),
            'story' => $producer->getStory(),
            'city' => $producer->getCity(),
            'countryCode' => $producer->getCountry()?->getCode(),
            'verificationStatus' => $producer->getVerificationStatus()->value,
        ]);
    }
}