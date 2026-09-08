# TrouveMoi Agri — API & Back-office Symfony

Plateforme SaaS de mise en relation entre producteurs agricoles et clients.
Ce dépôt contient l'API REST et le back-office Symfony (EasyAdmin). Le front Angular vit dans un dépôt séparé.

## Stack

- PHP 8.4+, Symfony 8.1
- PostgreSQL 16 + PostGIS
- Stockage objet compatible S3 (MinIO en local)
- Stripe (abonnements producteurs)
- JWT (Lexik) pour l'authentification API

## Prérequis

- PHP 8.4+ avec Composer
- Docker (pour PostgreSQL, MinIO, Mailpit)
- `make` (voir plus bas si absent)
- Symfony CLI (optionnel, pour `symfony server:start`)

## Installation locale

\`\`\`bash
git clone <url-du-dépôt>
cd agriculture-symfony
make setup
\`\`\`

`make setup` installe les dépendances Composer, crée `.env.local` s'il n'existe pas encore
(à compléter avec tes propres valeurs : `STRIPE_SECRET_KEY`, `STORAGE_*`...), génère les clés
JWT si absentes, puis démarre PostgreSQL/MinIO/Mailpit via Docker.

Ensuite :

\`\`\`bash
make db-reset   # crée la base et joue les migrations
symfony server:start   # ou: php -S 127.0.0.1:8000 -t public
\`\`\`

## Commandes

| Commande | Effet |
|---|---|
| `make setup` | Installation complète depuis zéro |
| `make up` / `make down` | Démarre / arrête Postgres, MinIO, Mailpit |
| `make db-reset` | Recrée la base et rejoue les migrations |
| `make test` | Lance la suite PHPUnit |
| `make quality` | PHP-CS-Fixer + PHPStan |
| `make logs` | Logs des services Docker |

## Conventions

- Style de code : PHP-CS-Fixer (config dans `.php-cs-fixer.dist.php`), à lancer avant chaque commit.
- Analyse statique : PHPStan (config dans `phpstan.dist.neon`, baseline dans `phpstan-baseline.neon`).
- Tests : `tests/Functional/Controller/<Domaine>/` pour les tests HTTP bout-en-bout,
  `tests/Fixtures/EntityFactoryTrait.php` pour les fixtures réutilisables.
- Migrations Doctrine : une migration par fonctionnalité, jamais éditée après merge sur `master`.

## Dépannage rapide

- **`JWTDecodeFailureException` ou erreurs liées au firewall API** : les clés JWT ne sont pas
  générées → `php bin/console lexik:jwt:generate-keypair --no-interaction`.
- **Port 5432 déjà utilisé** : un autre PostgreSQL tourne déjà en local (WAMP, etc.) —
  arrête-le ou change le port exposé dans `compose.override.yaml`.
- **Mails de test invisibles** : ils partent vers Mailpit, pas une vraie boîte —
  interface sur `http://localhost:8025`.
- **Fichiers/photos introuvables en local** : console MinIO sur `http://localhost:9001`
  (identifiants par défaut dans `compose.yaml`).

## Liens

- Cahiers des charges (fonctionnel et DevOps) : hors dépôt, demander l'accès à l'équipe projet.