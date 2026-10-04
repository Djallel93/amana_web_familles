<?php
// app/Services/RoleService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Models\Application;
use Amana\Shared\Models\Role;
use App\Models\Personne;
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
    /**
     * Rôles de rang de l'application familles (l'admin, gestionnaire, membre,
     * bénévole en cascade, plus gestionnaire_externe, latéral) — un seul à la
     * fois par personne, ce sont ceux que gèrent syncRoleFamilles()/
     * currentRoleCode()/famillesRoles().
     *
     * Les 4 rôles equipe_* (reception/pesee/packaging/chargement) sont
     * volontairement HORS de cette liste : latéraux et cumulables avec un rôle
     * de rang (voir migration register_familles_application), ils ne doivent
     * jamais être supprimés ni masqués par un changement de rôle de rang.
     */
    public const ROLES_RANG = ['admin', 'gestionnaire', 'membre', 'benevole', 'gestionnaire_externe'];

    private ?Application $famillesApp = null;

    // ── Résolution de l'application ───────────────────────────────────────

    public function famillesApp(): ?Application
    {
        return $this->famillesApp ??= Application::where('code', 'familles')->first();
    }

    // ── Rôles disponibles ─────────────────────────────────────────────────

    /**
     * Retourne les rôles familles affichables dans les formulaires, dans
     * l'ordre hiérarchique admin > gestionnaire > membre > bénévole, puis
     * gestionnaire_externe en dernier — ajouté le 28/08/2026, rôle latéral
     * hors cascade (voir Amana\Shared\Http\Middleware\EnsureRole), placé à
     * part dans la liste pour ne pas laisser croire qu'il se situe entre
     * deux rôles internes.
     */
    public function famillesRoles(): Collection
    {
        $app = $this->famillesApp();

        if (!$app) {
            return collect();
        }

        return Role::where('id_application', $app->id)
            ->whereIn('code', self::ROLES_RANG)
            ->orderByRaw("FIELD(code, 'admin', 'gestionnaire', 'membre', 'benevole', 'gestionnaire_externe')")
            ->get();
    }

    // ── Lecture du rôle courant ───────────────────────────────────────────

    /**
     * Rôle de RANG courant (voir ROLES_RANG) — jamais un rôle equipe_*, qui
     * peut coexister avec lui : sans ce filtre, ->first() pourrait renvoyer
     * le rôle equipe_* d'une personne cumulant les deux, et le formulaire
     * d'édition n'y retrouverait aucune option à présélectionner.
     */
    public function currentRoleCode(Personne $personne): ?string
    {
        $role = $personne->roles()
            ->whereHas('application', fn($q) => $q->where('code', 'familles'))
            ->whereIn('ref_roles.code', self::ROLES_RANG)
            ->first();

        return $role?->code;
    }

    // ── Synchronisation du rôle ───────────────────────────────────────────

    /**
     * Attribue un rôle de rang familles à une personne — supprime d'abord
     * son éventuel rôle de rang existant (une personne n'a qu'un seul rôle
     * de rang à la fois), puis insère le nouveau. N'affecte jamais les rôles
     * de la personne sur d'autres applications (ex : ses rôles Planning
     * restent intacts), ni ses rôles equipe_* familles (cumulables avec le
     * rôle de rang, voir ROLES_RANG).
     */
    public function syncRoleFamilles(Personne $personne, string $roleCode): void
    {
        $app = $this->famillesApp();

        if (!$app) {
            return;
        }

        $rolesRangIds = Role::where('id_application', $app->id)
            ->whereIn('code', self::ROLES_RANG)
            ->pluck('id')
            ->toArray();

        if (!empty($rolesRangIds)) {
            DB::connection(config('amana-shared.connection', 'commun'))->table('ref_personnes_roles')
                ->where('id_personne', $personne->id)
                ->whereIn('id_role', $rolesRangIds)
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
     * Retire tout accès à l'application familles pour une personne (rôle de
     * rang ET rôles equipe_*, contrairement à syncRoleFamilles()), sans
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
