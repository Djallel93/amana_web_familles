<?php
// tests/Concerns/MigratesCommunConnection.php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Illuminate\Foundation\Testing\RefreshDatabase only migrates and wraps in a
 * transaction the DEFAULT connection (config('database.default') = 'mysql',
 * this app's own tables) — see refreshTestDatabase()/connectionsToTransact()
 * on that trait. This app also needs the separate `commun` connection
 * (ref_personnes/ref_roles/ref_applications/ref_settings, owned by
 * amana_shared — see config/database.php and config/amana-shared.php)
 * migrated and rolled back the same way, since almost every authenticated
 * request touches it (auth guard, role checks, settings).
 *
 * Embeds RefreshDatabase itself (rather than being used alongside it) via
 * trait aliasing: both traits define refreshTestDatabase()/
 * connectionsToTransact(), which PHP treats as a fatal conflict — not an
 * override — if both are `use`d directly in the same class. Aliasing
 * RefreshDatabase's originals to a different method name here lets this
 * trait's own versions call them, then a single `use MigratesCommunConnection;`
 * in tests/TestCase.php is all that's needed (no separate `use
 * RefreshDatabase;` alongside it).
 *
 * amana_shared's own `amana:migrate-shared` artisan command is deliberately
 * manual-only in normal operation (see its docblock: "jamais appelée
 * automatiquement") — that restriction is about not running it as a side
 * effect of a routine deploy, it doesn't apply to a disposable test
 * database, so this calls `migrate:fresh --database=commun` directly against
 * the package's migrations path instead of going through that command.
 *
 * The commun migration itself runs once per test process (mirrors
 * RefreshDatabase's own RefreshDatabaseState::$migrated guard for the
 * default connection) — cheap to only do once since it's still wrapped in
 * the per-test transaction from connectionsToTransact() below.
 */
trait MigratesCommunConnection
{
    use RefreshDatabase {
        RefreshDatabase::refreshTestDatabase as private refreshDefaultConnection;
        RefreshDatabase::connectionsToTransact as private defaultConnectionsToTransact;
    }

    private static bool $communMigrated = false;

    protected function refreshTestDatabase(): void
    {
        if (! self::$communMigrated) {
            $this->artisan('migrate:fresh', [
                '--database' => config('amana-shared.connection', 'commun'),
                // Relative to base_path() (no --realpath), same convention
                // Laravel's own migrate command uses by default.
                '--path' => 'vendor/amana/shared/database/migrations',
            ]);

            self::$communMigrated = true;
        }

        $this->refreshDefaultConnection();
    }

    /**
     * @return array<int, string|null>
     */
    protected function connectionsToTransact(): array
    {
        return [
            ...$this->defaultConnectionsToTransact(),
            config('amana-shared.connection', 'commun'),
        ];
    }
}
