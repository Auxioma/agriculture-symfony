<?php

namespace App\Controller\Api;

use App\Dto\Producer\CreateProducerProductRequest;
use App\Dto\Producer\ProductAvailabilityInput;
use App\Dto\Producer\UpdateProducerProductRequest;
use App\Entity\Catalog\Currency;
use App\Entity\Catalog\Label;
use App\Entity\Catalog\Product;
use App\Entity\Catalog\Unit;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProduct;
use App\Entity\Producer\ProductAvailability;
use App\Repository\Producer\ProducerProductRepository;
use App\Service\Producer\ProducerProductPresenter;
use App\Service\Producer\ProductAvailabilityWindow;
use App\Service\Producer\ProductPhotoStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Contrôleur API pour la gestion du catalogue de produits d'un producteur.
 *
 * Permet aux producteurs authentifiés de lister, d'ajouter, de modifier et de supprimer
 * des produits au sein de leur propre catalogue (entité ProducerProduct).
 *
 * Les requêtes entrantes exploite l'attribut `#[MapRequestPayload]` pour désérialiser
 * et valider automatiquement les DTOs (`CreateProducerProductRequest` et `UpdateProducerProductRequest`).
 *
 * Un produit porte aussi sa période de disponibilité (le premier créneau de product_availabilities, voir
 * ProductAvailabilityWindow), les labels que le producteur déclare pour lui et ses photos (ProducerProductPhotoController).
 */
final class ProducerProductController extends AbstractController
{
    private const DUPLICATE = 'Vous avez déjà ce produit avec cette variété : indiquez une autre variété.';

    /**
     * Liste le catalogue du producteur connecté, produits désactivés compris : la page "Produits et disponibilités"
     * les montre tous, et c'est is_active qui décide si le produit compte dans le matching (matching.find_matching_producers
     * ne garde que les producteurs qui ont le produit demandé ET actif).
     *
     * ! nom de base du produit, sans traduction : l'espace producteur est en français pour l'instant.
     * * saison et unité par défaut viennent du produit du catalogue (éditables dans l'admin) ; la période, le volume et l'unité
     * * "availability" sont ceux que le producteur a choisis pour son produit (premier créneau, null s'il n'en a pas).
     *
     * @return JsonResponse tableau des produits, triés par nom puis variété (403 sans profil producteur).
     */
    #[Route('/api/producer/products', methods: ['GET'])]
    public function listProducts(#[CurrentUser] User $user, ProducerProductRepository $products, ProducerProductPresenter $presenter): JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        return $this->json(array_map($presenter->forOwner(...), $products->findOfProducer($producer)));
    }

    /**
     * Ajoute un nouveau produit au catalogue du producteur connecté.
     *
     * Étapes de validation et traitement :
     * 1. Vérifie l'existence d'un profil producteur rattaché à l'utilisateur (403).
     * 2. Vérifie l'existence du produit générique référencé dans le DTO (422).
     * 3. S'assure que le producteur n'a pas déjà ce produit avec cette variété (409) : plusieurs variétés d'un même produit sont permises.
     * 4. Valide la devise, les labels et l'unité de la période si fournis (422).
     * 5. Instancie et persiste la nouvelle entité `ProducerProduct`, avec son créneau et ses labels.
     *
     * @param CreateProducerProductRequest $request DTO contenant les données de création (validé automatiquement).
     * @param User                         $user    Utilisateur authentifié effectuant la requête.
     *
     * @return JsonResponse Identifiant UUID du produit créé (201 Created) ou erreur HTTP appropriée.
     */
    #[Route('/api/producer/products', methods: ['POST'])]
    public function createProduct(
        #[MapRequestPayload] CreateProducerProductRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        ProducerProductRepository $products,
    ): JsonResponse {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $product = $em->find(Product::class, $request->productId);
        if ($product === null) {
            return $this->json(['error' => 'Produit inconnu.'], 422);
        }

        $variety = $this->cleanVariety($request->variety);
        if ($products->hasVariety($producer, $product, $variety)) {
            return $this->json(['error' => self::DUPLICATE], 409);
        }

        $producerProduct = new ProducerProduct();
        $producerProduct->setProducer($producer);
        $producerProduct->setProduct($product);
        $producerProduct->setVariety($variety);
        $producerProduct->setDescription($request->description);
        $producerProduct->setEstimatedVolume($request->estimatedVolume);
        $producerProduct->setDefaultPrice($request->defaultPrice);
        $producerProduct->setIsActive($request->isActive);

        $error = $this->applyCurrency($producerProduct, $request->currencyCode, $em)
            ?? $this->applyLabels($producerProduct, $request->labelIds, $em)
            ?? $this->applyAvailability($producerProduct, $request->availability, $em);
        if ($error !== null) {
            return $error;
        }

        $em->persist($producerProduct);
        $em->flush();

        return $this->json(['id' => $producerProduct->getId()->toRfc4122()], 201);
    }

