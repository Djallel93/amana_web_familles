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

# One-off: docker/mysql/init.sql only runs on first volume creation, so an
# existing `db_data` volume (created before amana_familles_test/amana_commun_test
# existed) won't have them. Safe to re-run any time — CREATE DATABASE IF NOT EXISTS.
test-db-init:
	docker compose exec mysql mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS amana_familles_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS amana_commun_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON amana_familles_test.* TO 'root'@'%'; GRANT ALL PRIVILEGES ON amana_commun_test.* TO 'root'@'%'; FLUSH PRIVILEGES;"

fresh:
	docker compose exec app php artisan migrate:fresh --seed

%:
	@:
