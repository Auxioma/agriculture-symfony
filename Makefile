.PHONY: setup up down db-reset test quality logs

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