# TrouveMoi Agri — API & Back-office Symfony

Plateforme SaaS de mise en relation entre producteurs agricoles et clients.
Ce dépôt contient l'API REST et le back-office Symfony (EasyAdmin). Le front Angular vit dans un dépôt séparé.

## Stack

- PHP 8.4+, Symfony 8.1
- PostgreSQL 16 + PostGIS
- Stockage objet compatible S3 (adaptateur disque local en développement/tests)
- Stripe (abonnements producteurs)
- JWT (Lexik) pour l'authentification API

## Prérequis

- PHP 8.4+ avec Composer
- PostgreSQL 16 + PostGIS installés nativement et démarrés (ce projet utilise pgAdmin4 pour l'administration)
- `make`
- Symfony CLI (pour `symfony server:start`)

## Installation locale

```bash
git clone <url-du-dépôt>
cd agriculture-symfony
make setup
make up
```

`make setup` installe les dépendances, crée `.env.local` si absent (à compléter),
génère les clés JWT si nécessaire, crée la base et joue les migrations.
`make up` démarre le serveur Symfony local.

Le stockage de fichiers (photos, pièces jointes) et les emails n'utilisent aucun service
externe en local : adaptateur disque local (`when@dev` dans `config/packages/flysystem.yaml`)
et emails non envoyés (visibles dans le profiler Symfony, `/_profiler`, une fois branchés).

## Commandes

| Commande | Effet |
|---|---|
| `make setup` | Installation complète depuis zéro |
| `make up` / `make down` | Démarre / arrête le serveur Symfony local |
| `make db-reset` | Recrée la base et rejoue les migrations |
| `make test` | Lance la suite PHPUnit |
| `make quality` | PHP-CS-Fixer + PHPStan |
| `make logs` | Logs applicatifs (`var/log/dev.log`) |
| `make backup` | Sauvegarde PostgreSQL manuelle (nécessite les mêmes credentials que le serveur, voir [runbook](docs/runbooks/backup.md)) |

## Conventions

- Style de code : PHP-CS-Fixer (config dans `.php-cs-fixer.dist.php`), à lancer avant chaque commit.
- Analyse statique : PHPStan (config dans `phpstan.dist.neon`, baseline dans `phpstan-baseline.neon`).
- Tests : `tests/Functional/Controller/<Domaine>/` pour les tests HTTP bout-en-bout,
  `tests/Fixtures/EntityFactoryTrait.php` pour les fixtures réutilisables.
- Migrations Doctrine : une migration par fonctionnalité, jamais éditée après merge sur `master`.

## Dépannage rapide

- **`JWTDecodeFailureException` ou erreurs liées au firewall API** : les clés JWT ne sont pas
  générées → `php bin/console lexik:jwt:generate-keypair --no-interaction`.
- **Erreurs de connexion à la base** : vérifie que le service PostgreSQL tourne
  (visible dans pgAdmin4) et que `DATABASE_URL` dans `.env.local` pointe dessus.
- **Photos/pièces jointes introuvables en local** : normal, elles sont écrites dans
  `var/storage/dev*` (gitignoré), pas sur un vrai stockage S3.
- **`symfony server:start` échoue sur un verrou de fichier log (Windows)** : repli sans
  Symfony CLI → `php -S 127.0.0.1:8000 -t public`.

## Liens

- Cahiers des charges (fonctionnel et DevOps) : hors dépôt, demander l'accès à l'équipe projet.
- [Runbook sauvegardes PostgreSQL](docs/runbooks/backup.md) : fonctionnement, restauration en cas d'incident, secrets requis.