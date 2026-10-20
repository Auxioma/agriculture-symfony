<?php

namespace App\Service\Producer;

use App\Entity\Producer\ProductAvailability;

/**
 * Passage entre les mois du formulaire du producteur ("Juin à Septembre") et les dates d'un créneau de disponibilité
 * (product_availabilities.available_from / available_to, des dates). Sans état, pour se tester sans base.
 */
final class ProductAvailabilityWindow
{
    /**
     * Les dates du prochain créneau qui n'est pas déjà fini : "Juin à Septembre" saisi en octobre donne l'été suivant,
     * saisi en juillet donne l'été en cours. Une période à cheval sur deux années ("Octobre à Mars") est celle en cours si
     * on est en février, la prochaine en juin.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} premier jour du mois de début, dernier jour du mois de fin
     */
    public static function forMonths(int $startMonth, int $endMonth, \DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);
        $year = (int) $today->format('Y');

        // * 3 années suffisent : celle d'avant (période à cheval encore en cours), l'actuelle, la suivante (toujours bonne)
        foreach ([$year - 1, $year, $year + 1] as $startYear) {
            $endYear = $endMonth >= $startMonth ? $startYear : $startYear + 1;
            $from = new \DateTimeImmutable(\sprintf('%d-%02d-01', $startYear, $startMonth));
            $to = (new \DateTimeImmutable(\sprintf('%d-%02d-01', $endYear, $endMonth)))->modify('last day of this month');
            if ($to >= $today) {
                return [$from, $to];
            }
        }

        throw new \LogicException('Aucune période trouvée.');
    }

    /**
     * Le créneau que gère le formulaire quand le produit en a plusieurs : le plus ancien (sans date de début d'abord).
     *
     * @param iterable<ProductAvailability> $slots
     */
    public static function primary(iterable $slots): ?ProductAvailability
    {
        $primary = null;
        foreach ($slots as $slot) {
            $from = $slot->getAvailableFrom()?->getTimestamp() ?? PHP_INT_MIN;
            if (null === $primary || $from < ($primary->getAvailableFrom()?->getTimestamp() ?? PHP_INT_MIN)) {
                $primary = $slot;
            }
        }

        return $primary;
    }

    /**
     * Les mois d'un créneau, pour remplir le formulaire.
     *
     * @return array{0: ?int, 1: ?int} [début, fin], null pour une date absente
     */
    public static function monthsOf(ProductAvailability $slot): array
    {
        return [
            null === $slot->getAvailableFrom() ? null : (int) $slot->getAvailableFrom()->format('n'),
            null === $slot->getAvailableTo() ? null : (int) $slot->getAvailableTo()->format('n'),
        ];
    }
}
