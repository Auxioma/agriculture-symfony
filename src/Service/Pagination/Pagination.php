<?php

namespace App\Service\Pagination;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pagination des listes de l'API (cahier DevOps, sécurité de l'API : « pagination obligatoire »). Une page est toujours
 * appliquée, même si le client n'envoie rien : ?page=<entier ≥ 1> (défaut 1) et ?pageSize=<1 à $maxPageSize> (défaut
 * $defaultPageSize, propre à chaque liste).
 *
 * Le corps de la réponse reste un tableau (les consommateurs d'avant ne cassent pas) ; le total de TOUTES les pages voyage
 * dans l'en-tête X-Total-Count, que SecurityHeadersListener expose au navigateur. Sans en-tête le front sait que la liste tient
 * en une page ; avec, il sait s'il reste des pages à charger (voir core/http/paged.ts côté Angular).
 */
final readonly class Pagination
{
    public const DEFAULT_PAGE_SIZE = 20;
    public const MAX_PAGE_SIZE = 100;
    public const TOTAL_HEADER = 'X-Total-Count';

    private function __construct(
        public int $page,
        public int $pageSize,
    ) {
    }

    /**
     * null si "page" ou "pageSize" est invalide : l'appelant répond 400 avec invalidMessage().
     * filter_var plutôt que getInt() : « abc » (ou « page[]=1 ») doit donner null, pas une exception.
     */
    public static function fromRequest(
        Request $request,
        int $defaultPageSize = self::DEFAULT_PAGE_SIZE,
        int $maxPageSize = self::MAX_PAGE_SIZE,
    ): ?self {
        // * all() et non get() : get() lève une exception sur une valeur tableau au lieu de la refuser proprement
        $query = $request->query->all();
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pageSize = filter_var(
            $query['pageSize'] ?? $defaultPageSize,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => $maxPageSize]]
        );

        return $page === false || $pageSize === false ? null : new self($page, $pageSize);
    }

    public static function invalidMessage(int $maxPageSize = self::MAX_PAGE_SIZE): string
    {
        return sprintf('Paramètres "page" (entier ≥ 1) et "pageSize" (entier de 1 à %d) invalides.', $maxPageSize);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }

    /**
     * Une page d'une requête Doctrine : [les éléments de la page, le total de toutes les pages].
     * Le Paginator compte avec la même requête, sans LIMIT ; fetchJoinCollection reste à false : les jointures
     * des listes de l'API sont toutes « vers un » (une ligne par élément), pas de collection à dédoublonner.
     *
     * @return array{0: list<object>, 1: int}
     */
    public function slice(QueryBuilder $queryBuilder): array
    {
        $query = $queryBuilder
            ->setFirstResult($this->offset())
            ->setMaxResults($this->pageSize)
            ->getQuery();
        $paginator = new Paginator($query, false);

        return [array_values(iterator_to_array($paginator)), count($paginator)];
    }

    /**
     * Pose le total de toutes les pages en en-tête de la réponse, et la rend : le type reste celui qu'on a donné (un JsonResponse
     * reste un JsonResponse, ce que les contrôleurs déclarent).
     *
     * @template T of Response
     *
     * @param T $response
     *
     * @return T
     */
    public function withTotal(Response $response, int $total): Response
    {
        $response->headers->set(self::TOTAL_HEADER, (string) $total);

        return $response;
    }
}
