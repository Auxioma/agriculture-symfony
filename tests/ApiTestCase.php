<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Classe de base pour les tests qui appellent l'API HTTP en conditions réelles (KernelBrowser), par
 * opposition à DatabaseTestCase qui teste directement l'ORM/la base sans passer par une requête HTTP.
 * Même principe transactionnel que DatabaseTestCase (setUp ouvre une transaction, tearDown l'annule),
 * plus le client HTTP et les helpers d'authentification partagés par tous les tests d'API.
 */

abstract class ApiTestCase extends WebTestCase
{
    use GeographyTestHelperTrait;

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // ! KernelBrowser reboote le kernel entre chaque requête par défaut. Avec nos firewalls stateless
        // ! (api_login + api), ce reboot fait perdre le bon contexte de sécurité d'une requête à l'autre
        // ! (un register-client suivi d'un login dans le même test retombait sur le mauvais firewall).
        // ! disableReboot() garde le même kernel pour toute la durée du test.
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    // * Déplacé depuis AuthControllerTest (était privé) : une méthode privée n'est visible que dans sa
    // * propre classe, donc ClientRequestControllerTest (et tout futur test d'API authentifié) ne pouvait
    // * pas s'en servir sans la dupliquer. Ici, elle est héritée automatiquement par toute classe qui
    // * étend ApiTestCase, comme EntityFactoryTrait fournit ses fixtures à tous les tests qui l'utilisent.
    protected function registerClientAndLogin(string $emailPrefix = 'client'): string
    {
        $email = $emailPrefix.'_'.bin2hex(random_bytes(4)).'@test.local';
        $password = 'motdepasse123';

        $this->client->request('POST', '/api/auth/register-client', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => $password,
            'firstName' => 'Jean',
            'lastName' => 'Dupont',
        ]));

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => $password,
        ]));

        $data = json_decode($this->client->getResponse()->getContent(), true);

        return $data['token'];
    }
}