    /**
     * Met à jour un produit existant dans le catalogue du producteur connecté.
     *
     * Récupère le produit via `findOwnedProducerProduct` pour valider l'existence et la propriété,
     * puis applique les modifications transmises dans le DTO `UpdateProducerProductRequest`.
     *
     * ! PUT remplace les champs du produit : un champ absent est remis à vide. Seuls `labelIds` et `availability`
     * ! font exception (absents = inchangés), pour que l'interrupteur de la liste ne touche ni aux labels ni à la période.
     */
    #[Route('/api/producer/products/{id}', methods: ['PUT'])]
    public function updateProduct(
        string $id,
        #[MapRequestPayload] UpdateProducerProductRequest $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        ProducerProductRepository $products,
    ): JsonResponse {
        $result = $this->findOwnedProducerProduct($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $producerProduct = $result;

        // * changer la variété ne doit pas tomber sur celle d'un autre produit du même type (Gala et Golden ne deviennent pas deux Gala)
        $variety = $this->cleanVariety($request->variety);
        if ($products->hasVariety($producerProduct->getProducer(), $producerProduct->getProduct(), $variety, $producerProduct)) {
            return $this->json(['error' => self::DUPLICATE], 409);
        }
        $producerProduct->setVariety($variety);
        $producerProduct->setDescription($request->description);
        $producerProduct->setEstimatedVolume($request->estimatedVolume);
        $producerProduct->setDefaultPrice($request->defaultPrice);
        $producerProduct->setIsActive($request->isActive);

        $error = $this->applyCurrency($producerProduct, $request->currencyCode, $em)
            ?? $this->applyLabels($producerProduct, $request->labelIds, $em)
            ?? $this->applyAvailability($producerProduct, $request->availability, $em);
        if ($error !== null) {
            return $error;
        }

        $em->flush();

        return $this->json(null, 200);
    }

    /**
     * Supprime un produit du catalogue du producteur connecté, avec ses photos (fichiers compris).
     * Son créneau et ses labels partent avec lui (orphanRemoval, clés étrangères en cascade).
     */
    #[Route('/api/producer/products/{id}', methods: ['DELETE'])]
    public function deleteProduct(string $id, #[CurrentUser] User $user, EntityManagerInterface $em, ProductPhotoStorage $photos): JsonResponse
    {
        $result = $this->findOwnedProducerProduct($id, $user, $em);
        if ($result instanceof JsonResponse) {
            return $result;
        }

        // * la clé étrangère des photos n'est pas en cascade : on retire leurs lignes avec le produit. Les fichiers partent une fois la
        // * base enregistrée : si elle échoue, aucune photo ne perd son fichier.
        $keys = [];
        foreach ($result->getMedia()->toArray() as $media) {
            $keys[] = $photos->keyOf($media);
            $em->remove($media);
        }
        $em->remove($result);
        $em->flush();
        foreach ($keys as $key) {
            $photos->delete($key);
        }

        return $this->json(null, 204);
    }

    /**
     * Helper privé pour charger un produit du catalogue et vérifier sa propriété.
     *
     * Contrôle successivement :
     * 1. Que l'utilisateur courant possède bien un profil producteur.
     * 2. Que le `ProducerProduct` existe en base de données.
     * 3. Que ce produit appartient bien au producteur connecté.
     */
    private function findOwnedProducerProduct(string $id, User $user, EntityManagerInterface $em): ProducerProduct|JsonResponse
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return $this->json(['error' => "Ce compte n'a pas de profil producteur."], 403);
        }

        $producerProduct = $em->find(ProducerProduct::class, $id);
        if ($producerProduct === null) {
            return $this->json(['error' => 'Produit introuvable.'], 404);
        }

        if ($producerProduct->getProducer() !== $producer) {
            return $this->json(['error' => 'Accès refusé.'], 403);
        }

        return $producerProduct;
    }

