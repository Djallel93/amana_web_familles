# Convenience wrappers around docker compose. Run from a WSL2/Ubuntu shell
# on Windows, or a native terminal on Pop!_OS — commands are identical on
# both. Not required: every target here is just a shorter alias for a
# `docker compose ...` command you can also type out by hand.

up:
	docker compose up -d --build

down:
	docker compose down

worker:
	docker compose --profile worker up -d

logs:
	docker compose logs -f app vite

shell:
	docker compose exec app bash

artisan:
	docker compose exec app php artisan $(filter-out $@,$(MAKECMDGOALS))

composer:
	docker compose exec app composer $(filter-out $@,$(MAKECMDGOALS))

npm:
	docker compose exec vite npm $(filter-out $@,$(MAKECMDGOALS))

test:
	docker compose exec app php artisan test

fresh:
	docker compose exec app php artisan migrate:fresh --seed

%:
	@:
