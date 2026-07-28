<?php
// app/Services/RoleService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Models\Application;
use App\Models\Personne;
use Amana\Shared\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service centralisé pour la gestion des rôles de l'application 'familles'.
 * Adapté de amana_web_planning\Services\RoleService (même structure,
 * app_code résolu sur 'familles' au lieu de 'planning').
 *
 * Utilisé par :
 *   - Admin\PersonnesController
 */
class RoleService
{
    private ?Application $famillesApp = null;

    // ── Résolution de l'application ───────────────────────────────────────

    public function famillesApp(): ?Application
    {
        return $this->famillesApp ??= Application::where('code', 'familles')->first();
    }

    // ── Rôles disponibles ─────────────────────────────────────────────────

    /**
     * Retourne les rôles familles affichables dans les formulaires,
     * dans l'ordre hiérarchique admin > gestionnaire > membre > bénévole.
     */
    public function famillesRoles(): Collection
    {
        $app = $this->famillesApp();

        if (!$app) {
            return collect();
        }

        return Role::where('id_application', $app->id)
            ->whereIn('code', ['admin', 'gestionnaire', 'membre', 'benevole'])
            ->orderByRaw("FIELD(code, 'admin', 'gestionnaire', 'membre', 'benevole')")
            ->get();
    }

    // ── Lecture du rôle courant ───────────────────────────────────────────

    public function currentRoleCode(Personne $personne): ?string
    {
        $role = $personne->roles()
            ->whereHas('application', fn($q) => $q->where('code', 'familles'))
            ->first();

        return $role?->code;
    }

    // ── Synchronisation du rôle ───────────────────────────────────────────

    /**
     * Attribue un rôle familles à une personne — supprime d'abord tout
     * rôle familles existant (une personne n'a qu'un seul rôle familles à
     * la fois), puis insère le nouveau. N'affecte jamais les rôles de la
     * personne sur d'autres applications (ex : ses rôles Planning restent
     * intacts).
     */
    public function syncRoleFamilles(Personne $personne, string $roleCode): void
    {
        $app = $this->famillesApp();

        if (!$app) {
            return;
        }

        $famillesRoleIds = Role::where('id_application', $app->id)->pluck('id')->toArray();

        if (!empty($famillesRoleIds)) {
            DB::connection(config('amana-shared.connection', 'commun'))->table('ref_personnes_roles')
                ->where('id_personne', $personne->id)
                ->whereIn('id_role', $famillesRoleIds)
                ->delete();
        }

        $role = Role::where('code', $roleCode)
            ->where('id_application', $app->id)
            ->first();

        if ($role) {
            DB::connection(config('amana-shared.connection', 'commun'))->table('ref_personnes_roles')->insert([
                'id_personne' => $personne->id,
                'id_role' => $role->id,
                'date_attribution' => now()->toDateString(),
            ]);
        }
    }

    /**
     * Retire tout accès à l'application familles pour une personne, sans
     * supprimer son compte ref_personnes (elle peut garder l'accès à
     * d'autres apps AMANA).
     */
    public function revokeAccesFamilles(Personne $personne): void
    {
        $app = $this->famillesApp();

        if (!$app) {
            return;
        }

        $famillesRoleIds = Role::where('id_application', $app->id)->pluck('id')->toArray();

        if (!empty($famillesRoleIds)) {
            DB::connection(config('amana-shared.connection', 'commun'))->table('ref_personnes_roles')
                ->where('id_personne', $personne->id)
                ->whereIn('id_role', $famillesRoleIds)
                ->delete();
        }
    }
}
