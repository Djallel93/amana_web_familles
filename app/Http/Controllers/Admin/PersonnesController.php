<?php
// app/Http/Controllers/Admin/PersonnesController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Personne;
use App\Notifications\InvitationFamillesNotification;
use App\Notifications\InvitationFamillesDejaInscritNotification;
use App\Services\RoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * Gestion du staff AMANA Familles (admin uniquement — voir section 8.2 du
 * prompt de migration, "Vue Personnes").
 *
 * Contrairement à Planning, il n'y a pas de flux de "candidature" publique
 * ici (Familles est staff-only, décision 6.2) : un admin crée directement
 * un compte et lui attribue un rôle. Le reste du mécanisme est repris à
 * l'identique de CandidaturesController::valider() de amana_web_planning :
 *   - si la personne a déjà un mot de passe (compte partagé, ex: déjà staff
 *     Planning) → email "connexion directe"
 *   - sinon → email d'invitation avec lien de création de mot de passe
 *     (Password::broker('personnes')->createToken())
 */
class PersonnesController extends Controller
{
    public function __construct(
        private readonly RoleService $roleService,
    ) {
    }

    public function index(): View
    {
        $personnes = Personne::staffFamilles()
            ->with(['roles' => fn($q) => $q->whereHas('application', fn($q2) => $q2->where('code', 'familles'))])
            ->orderBy('nom')
            ->get();

        return view('personnes.index', compact('personnes'));
    }

    public function create(): View
    {
        $roles = $this->roleService->famillesRoles();

        return view('personnes.form', [
            'personne' => null,
            'roleActuel' => null,
            'roles' => $roles,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'string', 'in:admin,gestionnaire,membre,benevole'],
        ], [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'email.required' => 'L\'adresse email est obligatoire.',
            'email.email' => 'Format d\'email invalide.',
            'role.required' => 'Veuillez sélectionner un rôle.',
            'role.in' => 'Rôle invalide.',
        ]);

        // ── Personne déjà connue de ref_personnes ? ──────────────────────
        // Table PARTAGÉE : la personne peut déjà exister (ex : staff
        // Planning) — on ne crée jamais de doublon, on lui attribue juste
        // en plus le rôle familles demandé.
        $personne = Personne::where('email', $request->email)->first();

        if ($personne) {
            $avant = $personne->toArray();
            $personne->fill($request->only(['nom', 'prenom', 'telephone']));
            $personne->save();
        } else {
            $avant = null;
            $personne = Personne::create([
                'nom' => $request->nom,
                'prenom' => $request->prenom,
                'email' => $request->email,
                'telephone' => $request->telephone,
                'statut' => 'Validé',
            ]);
        }

        $roleCode = $request->input('role');
        $this->roleService->syncRoleFamilles($personne, $roleCode);

        $dejaMotDePasse = !empty($personne->password);

        if ($dejaMotDePasse) {
            try {
                $personne->notify(new InvitationFamillesDejaInscritNotification(route('login')));
                Log::info('[PersonnesController] Email connexion directe envoyé', ['id' => $personne->id]);
            } catch (\Throwable $e) {
                Log::error('[PersonnesController] Échec email connexion directe', [
                    'id' => $personne->id,
                    'erreur' => $e->getMessage(),
                ]);
            }
            $messageFlash = "Accès accordé à {$personne->prenom} {$personne->nom} (rôle : {$roleCode}). Email de connexion directe envoyé.";
        } else {
            $token = Password::broker('personnes')->createToken($personne);
            $resetUrl = route('password.reset', ['token' => $token, 'email' => $personne->email]);
            try {
                $personne->notify(new InvitationFamillesNotification($resetUrl));
                Log::info('[PersonnesController] Email invitation envoyé', ['id' => $personne->id]);
            } catch (\Throwable $e) {
                Log::error('[PersonnesController] Échec email invitation', [
                    'id' => $personne->id,
                    'erreur' => $e->getMessage(),
                ]);
            }
            $messageFlash = "Compte créé pour {$personne->prenom} {$personne->nom} (rôle : {$roleCode}). Email d'invitation envoyé.";
        }

        audit('create', 'familles_personnes', $personne->id, $avant, [
            'action' => 'attribution accès familles',
            'role' => $roleCode,
            'deja_mot_de_passe' => $dejaMotDePasse,
        ]);

        return redirect()->route('admin.personnes.index')->with('success', $messageFlash);
    }

    public function edit(int $id): View
    {
        $personne = Personne::findOrFail($id);
        $roles = $this->roleService->famillesRoles();
        $roleActuel = $this->roleService->currentRoleCode($personne);

        return view('personnes.form', compact('personne', 'roleActuel', 'roles'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'telephone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'string', 'in:admin,gestionnaire,membre,benevole'],
        ], [
            'role.required' => 'Veuillez sélectionner un rôle.',
            'role.in' => 'Rôle invalide.',
        ]);

        $personne = Personne::findOrFail($id);
        $avant = $personne->toArray();

        $personne->fill($request->only(['nom', 'prenom', 'telephone']));
        $personne->save();

        $this->roleService->syncRoleFamilles($personne, $request->input('role'));

        audit('update', 'familles_personnes', $personne->id, $avant, $personne->toArray());

        return redirect()->route('admin.personnes.index')
            ->with('success', "Fiche de {$personne->prenom} {$personne->nom} mise à jour.");
    }

    /**
     * Révoque l'accès à Familles — ne supprime PAS le compte ref_personnes
     * (partagé, peut avoir accès à d'autres apps AMANA), retire uniquement
     * le rôle familles.
     */
    public function destroy(int $id): RedirectResponse
    {
        $personne = Personne::findOrFail($id);
        $avant = $personne->toArray();

        $this->roleService->revokeAccesFamilles($personne);

        audit('delete', 'familles_personnes', $personne->id, $avant, null);

        return redirect()->route('admin.personnes.index')
            ->with('success', "Accès de {$personne->prenom} {$personne->nom} à AMANA Familles révoqué.");
    }
}
