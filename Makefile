.PHONY: setup up down db-reset test quality logs

setup: ## Installe les dépendances et prépare l'environnement local
	composer install
	@if [ ! -f .env.local ]; then cp .env .env.local; echo "-> .env.local créé, complète-le avec tes vraies valeurs"; fi
	@if [ ! -f config/jwt/private.pem ]; then php bin/console lexik:jwt:generate-keypair --no-interaction; fi
	docker compose up -d

up: ## Démarre les services locaux (Postgres, MinIO, Mailpit)
	docker compose up -d

down: ## Arrête les services locaux
	docker compose down

db-reset: ## Recrée la base locale et rejoue toutes les migrations
	php bin/console doctrine:database:drop --force --if-exists
	php bin/console doctrine:database:create
	php bin/console doctrine:migrations:migrate --no-interaction

test: ## Lance la suite de tests
	php bin/phpunit

quality: ## Lint + format + analyse statique
	vendor/bin/php-cs-fixer fix
	vendor/bin/phpstan analyse

logs: ## Affiche les logs des services locaux
	docker compose logs -f