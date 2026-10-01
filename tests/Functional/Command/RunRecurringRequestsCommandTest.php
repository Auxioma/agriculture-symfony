<?php

namespace App\Tests\Functional\Command;

use App\Entity\Matching\RecurringRequestRule;
use App\Enum\RecurrenceFrequency;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Teste app:run-recurring-requests (cahier fonctionnel -- "demande récurrente", NeedType::Recurring) :
 * republication automatique via RecurringRequestRule, sur le même principe que SendExpiryRemindersCommandTest.
 */
final class RunRecurringRequestsCommandTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private function executeCommand(): CommandTester
    {
        $application = new Application(static::getContainer()->get('kernel'));
        $command = $application->find('app:run-recurring-requests');
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    public function testDueRuleRepublishesRequestAndNotifiesClientAndMatchedProducer(): void
    {
        $country = $this->makeCountry();
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $client = $this->makeUser('client');
        $request = $this->makeClientRequest($client, $product);
        $request->setLocation('SRID=4326;POINT(2.36 48.86)');

        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country);
        $this->makeProducerProduct($producer, $product, true);

        $rule = new RecurringRequestRule();
        $rule->setRequest($request);
        $rule->setFrequency(RecurrenceFrequency::Weekly);
        $rule->setNextRunAt(new \DateTimeImmutable('-1 hour'));
        $rule->setIsActive(true);
        $this->em->persist($rule);
        $this->em->flush();
        $this->setGeographyPoint('producer.producer_profiles', 'location', $producer->getId()->toRfc4122(), 2.35, 48.85);

        $this->executeCommand();

        $countRequests = (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM matching.client_requests WHERE client_id = :id',
            ['id' => $client->getId()->toRfc4122()]
        );
        self::assertSame(2, $countRequests, 'La demande originale + la republication automatique.');

        $requestSentCount = (int) $this->em->getConnection()->fetchOne(
            "SELECT count(*) FROM notification.notifications WHERE type = 'request_sent' AND user_id = :id",
            ['id' => $client->getId()->toRfc4122()]
        );
        self::assertSame(1, $requestSentCount);

        $producerNotificationCount = (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM notification.notifications WHERE type = :type AND user_id = :userId',
            ['type' => 'new_relevant_request', 'userId' => $producer->getOwner()->getId()->toRfc4122()]
        );
        self::assertSame(1, $producerNotificationCount);

        $this->em->refresh($rule);
        self::assertGreaterThan(new \DateTimeImmutable(), $rule->getNextRunAt());
        self::assertTrue($rule->isActive());
    }

    public function testRuleNotYetDueIsIgnored(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $client = $this->makeUser('client');
        $request = $this->makeClientRequest($client, $product);

        $rule = new RecurringRequestRule();
        $rule->setRequest($request);
        $rule->setFrequency(RecurrenceFrequency::Weekly);
        $rule->setNextRunAt(new \DateTimeImmutable('+1 day'));
        $rule->setIsActive(true);
        $this->em->persist($rule);
        $this->em->flush();

        $this->executeCommand();

        $countRequests = (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM matching.client_requests WHERE client_id = :id',
            ['id' => $client->getId()->toRfc4122()]
        );
        self::assertSame(1, $countRequests);
    }

    public function testRuleWithEndAtInThePastIsDeactivatedInsteadOfRepublishing(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $client = $this->makeUser('client');
        $request = $this->makeClientRequest($client, $product);

        $rule = new RecurringRequestRule();
        $rule->setRequest($request);
        $rule->setFrequency(RecurrenceFrequency::Weekly);
        $rule->setNextRunAt(new \DateTimeImmutable('-1 hour'));
        $rule->setEndAt(new \DateTimeImmutable('-1 day'));
        $rule->setIsActive(true);
        $this->em->persist($rule);
        $this->em->flush();

        $this->executeCommand();

        $countRequests = (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM matching.client_requests WHERE client_id = :id',
            ['id' => $client->getId()->toRfc4122()]
        );
        self::assertSame(1, $countRequests, 'Aucune republication : la règle a dépassé son endAt.');

        $this->em->refresh($rule);
        self::assertFalse($rule->isActive());
    }

    public function testInactiveRuleIsIgnored(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $client = $this->makeUser('client');
        $request = $this->makeClientRequest($client, $product);

        $rule = new RecurringRequestRule();
        $rule->setRequest($request);
        $rule->setFrequency(RecurrenceFrequency::Monthly);
        $rule->setNextRunAt(new \DateTimeImmutable('-1 hour'));
        $rule->setIsActive(false);
        $this->em->persist($rule);
        $this->em->flush();

        $this->executeCommand();

        $countRequests = (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM matching.client_requests WHERE client_id = :id',
            ['id' => $client->getId()->toRfc4122()]
        );
        self::assertSame(1, $countRequests);
    }
}
