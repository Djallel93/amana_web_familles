<?php
// tests/Concerns/SeedsCommunFixtures.php

declare(strict_types=1);

namespace Tests\Concerns;

use Amana\Shared\Models\Personne;
use Illuminate\Support\Facades\DB;

/**
 * Minimal `commun`-connection fixtures every authenticated test needs.
 *
 * The `familles` ref_applications row, its 9 ref_roles, and the 2
 * inscription-switch ref_settings rows are already created by this app's
 * OWN migrations (database/migrations/2026_08_27_000000_register_familles_
 * application.php, run as part of the normal migrate:fresh cycle — see
 * MigratesCommunConnection) — NOT re-inserted here. ref_applications.code
 * and ref_roles.(code, id_application) are both unique in amana_shared's
 * schema, so re-inserting them would fail; loadRolesEtApplication() below
 * just looks up what the migration already created.
 *
 * What this trait actually adds is test-only data the migrations
 * deliberately don't create: real ref_personnes rows with roles attached,
 * for tests that need an authenticated user.
 */
trait SeedsCommunFixtures
{
    /** @var array<string, int> role code => ref_roles.id, for the 'familles' app */
    protected array $roleIds = [];

    protected int $idApplicationFamilles;

    /**
     * Looks up the 'familles' app + its 9 roles (already seeded by
     * migrations) into $this->idApplicationFamilles/$this->roleIds — call
     * this before creerPersonne() with any $roles.
     */
    protected function chargerRolesFamilles(): void
    {
        $connexion = config('amana-shared.connection', 'commun');

        $this->idApplicationFamilles = (int) DB::connection($connexion)
            ->table('ref_applications')
            ->where('code', 'familles')
            ->value('id');

        $this->roleIds = DB::connection($connexion)
            ->table('ref_roles')
            ->where('id_application', $this->idApplicationFamilles)
            ->pluck('id', 'code')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Creates a real Personne (commun connection) with the given role codes
     * attached for the 'familles' application — call chargerRolesFamilles()
     * first (once per test) so $this->roleIds is populated.
     *
     * @param  string[]  $roles
     */
    protected function creerPersonne(array $roles = [], array $attributs = []): Personne
    {
        static $n = 0;
        $n++;

        $personne = Personne::query()->create(array_merge([
            'nom' => "Nom{$n}",
            'prenom' => "Prenom{$n}",
            'email' => "personne{$n}@amana-test.fr",
            'password' => bcrypt('mot-de-passe'),
            'statut' => 'Validé',
        ], $attributs));

        foreach ($roles as $code) {
            if (! isset($this->roleIds[$code])) {
                throw new \InvalidArgumentException("Rôle inconnu ou non chargé (appeler chargerRolesFamilles() d'abord) : {$code}");
            }

            DB::connection(config('amana-shared.connection', 'commun'))->table('ref_personnes_roles')->insert([
                'id_personne' => $personne->id,
                'id_role' => $this->roleIds[$code],
                'date_attribution' => now()->toDateString(),
            ]);
        }

        return $personne->fresh();
    }
}
