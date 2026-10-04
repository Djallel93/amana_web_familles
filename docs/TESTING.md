# Running the test suite

This app uses plain PHPUnit (no Pest) with two Eloquent connections — its
own `mysql` connection plus the shared `commun` connection (`amana/shared`'s
`ref_personnes`/`ref_roles`/`ref_settings`/...) — so the setup has a couple
of extra steps beyond a stock `php artisan test`. See `tests/TestCase.php`
and `tests/Concerns/MigratesCommunConnection.php` for why.

---

## 1. Prerequisites

You need a real MySQL (or MariaDB) database — **SQLite `:memory:` doesn't
work here**. Two reasons, both explained in `phpunit.xml`'s own comment:

- `App\Jobs\ResoudreAdresseFamille` and related geo-resolution logic use
  MySQL spatial functions (`ST_Contains`/`ST_GeomFromText`), which SQLite
  doesn't support.
- Almost every authenticated request touches the separate `commun`
  connection, which SQLite `:memory:` can't represent as a second real
  connection the way tests need.

Pick whichever of the two setups below matches how you actually run this
app day to day — both are first-class, neither is a fallback for the
other.

### Option A — Docker

If you're using the Docker setup (`docs/DOCKER.md`), you already have
this — the `mysql` service runs both `amana_familles`/`amana_commun` (dev)
and `amana_familles_test`/`amana_commun_test` (tests), created by
`docker/mysql/init.sql`. If your `mysql` container's data volume predates
this test setup, run once:

```bash
make test-db-init
```

(safe to re-run any time — `CREATE DATABASE IF NOT EXISTS`). Nothing else
to configure — your existing `.env` (pointed at the `mysql` service, per
`.env.docker.example`) already supplies the host/port/credentials that
`phpunit.xml` doesn't override (see its own comment: it only swaps the
database *names* to the `_test` ones).

### Option B — Native Linux (no Docker)

You need MySQL or MariaDB installed and running locally — e.g. on
Pop!\_OS/Ubuntu:

```bash
sudo apt install mysql-server
sudo systemctl enable --now mysql
```

Then create the two test databases (a one-time step, same idea as
`docker/mysql/init.sql` does for the Docker setup — just run directly
against your own server instead of a container):

```bash
sudo mysql -e "
  CREATE DATABASE IF NOT EXISTS amana_familles_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE DATABASE IF NOT EXISTS amana_commun_test   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
"
```

(Adjust user/auth to however your local MySQL is configured — the above
assumes the default `sudo mysql` socket-auth setup Ubuntu/Debian ship
with. If you instead run MySQL with a root password, drop `sudo` and add
`-u root -p`.)

Your `.env` needs the same variables it already needs for normal
`artisan` commands against your dev databases — `phpunit.xml` only
overrides `DB_CONNECTION`/`DB_DATABASE`/`DB_COMMUN_DATABASE` to the
`_test` ones, so as long as your existing `.env` already has working
`DB_HOST`/`DB_PORT`/`DB_USERNAME`/`DB_PASSWORD` and
`DB_COMMUN_HOST`/`DB_COMMUN_USERNAME`/`DB_COMMUN_PASSWORD` (see
`config/database.php` for the full list of `commun`-connection keys),
there's nothing extra to set for tests specifically. If you don't have a
`.env` with those yet, base it on `.env.docker.example`'s `DB_*`/
`DB_COMMUN_*` block but with `DB_HOST=127.0.0.1`/`DB_COMMUN_HOST=127.0.0.1`
(no `mysql` service hostname to resolve outside Docker) and whatever
username/password your local MySQL install actually uses.

---

## 2. Running the suite

**Docker:**

```bash
make test
# same as: docker compose exec app php artisan test
```

**Native:**

```bash
php artisan test
```

Both are equivalent to `vendor/bin/phpunit` — `artisan test` just adds
nicer output.

### Running a subset

Docker (`make artisan ...` forwards to `docker compose exec app php
artisan ...`):

```bash
# One file
make artisan test tests/Unit/Services/GeoCalculationServiceTest.php

# One test method (matches the whole name, so this also runs any other
# test whose name contains this string)
make artisan test --filter=test_upsert_cree_une_nouvelle_famille_quand_aucun_doublon

# One directory
make artisan test tests/Feature/Policies
```

Native — same arguments, just drop `make artisan` for a direct `php
artisan test ...`:

```bash
php artisan test tests/Unit/Services/GeoCalculationServiceTest.php
php artisan test --filter=test_upsert_cree_une_nouvelle_famille_quand_aucun_doublon
php artisan test tests/Feature/Policies
```

---

## 3. How the suite is organized

| Directory                 | What lives there                                                                               | Example                                               |
| ------------------------- | ---------------------------------------------------------------------------------------------- | ----------------------------------------------------- |
| `tests/Unit/Services/`    | Pure-logic services — no DB, or DB only incidentally (see below)                               | `tests/Unit/Services/GeoCalculationServiceTest.php`   |
| `tests/Unit/Support/`     | Support/helper classes, DB-backed where the class itself needs it                              | `tests/Unit/Support/FamilleFiltersTest.php`           |
| `tests/Feature/Services/` | Services that persist/query — real Eloquent models, real assertions against the database       | `tests/Feature/Services/FamilleUpsertServiceTest.php` |
| `tests/Feature/Policies/` | Authorization — one class per `app/Policies/*.php` file                                        | `tests/Feature/Policies/CampagnePolicyTest.php`       |
| `tests/Feature/Http/`     | Full HTTP requests through real routes (`$this->actingAs($personne)->post(route(...), [...])`) | `tests/Feature/Http/FamilleLockingTest.php`           |
| `tests/Concerns/`         | Shared trait helpers used across test classes — not tests themselves                           | `tests/Concerns/SeedsCommunFixtures.php`              |

