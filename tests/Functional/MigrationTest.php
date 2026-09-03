<?php

namespace App\Tests\Functional;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Doctrine\DBAL\Tools\DsnParser;

/**
 *  Vérifie que la migration (migrations/Version20260901130245.php) reste exécutable sur une base vide.
 *  Tous les autres tests supposent app_test déjà migrée ; celui-ci rejoue la migration elle-même sur une
 *  base jetable, comme le ferait un vrai déploiement, pour attraper une régression avant qu'elle n'arrive en prod.
 */
final class MigrationTest extends TestCase
{
    private const DB_NAME = 'app_migration_check';

    public function testMigrationsRunCleanlyOnAnEmptyDatabase(): void
    {
        $baseUrl = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        self::assertNotFalse($baseUrl, 'DATABASE_URL doit être défini (.env.test.local)');

        $parts = parse_url($baseUrl);
        $adminUrl = sprintf('postgresql://%s:%s@%s:%d/postgres', $parts['user'], $parts['pass'], $parts['host'], $parts['port']);
        $checkUrl = sprintf('postgresql://%s:%s@%s:%d/%s?serverVersion=18.6&charset=utf8', $parts['user'], $parts['pass'], $parts['host'], $parts['port'], self::DB_NAME);

        // * Base jetable : on ne peut pas la (re)créer en étant connecté dessus, d'où la connexion admin à part.
        $admin = $this->connect($adminUrl);
        $admin->executeStatement('DROP DATABASE IF EXISTS '.self::DB_NAME);
        $admin->executeStatement('CREATE DATABASE '.self::DB_NAME);
        $admin->close();

        // ! Sans variables_order=EGPCS + SYMFONY_DOTENV_VARS vidé, le Dotenv du sous-processus écrase silencieusement
        // ! notre DATABASE_URL avec celui de .env.test.local (il se croit autorisé à "rafraîchir" une variable
        // !qu'un Dotenv parent a déjà chargée). APP_ENV=dev évite en plus le dbname_suffix ("_test") de
        // ! config/packages/doctrine.yaml, qui rajouterait un suffixe à ce nom de base jetable.
        $env = [
            'DATABASE_URL' => $checkUrl,
            'APP_ENV' => 'dev',
            'SYMFONY_DOTENV_VARS' => '',
        ] + $_ENV;

        $process = new Process(
            ['php', '-d', 'variables_order=EGPCS', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'],
            dirname(__DIR__, 2),
            $env
        );

        $process->setTimeout(120);
        $process->run();

        self::assertTrue($process->isSuccessful(), "La migration a échoué sur une base vide :\n".$process->getErrorOutput());

        $connection = $this->connect($checkUrl);
        $tableCount = (int) $connection->fetchOne(
            "SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog', 'information_schema')"
        );

        // ? 78 = 73 entités + messenger_messages (74, notre migration) + doctrine_migration_versions (1, suivi
        // ? interne de l'outil Migrations) + spatial_ref_sys, geography_columns, geometry_columns (3, créés
        // ? automatiquement par CREATE EXTENSION postgis).
        self::assertSame(78, $tableCount, "les 73 entités + messenger_messages + les objets système créés par les migrations/extensions doivent tous exister après la migration");
        $connection->close();
    }

    protected function tearDown(): void
    {
        $baseUrl = $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        $parts = parse_url($baseUrl);
        $adminUrl = sprintf('postgresql://%s:%s@%s:%d/postgres', $parts['user'], $parts['pass'], $parts['host'], $parts['port']);

        // *On ne peut pas drop la base sur laquelle on est connecté, donc on se connecte à postgres pour tuer les connexions
        $admin = $this->connect($adminUrl);
        $admin->executeStatement(sprintf(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '%s' AND pid <> pg_backend_pid()",
            self::DB_NAME
        ));
        $admin->executeStatement('DROP DATABASE IF EXISTS '.self::DB_NAME);
        $admin->close();

        parent::tearDown();
    }

        private function connect(string $dsn): \Doctrine\DBAL\Connection
    {
        static $dsnParser = null;
        $dsnParser ??= new DsnParser(['postgresql' => 'pdo_pgsql']);

        return DriverManager::getConnection($dsnParser->parse($dsn));
    }
}