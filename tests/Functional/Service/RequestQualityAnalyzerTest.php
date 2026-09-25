<?php

namespace App\Tests\Functional\Service;

use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Enum\RequestStatus;
use App\Service\Matching\RequestQualityAnalyzer;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;

/**
 * Teste la détection de doublons et de spam (cahier fonctionnel, Back-office "Demandes" : "doublons, spam").
 * Les seuils sont ceux de RequestQualityAnalyzer (choix à valider avec le client, pas des valeurs du cahier) : les
 * tests documentent surtout ce qui ne doit PAS être signalé (récurrences, autre lieu, autre client, demande clôturée).
 */
final class RequestQualityAnalyzerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private RequestQualityAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new RequestQualityAnalyzer($this->em->getConnection());
    }

    private function request(User $client, string $what = 'Pommes bio', string $city = 'Rennes', string $hoursAgo = '0', ?string $message = null, RequestStatus $status = RequestStatus::Sent): ClientRequest
    {
        $request = $this->makeClientRequest($client, null, status: $status);
        $request->setCustomProduct($what);
        $request->setCity($city);
        $request->setMessage($message);
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            "UPDATE matching.client_requests SET created_at = now() - (:hours || ' hours')::interval WHERE id = :id",
            ['hours' => $hoursAgo, 'id' => $request->getId()->toRfc4122()]
        );

        return $request;
    }

    /**
     * @param list<ClientRequest> $requests
     *
     * @return array<string, list<string>>
     */
    private function signals(array $requests): array
    {
        return $this->analyzer->signalsFor(array_map(static fn (ClientRequest $r) => $r->getId()->toRfc4122(), $requests));
    }

    private function id(ClientRequest $request): string
    {
        return $request->getId()->toRfc4122();
    }

    public function testTheNewerCopyIsADuplicateAndTheOriginalIsNot(): void
    {
        $client = $this->makeUser('dup');
        $original = $this->request($client, hoursAgo: '5');
        $copy = $this->request($client, hoursAgo: '1');
        $this->em->flush();

        $signals = $this->signals([$original, $copy]);

        self::assertSame([RequestQualityAnalyzer::SIGNAL_DUPLICATE], $signals[$this->id($copy)]);
        self::assertArrayNotHasKey($this->id($original), $signals);
        self::assertSame([$this->id($original)], $this->analyzer->duplicatesOf([$this->id($copy)])[$this->id($copy)]);
    }

    // * created_at n'a que la seconde de précision : un double clic produit deux demandes à la même seconde exacte.
    // * Exactement UNE des deux doit être signalée (l'autre est l'original), pas zéro ni deux.
    public function testTwoIdenticalRequestsInTheSameSecondFlagExactlyOne(): void
    {
        $client = $this->makeUser('doubleclick');
        $first = $this->request($client, hoursAgo: '1');
        $second = $this->request($client, hoursAgo: '1');
        $this->em->getConnection()->executeStatement(
            'UPDATE matching.client_requests SET created_at = (SELECT created_at FROM matching.client_requests WHERE id = :first) WHERE id = :second',
            ['first' => $this->id($first), 'second' => $this->id($second)]
        );

        $signals = $this->signals([$first, $second]);

        self::assertCount(1, $signals);
        self::assertSame([RequestQualityAnalyzer::SIGNAL_DUPLICATE], array_values($signals)[0]);
    }

    public function testDuplicateComparisonIgnoresCaseAndSurroundingSpaces(): void
    {
        $client = $this->makeUser('dupcase');
        $this->request($client, 'Pommes bio', 'Rennes', '3');
        $copy = $this->request($client, '  POMMES BIO ', ' rennes', '1');

        self::assertContains(RequestQualityAnalyzer::SIGNAL_DUPLICATE, $this->signals([$copy])[$this->id($copy)] ?? []);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function notDuplicates(): iterable
    {
        yield 'autre lieu' => [['city' => 'Nantes']];
        yield 'autre produit' => [['what' => 'Poires']];
        yield 'plus de 48 h d\'écart' => [['originalHoursAgo' => '100']];
        // * Récurrence hebdomadaire (la plus courte, RecurrenceFrequency::Weekly) : jamais un doublon.
        yield 'récurrence hebdomadaire' => [['originalHoursAgo' => '168']];
        yield 'original annulé' => [['originalStatus' => RequestStatus::Cancelled]];
        yield 'original brouillon' => [['originalStatus' => RequestStatus::Draft]];
    }

    /**
     * @param array<string, mixed> $variant
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('notDuplicates')]
    public function testSimilarButLegitimateRequestsAreNotDuplicates(array $variant): void
    {
        $client = $this->makeUser('nodup');
        $this->request($client, 'Pommes bio', 'Rennes', $variant['originalHoursAgo'] ?? '5', status: $variant['originalStatus'] ?? RequestStatus::Sent);
        $other = $this->request($client, $variant['what'] ?? 'Pommes bio', $variant['city'] ?? 'Rennes', '1');

        self::assertArrayNotHasKey($this->id($other), $this->signals([$other]));
    }

    public function testTheSameRequestFromAnotherClientIsNotADuplicate(): void
    {
        $this->request($this->makeUser('a'), hoursAgo: '5');
        $other = $this->request($this->makeUser('b'), hoursAgo: '1');

        self::assertSame([], $this->signals([$other]));
    }

    public function testFifthRequestInADayIsFloodButTheFirstFourAreNot(): void
    {
        $client = $this->makeUser('flood');
        $requests = [];
        foreach ([10, 8, 6, 4, 2] as $i => $hoursAgo) {
            $requests[] = $this->request($client, 'Produit '.$i, 'Rennes', (string) $hoursAgo);
        }

        $signals = $this->signals($requests);

        self::assertSame([RequestQualityAnalyzer::SIGNAL_FLOOD], $signals[$this->id($requests[4])]);
        for ($i = 0; $i < 4; ++$i) {
            self::assertArrayNotHasKey($this->id($requests[$i]), $signals);
        }
    }

    public function testRequestsSpreadOverSeveralDaysAreNotFlood(): void
    {
        $client = $this->makeUser('spread');
        $requests = [];
        foreach ([120, 96, 72, 48, 1] as $i => $hoursAgo) {
            $requests[] = $this->request($client, 'Produit '.$i, 'Rennes', (string) $hoursAgo);
        }

        self::assertSame([], $this->signals($requests));
    }

    public function testLinksInTheMessageOrTheFreeProductAreSpamButPlainTextIsNot(): void
    {
        $client = $this->makeUser('links');
        $http = $this->request($client, 'Produit A', message: 'Achetez sur https://promo.example maintenant');
        $www = $this->request($client, 'Produit B', message: 'Voir WWW.exemple.com pour les offres');
        $product = $this->request($client, 'http://exemple.com/pas-un-produit');
        $plain = $this->request($client, 'Produit D', message: 'Je cherche des pommes, pas de site web, https est un protocole.');

        $signals = $this->signals([$http, $www, $product, $plain]);

        foreach ([$http, $www, $product] as $flagged) {
            self::assertSame([RequestQualityAnalyzer::SIGNAL_LINK], $signals[$this->id($flagged)]);
        }
        self::assertArrayNotHasKey($this->id($plain), $signals);
    }

    public function testTheSameLongMessageFromThreeClientsIsAMassMessage(): void
    {
        $text = 'Nous vendons des produits pas chers, contactez le 06 00 00 00 00';
        $a = $this->request($this->makeUser('m1'), 'Produit A', message: $text);
        $b = $this->request($this->makeUser('m2'), 'Produit B', message: strtoupper($text));
        $c = $this->request($this->makeUser('m3'), 'Produit C', message: "  Nous vendons   des produits pas chers,\ncontactez le 06 00 00 00 00 ");

        $signals = $this->signals([$a, $b, $c]);

        foreach ([$a, $b, $c] as $request) {
            self::assertSame([RequestQualityAnalyzer::SIGNAL_MASS_MESSAGE], $signals[$this->id($request)]);
        }
    }

    public function testTwoClientsOrAShortMessageAreNotAMassMessage(): void
    {
        $long = 'Nous cherchons un producteur de pommes de terre bio près de Rennes';
        $a = $this->request($this->makeUser('n1'), 'Produit A', message: $long);
        $b = $this->request($this->makeUser('n2'), 'Produit B', message: $long);
        $short = [];
        foreach (['s1', 's2', 's3'] as $prefix) {
            $short[] = $this->request($this->makeUser($prefix), 'Produit '.$prefix, message: 'Bonjour');
        }

        self::assertSame([], $this->signals([$a, $b, ...$short]));
    }

    public function testRequestsNoLongerInCirculationAreNeverFlagged(): void
    {
        $client = $this->makeUser('gone');
        $cancelled = $this->request($client, message: 'https://spam.example', status: RequestStatus::Cancelled);
        $archived = $this->request($client, 'Autre', message: 'www.spam.example', status: RequestStatus::Archived);
        $draft = $this->request($client, 'Brouillon', message: 'https://brouillon.example', status: RequestStatus::Draft);

        self::assertSame([], $this->signals([$cancelled, $archived, $draft]));
    }

    public function testSeveralSignalsCanAccumulateOnOneRequest(): void
    {
        $client = $this->makeUser('multi');
        $this->request($client, hoursAgo: '5');
        $both = $this->request($client, hoursAgo: '1', message: 'Voir https://spam.example');

        self::assertEqualsCanonicalizing(
            [RequestQualityAnalyzer::SIGNAL_DUPLICATE, RequestQualityAnalyzer::SIGNAL_LINK],
            $this->signals([$both])[$this->id($both)]
        );
    }

    public function testFlaggedIdsCoversEveryRequestAndSignalsForIsScopedToTheGivenIds(): void
    {
        $client = $this->makeUser('scope');
        $spam = $this->request($client, 'Produit A', message: 'https://spam.example');
        $clean = $this->request($this->makeUser('clean'), 'Produit B', message: 'Je cherche des pommes');

        self::assertContains($this->id($spam), $this->analyzer->flaggedIds());
        self::assertNotContains($this->id($clean), $this->analyzer->flaggedIds());
        self::assertSame([], $this->analyzer->signalsFor([$this->id($clean)]));
        self::assertSame([], $this->analyzer->signalsFor([]));
        self::assertSame([], $this->analyzer->duplicatesOf([]));
    }
}