    // * espaces autour retirés, une variété vide n'est pas une variété (null) : c'est ce que compare hasVariety()
    private function cleanVariety(?string $variety): ?string
    {
        $variety = trim((string) $variety);

        return '' === $variety ? null : $variety;
    }

    private function applyCurrency(ProducerProduct $item, ?string $currencyCode, EntityManagerInterface $em): ?JsonResponse
    {
        if ($currencyCode === null) {
            return null;
        }

        $currency = $em->find(Currency::class, strtoupper($currencyCode));
        if ($currency === null) {
            return $this->json(['error' => 'Devise inconnue.'], 422);
        }
        $item->setCurrency($currency);

        return null;
    }

    /**
     * Remplace les labels du produit par ceux demandés (actifs dans le catalogue). null = on ne touche à rien.
     *
     * @param list<string>|null $labelIds
     */
    private function applyLabels(ProducerProduct $item, ?array $labelIds, EntityManagerInterface $em): ?JsonResponse
    {
        if ($labelIds === null) {
            return null;
        }

        $wanted = [];
        foreach (array_unique($labelIds) as $labelId) {
            $label = $em->find(Label::class, $labelId);
            if ($label === null || !$label->isActive()) {
                return $this->json(['error' => 'Label inconnu.'], 422);
            }
            $wanted[] = $label;
        }

        foreach ($item->getLabels()->toArray() as $label) {
            if (!\in_array($label, $wanted, true)) {
                $item->removeLabel($label);
            }
        }
        foreach ($wanted as $label) {
            $item->addLabel($label);
        }

        return null;
    }

    /**
     * Crée, met à jour ou supprime le créneau du produit (le premier s'il y en a plusieurs) d'après le formulaire.
     * null = on ne touche à rien ; tout à null = on supprime le créneau.
     *
     * * mêmes mois que le créneau et créneau pas fini : on garde ses dates telles quelles (un simple "Enregistrer" ne
     * * réécrit pas des dates précises posées ailleurs) ; sinon les dates sont celles du prochain créneau de ces mois.
     */
    private function applyAvailability(ProducerProduct $item, ?ProductAvailabilityInput $input, EntityManagerInterface $em): ?JsonResponse
    {
        if ($input === null) {
            return null;
        }

        $slot = ProductAvailabilityWindow::primary($item->getProductAvailabilities());
        if ($input->isBlank()) {
            if ($slot !== null) {
                $item->removeProductAvailability($slot);
            }

            return null;
        }

        $unit = null;
        if ($input->unitId !== null) {
            $unit = $em->find(Unit::class, $input->unitId);
            if ($unit === null) {
                return $this->json(['error' => 'Unité inconnue.'], 422);
            }
        }

        if ($slot === null) {
            $slot = new ProductAvailability();
            $item->addProductAvailability($slot);
            $em->persist($slot);
        }

        $today = new \DateTimeImmutable('today');
        if ($input->startMonth === null || $input->endMonth === null) {
            $slot->setAvailableFrom(null)->setAvailableTo(null);
        } elseif (ProductAvailabilityWindow::monthsOf($slot) !== [$input->startMonth, $input->endMonth] || $slot->getAvailableTo() < $today) {
            [$from, $to] = ProductAvailabilityWindow::forMonths($input->startMonth, $input->endMonth, $today);
            $slot->setAvailableFrom($from)->setAvailableTo($to);
        }
        $slot->setQuantityEstimate($input->quantity)->setUnit($unit);

        return null;
    }
}