`Unit` vs `Feature` here is about what the test needs, not a hard rule:
`ClusteringServiceTest` lives in `Unit/Services/` even though it needs the
DB, because `RouteOptimizationConfig` always reads settings through
`Amana\Shared\Models\Setting::get()` with no way to avoid it — see that
file's own class docblock for the full explanation. When in doubt, look at
what an existing test in the target directory actually does before adding
a new one elsewhere.

---

## 4. Patterns worth knowing before you add a test

These come up constantly in the existing suite — copy an existing example
rather than reinventing them:

**Need an authenticated user?** Use `Tests\Concerns\SeedsCommunFixtures`:

```php
use Tests\Concerns\SeedsCommunFixtures;

class MyTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerRolesFamilles(); // looks up the 9 roles the app's own migrations seed
    }

    public function test_something(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $this->actingAs($admin)->get(route('admin.personnes.index'))->assertOk();
    }
}
```

See `tests/Feature/Http/PersonnesControllerTest.php` for a full example.

**Need a campaign-scoped team role** (`equipe_pesee`/`equipe_reception`/
`equipe_packaging`/`equipe_chargement` on one specific `Campagne`, as
opposed to a global `ref_roles` role)? That's `Tests\Concerns\
BuildsCampagneEquipeFixtures` — see any file in `tests/Feature/Policies/`,
e.g. `CampagnePolicyTest.php`.

**Testing something that sends a notification or dispatches a queued
job?** Fake it in `setUp()`, always — several of this app's own jobs make
real external calls (geocoding, Google's OAuth token endpoint) that must
never actually run in a test:

```php
protected function setUp(): void
{
    parent::setUp();
    Notification::fake(); // or Bus::fake(), or both
}
```

See `tests/Feature/Http/ImportsControllerTest.php` (`Bus::fake()`, then
`Bus::assertDispatchedTimes(SynchroniserContactGoogle::class, 1)`) or
`tests/Feature/Http/BenevoleCandidaturesControllerTest.php`
(`Notification::assertSentTo(...)`).

**Testing something that goes through `RouteOptimizationConfig` or
`Amana\Shared\Models\Setting`?** Those cache results in a static PHP
property that survives `RefreshDatabase`'s per-test transaction rollback
(it's a plain in-process array, not a DB row). If your test overrides a
setting, clear the cache first:

```php
Setting::clearCache();
```

Same idea for `Amana\Shared\Helpers\AuditHelper::clearCache()` if your test
touches anything that calls the global `audit()` helper (most
create/update flows do) — see `tests/Feature/Services/
FamilleUpsertServiceTest.php`'s `setUp()` for why.

**Testing a private method with genuinely important logic and no
reasonable public seam?** Reflection is an accepted last resort here, not
a pattern to spread everywhere — see `tests/Unit/Services/
RouteGenerationServiceTest.php`'s docblock for the reasoning, and use it
the same way: only when the alternative (making something public just for
a test, or duplicating its logic) is worse.

---

## 5. The quality gate (what CI runs besides the tests)

`.github/workflows/tests.yaml` runs, in this order, on every push — all of
them must pass, and on `main` / `develop` a failure blocks the deployment:

| Step | Command | Fix locally |
|---|---|---|
| PHPUnit | `php artisan test` | — |
| PHPStan (level 5, `phpstan.neon`) | `composer analyse` | fix the code |
| Pint (PHP formatting, `pint.json`) | `composer format:check` | `composer format` |
| Prettier (frontend, `.prettierrc.json`) | `npm run format:check` | `npm run format` |
| ESLint | `npm run lint` | fix the code (`npx eslint --fix resources/js` for the trivial ones) |
| vue-tsc | `npm run type-check` | fix the types |

It runs the same way on any branch: a throwaway MySQL service container is
created for every run, so there is no shared test database that could be
missing on a given branch. PHPStan, ESLint and vue-tsc don't need a database
at all.

**PHPStan baseline.** `phpstan-baseline.neon` holds the findings that already
existed when the gate was introduced. It may only shrink: after fixing some of
them, run `composer analyse -- --generate-baseline` and commit the smaller
file. Never add an entry by hand to make CI pass.

**Vite in tests.** `Tests\TestCase::setUp()` calls `withoutVite()`: Inertia
pages render `app.blade.php` (`@vite`), and CI has no `public/build`. Tests
assert props and HTML, never compiled assets.

**shared-ui.** `@amana/shared-ui` is pinned by tag in `package.json` and by
commit in `package-lock.json`. After bumping the tag, run `npm install` so the
lockfile follows, otherwise `npm ci` fails.

---

## 6. What's *not* covered, and why

A few gaps are recorded rather than silently missing:

- **Frontend/Vue tests.** No Vitest/Vue Test Utils setup exists yet (no
  `vitest` in `package.json`) — the unified livraison row-expand
  composable (`useFormulaireCreneaux.ts`) and everything else on the Vue
  side has no automated coverage. Worth adding if this becomes a repeated
  pain point.
- **The Google OAuth token exchange itself.** `tests/Feature/Http/
  GoogleContactsControllerTest.php` only covers `redirect()` and
  `callback()`'s guard clauses (missing `code`, `error` param present) —
  anything past that needs a real request to Google's servers. Testing it
  properly needs a fake/mocked `Google\Client` bound into the container,
  which this app doesn't currently have a seam for.
- **`FamillesController::index()`/`nouvelles()`/`update()`/document
  handling.** Only the locking mechanism (`FamilleLockingTest.php`) is
  covered — the brief that shaped this suite called that out by name;
  the rest of that controller is a reasonable next addition.
