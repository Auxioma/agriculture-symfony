<?php

namespace App\Tests\Unit\Service\Pagination;

use App\Service\Pagination\Pagination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Teste la lecture de ?page= et ?pageSize= et l'en-tête X-Total-Count. Test unitaire : une requête construite à la main,
 * pas de base de données (la découpe d'une requête Doctrine est testée avec chaque liste qui s'en sert).
 */
final class PaginationTest extends TestCase
{
    public function testDefaultsToFirstPageWithTheListDefaultSize(): void
    {
        $pagination = Pagination::fromRequest(new Request(), defaultPageSize: 12);

        self::assertNotNull($pagination);
        self::assertSame(1, $pagination->page);
        self::assertSame(12, $pagination->pageSize);
        self::assertSame(0, $pagination->offset());
    }

    public function testReadsPageAndPageSize(): void
    {
        $pagination = Pagination::fromRequest(new Request(['page' => '3', 'pageSize' => '25']));

        self::assertNotNull($pagination);
        self::assertSame(3, $pagination->page);
        self::assertSame(25, $pagination->pageSize);
        self::assertSame(50, $pagination->offset());
    }

    public function testAcceptsThePageSizeCeiling(): void
    {
        self::assertNotNull(Pagination::fromRequest(new Request(['pageSize' => '100'])));
        self::assertNotNull(Pagination::fromRequest(new Request(['pageSize' => '50']), maxPageSize: 50));
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('invalidQueries')]
    public function testRejectsInvalidParameters(array $query, int $maxPageSize = Pagination::MAX_PAGE_SIZE): void
    {
        self::assertNull(Pagination::fromRequest(new Request($query), maxPageSize: $maxPageSize));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1?: int}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'page à zéro' => [['page' => '0']];
        yield 'page négative' => [['page' => '-1']];
        yield 'page illisible' => [['page' => 'abc']];
        yield 'page décimale' => [['page' => '1.5']];
        yield 'page tableau' => [['page' => ['1']]];
        yield 'pageSize à zéro' => [['pageSize' => '0']];
        yield 'pageSize illisible' => [['pageSize' => 'abc']];
        yield 'pageSize au-dessus du plafond' => [['pageSize' => '101']];
        yield 'pageSize au-dessus d\'un plafond plus bas' => [['pageSize' => '51'], 50];
    }

    public function testInvalidMessageNamesTheCeiling(): void
    {
        self::assertStringContainsString('de 1 à 100', Pagination::invalidMessage());
        self::assertStringContainsString('de 1 à 50', Pagination::invalidMessage(50));
    }

    public function testPutsTheTotalOfAllPagesInTheHeader(): void
    {
        $pagination = Pagination::fromRequest(new Request(['page' => '2', 'pageSize' => '10']));

        $response = $pagination->withTotal(new JsonResponse([]), 37);

        self::assertSame('37', $response->headers->get('X-Total-Count'));
    }
}
