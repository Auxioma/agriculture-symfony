# -----------------------------------------------------------------------------
# COMMANDES DE DÉVELOPPEMENT ET D'EXÉCUTION
#
# Ce fichier centralise et automatise les tâches courantes du projet :
# 1. Initialisation de l'environnement local (install, clés JWT, migrations).
# 2. Gestion du serveur de développement Symfony (start/stop).
# 3. Réinitialisation rapide de la base de données.
# 4. Exécution de la suite de tests unitaires/intégration (PHPUnit).
# 5. Contrôle de la qualité du code (PHP-CS-Fixer, PHPStan).
# 6. Consultation des logs applicatifs et déclenchement des sauvegardes.
# -----------------------------------------------------------------------------

.PHONY: setup up down db-reset test quality logs backup

setup: ## Installe les dépendances et prépare l'environnement local
	composer install
	@if [ ! -f .env.local ]; then cp .env .env.local; echo "-> .env.local créé, complète-le (DATABASE_URL, STRIPE_*...)"; fi
	@if [ ! -f config/jwt/private.pem ]; then php bin/console lexik:jwt:generate-keypair --no-interaction; fi
	php bin/console doctrine:database:create --if-not-exists
	php bin/console doctrine:migrations:migrate --no-interaction

up: ## Démarre le serveur Symfony local (PostgreSQL tourne déjà en service natif)
	symfony server:start -d

down: ## Arrête le serveur Symfony local
	symfony server:stop

db-reset: ## Recrée la base locale et rejoue toutes les migrations
	php bin/console doctrine:database:drop --force --if-exists
	php bin/console doctrine:database:create
	php bin/console doctrine:migrations:migrate --no-interaction

test: ## Lance la suite de tests
	php bin/phpunit

quality: ## Lint + format + analyse statique
	vendor/bin/php-cs-fixer fix
	vendor/bin/phpstan analyse

logs: ## Affiche les logs applicatifs
	tail -f var/log/dev.log

backup: ## Lance une sauvegarde PostgreSQL manuelle (nécessite les mêmes credentials que le serveur)
	bash scripts/db/backup.sh