.PHONY: up install migrate seed test shell down

up:
	docker compose up -d
	docker compose exec app composer install
	docker compose exec app cp -n .env.example .env || true
	docker compose exec app php artisan key:generate
	docker compose exec app php artisan migrate --seed

install:
	docker compose exec app composer install

migrate:
	docker compose exec app php artisan migrate:fresh --seed

test:
	docker compose exec app php artisan test

shell:
	docker compose exec app bash

down:
	docker compose down -v
