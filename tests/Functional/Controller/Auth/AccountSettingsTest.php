<?php

namespace App\Tests\Functional\Controller\Auth;

use App\Entity\Identity\RefreshToken;
use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Teste GET et PUT /api/me/preferences, et PUT /api/me/password (cahier fonctionnel : Paramètres, « notifications, langue,
 * devise, sécurité » ; MPD : identity.user_preferences).
 */
final class AccountSettingsTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private const PASSWORD = 'motdepasse123';

    private function login(User $user, string $password = self::PASSWORD): string
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $user->getEmail(),
            'password' => $password,
        ]));

        return json_decode($this->client->getResponse()->getContent(), true)['token'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function put(string $url, string $token, array $body): void
    {
        $this->client->request('PUT', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode($body));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function preferenceRow(User $user): array|false
    {
        return $this->em->getConnection()->fetchAssociative('SELECT locale, currency, notification_settings FROM identity.user_preferences WHERE user_id = :id', ['id' => $user->getId()->toRfc4122()]);
    }

    public function testWithoutAnyChoiceTheAccountLocaleEuroAndEveryEmailAreGiven(): void
    {
        $user = $this->makeUserWithPassword('farmer', self::PASSWORD);
        $this->em->flush();

        $this->client->request('GET', '/api/me/preferences', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->login($user)]);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertSame('fr', $data['locale']);
        self::assertSame('EUR', $data['currency']);
        self::assertSame(['new_relevant_request' => true, 'payment_failed' => true, 'subscription_renewing_soon' => true], $data['emailNotifications']);
        // * l'adresse du support vient des réglages de la plateforme (admin), pas du front
        self::assertSame('support@trouvemoi.com', $data['supportEmail']);
        self::assertFalse($this->preferenceRow($user));
    }

    public function testThePreferencesNeedAnAuthenticatedAccount(): void
    {
        $this->client->request('GET', '/api/me/preferences');
        self::assertResponseStatusCodeSame(401);

        $this->client->request('PUT', '/api/me/preferences', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(401);
    }

    public function testEachSectionSavesAloneAndKeepsWhatWasNotSent(): void
    {
        $user = $this->makeUserWithPassword('farmer', self::PASSWORD);
        $this->em->flush();
        $token = $this->login($user);

        $this->put('/api/me/preferences', $token, ['currency' => 'CHF']);
        self::assertResponseIsSuccessful();
        $this->put('/api/me/preferences', $token, ['emailNotifications' => ['payment_failed' => false]]);
        self::assertResponseIsSuccessful();
        // * le second enregistrement ne reprend pas les trois interrupteurs : les deux autres restent à leur état
        $this->put('/api/me/preferences', $token, ['emailNotifications' => ['subscription_renewing_soon' => false]]);

        $data = $this->json();
        self::assertSame('CHF', $data['currency']);
        self::assertSame('fr', $data['locale']);
        self::assertSame(['new_relevant_request' => true, 'payment_failed' => false, 'subscription_renewing_soon' => false], $data['emailNotifications']);

        $this->client->request('GET', '/api/me/preferences', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertSame($data, $this->json());
        $row = $this->preferenceRow($user);
        self::assertSame('CHF', $row['currency']);
        self::assertSame(['email' => ['new_relevant_request' => true, 'payment_failed' => false, 'subscription_renewing_soon' => false]], json_decode($row['notification_settings'], true));
    }

    public function testTheLanguageIsKeptOnThePreferencesAndOnTheAccount(): void
    {
        $user = $this->makeUserWithPassword('farmer', self::PASSWORD);
        $this->em->flush();

        $this->put('/api/me/preferences', $this->login($user), ['locale' => 'de']);

        self::assertResponseIsSuccessful();
        self::assertSame('de', $this->json()['locale']);
        self::assertSame('de', $this->preferenceRow($user)['locale']);
        self::assertSame('de', $this->em->getConnection()->fetchOne('SELECT locale FROM identity.users WHERE id = :id', ['id' => $user->getId()->toRfc4122()]));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function badPreferences(): iterable
    {
        yield 'langue inconnue' => [['locale' => 'jp']];
        yield 'devise inconnue' => [['currency' => 'JPY']];
        yield 'devise en minuscules' => [['currency' => 'eur']];
        yield 'notification inconnue' => [['emailNotifications' => ['new_message' => false]]];
        yield 'interrupteur qui n’est pas un booléen' => [['emailNotifications' => ['payment_failed' => 'non']]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('badPreferences')]
    public function testUnknownValuesAreRefusedAndNothingIsSaved(array $body): void
    {
        $user = $this->makeUserWithPassword('farmer', self::PASSWORD);
        $this->em->flush();

        $this->put('/api/me/preferences', $this->login($user), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->preferenceRow($user));
    }

    public function testThePasswordIsChangedAndTheOpenSessionsAreClosed(): void
    {
        $user = $this->makeUserWithPassword('farmer', self::PASSWORD);
        $session = new RefreshToken();
        $session->setIdUser($user);
        $session->setTokenHash('hash-de-session');
        $session->setExpiresAt(new \DateTimeImmutable('+7 days'));
        $this->em->persist($session);
        $this->em->flush();
        $token = $this->login($user);

        $this->put('/api/me/password', $token, ['currentPassword' => self::PASSWORD, 'newPassword' => 'un-tout-nouveau-mdp']);

        self::assertResponseStatusCodeSame(204);
        // * le nouveau mot de passe ouvre une session, l'ancien non
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => 'un-tout-nouveau-mdp']));
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM identity.refresh_tokens WHERE user_id = :id AND revoked_at IS NULL', ['id' => $user->getId()->toRfc4122()]));
        // * tracé dans l'audit, sans mot de passe
        $details = $this->em->getConnection()->fetchOne("SELECT coalesce(old_data::text, '') || coalesce(new_data::text, '') FROM audit.audit_logs WHERE action = 'password_changed' AND record_id = :id", ['id' => $user->getId()->toRfc4122()]);
        self::assertNotFalse($details);
        self::assertStringNotContainsString('tout-nouveau', (string) $details);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badPasswordChanges(): iterable
    {
        yield 'mot de passe actuel faux' => [['currentPassword' => 'pas-le-bon', 'newPassword' => 'un-tout-nouveau-mdp'], 'Mot de passe actuel incorrect.'];
        yield 'nouveau trop court' => [['currentPassword' => self::PASSWORD, 'newPassword' => 'court'], ''];
        yield 'nouveau identique' => [['currentPassword' => self::PASSWORD, 'newPassword' => self::PASSWORD], "Le nouveau mot de passe doit être différent de l'actuel."];
        yield 'mot de passe actuel vide' => [['currentPassword' => '', 'newPassword' => 'un-tout-nouveau-mdp'], ''];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('badPasswordChanges')]
    public function testABadChangeIsRefusedAndTheOldPasswordStillWorks(array $body, string $message): void
    {
        $user = $this->makeUserWithPassword('farmer', self::PASSWORD);
        $this->em->flush();
        $token = $this->login($user);

        $this->put('/api/me/password', $token, $body);

        self::assertResponseStatusCodeSame(422);
        if ($message !== '') {
            self::assertSame($message, $this->json()['error']);
        }
        $this->login($user);
        self::assertResponseIsSuccessful();
    }

    public function testThePasswordConfirmationIsLimitedToFiveTriesPerAccount(): void
    {
        $user = $this->makeUserWithPassword('farmer', self::PASSWORD);
        $other = $this->makeUserWithPassword('autre', self::PASSWORD);
        $this->em->flush();
        $token = $this->login($user);

        for ($try = 1; $try <= 5; ++$try) {
            $this->put('/api/me/password', $token, ['currentPassword' => 'pas-le-bon', 'newPassword' => 'un-tout-nouveau-mdp']);
            self::assertResponseStatusCodeSame(422, 'essai '.$try);
        }
        // * le sixième est refusé même avec le bon mot de passe : on ne peut pas deviner à l'infini
        $this->put('/api/me/password', $token, ['currentPassword' => self::PASSWORD, 'newPassword' => 'un-tout-nouveau-mdp']);
        self::assertResponseStatusCodeSame(429);

        // * le plafond est propre à chaque compte
        $this->put('/api/me/password', $this->login($other), ['currentPassword' => self::PASSWORD, 'newPassword' => 'un-tout-nouveau-mdp']);
        self::assertResponseStatusCodeSame(204);
    }
}
