<?php

namespace App\Tests\Functional\Service;

use App\Entity\Producer\ProductAvailability;
use App\Service\Producer\ProductAvailabilityWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les mois du formulaire du producteur ("Juin à Septembre") deviennent les dates d'un créneau de disponibilité.
 * Sans base : la date du jour est passée en paramètre.
 */
final class ProductAvailabilityWindowTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, int, string, string}> aujourd'hui, début, fin, premier jour, dernier jour
     */
    public static function windows(): iterable
    {
        yield 'en pleine période : celle en cours' => ['2026-07-15', 6, 9, '2026-06-01', '2026-09-30'];
        yield 'le dernier jour compte encore' => ['2026-09-30', 6, 9, '2026-06-01', '2026-09-30'];
        yield 'le lendemain de la fin : la suivante' => ['2026-10-01', 6, 9, '2027-06-01', '2027-09-30'];
        yield 'avant la période : celle de cette année' => ['2026-02-03', 6, 9, '2026-06-01', '2026-09-30'];
        yield 'un seul mois' => ['2026-05-20', 7, 7, '2026-07-01', '2026-07-31'];
        yield 'à cheval sur deux années, en cours en février' => ['2027-02-10', 10, 3, '2026-10-01', '2027-03-31'];
        yield 'à cheval sur deux années, la prochaine en juin' => ['2027-06-01', 10, 3, '2027-10-01', '2028-03-31'];
        yield 'à cheval sur deux années, en cours en novembre' => ['2026-11-20', 10, 3, '2026-10-01', '2027-03-31'];
        yield 'février d\'une année bissextile' => ['2027-12-05', 12, 2, '2027-12-01', '2028-02-29'];
        yield 'toute l\'année' => ['2026-10-19', 1, 12, '2026-01-01', '2026-12-31'];
    }

    #[DataProvider('windows')]
    public function testForMonthsGivesTheNextWindowNotAlreadyOver(string $today, int $start, int $end, string $from, string $to): void
    {
        [$gotFrom, $gotTo] = ProductAvailabilityWindow::forMonths($start, $end, new \DateTimeImmutable($today));

        self::assertSame($from, $gotFrom->format('Y-m-d'));
        self::assertSame($to, $gotTo->format('Y-m-d'));
    }

    public function testForMonthsIgnoresTheTimeOfDay(): void
    {
        [, $to] = ProductAvailabilityWindow::forMonths(6, 9, new \DateTimeImmutable('2026-09-30 23:59:59'));

        self::assertSame('2026-09-30', $to->format('Y-m-d'));
    }

    public function testMonthsOfReadsTheMonthsBackAndToleratesMissingDates(): void
    {
        $slot = (new ProductAvailability())->setAvailableFrom(new \DateTimeImmutable('2026-06-01'))->setAvailableTo(new \DateTimeImmutable('2026-09-30'));
        self::assertSame([6, 9], ProductAvailabilityWindow::monthsOf($slot));

        self::assertSame([null, null], ProductAvailabilityWindow::monthsOf(new ProductAvailability()));
    }

    public function testPrimaryIsTheOldestSlotAndOneWithoutStartComesFirst(): void
    {
        $december = (new ProductAvailability())->setAvailableFrom(new \DateTimeImmutable('2026-12-12'));
        $october = (new ProductAvailability())->setAvailableFrom(new \DateTimeImmutable('2026-10-03'));
        $undated = new ProductAvailability();

        self::assertSame($october, ProductAvailabilityWindow::primary([$december, $october]));
        self::assertSame($undated, ProductAvailabilityWindow::primary([$december, $undated, $october]));
        self::assertNull(ProductAvailabilityWindow::primary([]));
    }
}
