<?php

namespace App\Service\Catalog;

use App\Entity\Catalog\Category;
use App\Entity\Catalog\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Slug d'une catégorie ou d'un produit du catalogue, déduit de son nom quand l'administrateur ne l'a pas saisi.
 *
 * * Un slug vide cassait les liens du site (accueil et navbar pointent sur /categories/{slug} et retombent sinon sur
 * * « toutes les catégories »). Un slug saisi à la main n'est jamais touché.
 */
final class CatalogSlugger
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function fillIfBlank(object $entity): void
    {
        if (!$entity instanceof Category && !$entity instanceof Product) {
            return;
        }
        if ('' !== trim((string) $entity->getSlug())) {
            return;
        }

        $base = (new AsciiSlugger('fr'))->slug($entity->getName())->lower()->toString();
        if ('' === $base) {
            return;
        }

        // * le slug est unique en base (index) : s'il existe déjà, on ajoute -2, -3...
        $slug = $base;
        for ($i = 2; null !== ($other = $this->em->getRepository($entity::class)->findOneBy(['slug' => $slug])) && $other !== $entity; ++$i) {
            $slug = $base.'-'.$i;
        }
        $entity->setSlug($slug);
    }
}
