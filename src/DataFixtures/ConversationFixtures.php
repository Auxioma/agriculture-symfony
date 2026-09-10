<?php

/**
 * Conversations + messages entre un client et le producteur retenu sur sa demande -- $status parcourt Open
 * et Reported (comme ClientRequestFixtures) pour que le compteur "conversations signalées" de
 * DashboardController::index() ne soit pas nul. Une ClientRequest a ici toujours exactement une
 * Conversation, alors qu'en usage réel une conversation ne se crée qu'après un premier message échangé
 * (ConversationController::sendMessage()) -- simplification volontaire pour une base de démo.
 */

namespace App\DataFixtures;

use App\Entity\Identity\User;
use App\Entity\Matching\ClientRequest;
use App\Entity\Messaging\Conversation;
use App\Entity\Messaging\Message;
use App\Entity\Producer\ProducerProfile;
use App\Enum\ConversationStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;

class ConversationFixtures extends Fixture implements DependentFixtureInterface
{
    public const CONVERSATION_REFERENCE_PREFIX = 'conversation-';

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        for ($i = 0; $i < UserFixtures::CLIENT_COUNT; ++$i) {
            $request = $this->getReference('client-request-'.$i, ClientRequest::class);
            $client = $this->getReference(UserFixtures::CLIENT_REFERENCE_PREFIX.$i, User::class);
            $producer = $this->getReference(
                ProducerFixtures::PRODUCER_PROFILE_REFERENCE_PREFIX.($i % UserFixtures::PRODUCER_OWNER_COUNT),
                ProducerProfile::class
            );

            $conversation = new Conversation();
            $conversation->setRequest($request);
            $conversation->setClient($client);
            $conversation->setProducer($producer);
            // * Une conversation sur cinq signalée, pour peupler le compteur du dashboard.
            $conversation->setStatus(0 === $i % 5 ? ConversationStatus::Reported : ConversationStatus::Open);
            $conversation->setLastMessageAt(new \DateTimeImmutable('-2 hours'));
            $manager->persist($conversation);
            $this->addReference(self::CONVERSATION_REFERENCE_PREFIX.$i, $conversation);

            $clientMessage = new Message();
            $clientMessage->setConversation($conversation);
            $clientMessage->setSender($client);
            $clientMessage->setContent($faker->sentence(15));
            $manager->persist($clientMessage);

            $producerReply = new Message();
            $producerReply->setConversation($conversation);
            $producerReply->setSender($producer->getOwner());
            $producerReply->setContent($faker->sentence(20));
            $manager->persist($producerReply);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [ClientRequestFixtures::class, ProducerFixtures::class, UserFixtures::class];
    }
}
