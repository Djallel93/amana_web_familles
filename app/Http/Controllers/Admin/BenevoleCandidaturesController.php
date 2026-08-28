<?php
// app/Http/Controllers/Admin/BenevoleCandidaturesController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Amana\Shared\Models\VehiculeType;
use App\Models\BenevoleProfil;
use App\Models\Personne;
use App\Notifications\BenevoleCandidatureValideeDejaInscritNotification;
use App\Notifications\BenevoleCandidatureValideeNotification;
use App\Services\RoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * Revue des candidatures bénévoles par le staff — liste/filtre, détail,
 * validation/rejet. Miroir d'Admin\CandidaturesController
 * (amana_web_planning), adapté à BenevoleProfil (statut propre au
 * bénévolat, distinct de Personne::statut — voir migration
 * create_benevole_profils_table) plutôt qu'au statut de Personne
 * directement.
 *
 * Routes sous /admin, donc `role:admin` uniquement (pas admin|gestionnaire :
 * contrairement à amana_web_planning, amana_web_familles restreint tout le
 * groupe /admin à role:admin — /familles/* est le seul préfixe ouvert à
 * role:gestionnaire, voir routes/web.php).
 *
 * Révision du 26/08/2026 : le rôle attribué à la validation est maintenant
 * choisi par le staff (select dans la vue, voir admin/candidatures/
 * index.blade.php de amana_web_planning pour le précédent exact suivi
 * ici), plutôt que 'benevole' imposé sans possibilité de choix.
 */
class BenevoleCandidaturesController extends Controller
{
    public function __construct(
        private readonly RoleService $roleService,
    ) {
    }

    public function index(Request $request): View
    {
        $candidatures = BenevoleProfil::with(['personne', 'secteurs', 'vehiculeType'])
            ->when($request->filled('statut'), fn($q) => $q->where('statut', $request->input('statut')))
            ->when($request->filled('id_vehicule_type'), fn($q) => $q->where('id_vehicule_type', $request->input('id_vehicule_type')))
            ->when($request->filled('id_secteur'), fn($q) => $q->whereHas(
                'secteurs',
                fn($q2) => $q2->where('secteurs.id', $request->input('id_secteur')),
            ))
            ->when(!$request->filled('statut'), fn($q) => $q->pourRevueStaff())
            ->orderByDesc('derniere_maj')
            ->paginate(30)
            ->withQueryString();

        return view('admin.benevoles.index', [
            'candidatures' => $candidatures,
            'vehicules' => VehiculeType::orderBy('id')->get(['id', 'type']),
            'statuts' => BenevoleProfil::STATUTS,
            'roles' => $this->roleService->famillesRoles(),
        ]);
    }

    public function show(int $id): View
    {
        $profil = BenevoleProfil::with(['personne', 'secteurs', 'vehiculeType'])->findOrFail($id);

        return view('admin.benevoles.show', [
            'profil' => $profil,
            'roles' => $this->roleService->famillesRoles(),
        ]);
    }

    public function valider(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'role' => ['required', 'string', 'in:' . $this->roleService->famillesRoles()->pluck('code')->implode(',')],
        ], [
            'role.required' => 'Veuillez sélectionner un rôle.',
            'role.in' => 'Rôle invalide.',
        ]);

        $profil = BenevoleProfil::findOrFail($id);

        if ($profil->statut !== 'Reçu') {
            return redirect()->route('admin.benevoles.index')
                ->with('error', 'Cette candidature n\'est plus en attente.');
        }

        $avant = $profil->toArray();
        $roleCode = $request->input('role', 'benevole');

        $profil->statut = 'Validé';
        $profil->save();

        // Important : PAS $profil->personne — cette relation, définie dans
        // Amana\Shared\Models\BenevoleProfil, instancie toujours
        // Amana\Shared\Models\Personne, jamais la sous-classe locale
        // App\Models\Personne qu'attend RoleService::syncRoleFamilles()
        // (type-hint strict). Un TypeError sinon (retour du 27/08/2026).
        $personne = Personne::findOrFail($profil->id_personne);

        // Sans ceci, la Personne reste bloquée à statut='En attente' — or
        // AuthController REFUSE explicitement toute connexion dans cet état
        // (voir amana_shared/src/Http/Controllers/AuthController.php) : le
        // bénévole recevrait son email d'invitation/connexion sans jamais
        // pouvoir réellement se connecter. Oublié dans la version
        // précédente alors que amana_web_planning::CandidaturesController
        // le fait bien (précédent suivi pour le reste de cette méthode).
        $personne->statut = 'Validé';
        $personne->save();

        $this->roleService->syncRoleFamilles($personne, $roleCode);

        $dejaMotDePasse = !empty($personne->password);

        if ($dejaMotDePasse) {
            try {
                $personne->notify(new BenevoleCandidatureValideeDejaInscritNotification(route('login')));
                Log::info('[BenevoleCandidaturesController] Email connexion directe envoyé', ['id_personne' => $personne->id]);
            } catch (\Throwable $e) {
                Log::error('[BenevoleCandidaturesController] Échec email connexion directe', [
                    'id_personne' => $personne->id,
                    'erreur' => $e->getMessage(),
                ]);
            }
            $messageFlash = "Candidature de {$personne->prenom} {$personne->nom} validée (rôle : {$roleCode}). Email de connexion directe envoyé.";
        } else {
            $token = Password::broker('personnes')->createToken($personne);
            $resetUrl = route('password.reset', ['token' => $token, 'email' => $personne->email]);
            try {
                $personne->notify(new BenevoleCandidatureValideeNotification($resetUrl));
                Log::info('[BenevoleCandidaturesController] Email invitation envoyé', ['id_personne' => $personne->id]);
            } catch (\Throwable $e) {
                Log::error('[BenevoleCandidaturesController] Échec email invitation', [
                    'id_personne' => $personne->id,
                    'erreur' => $e->getMessage(),
                ]);
            }
            $messageFlash = "Candidature de {$personne->prenom} {$personne->nom} validée (rôle : {$roleCode}). Email d'invitation envoyé.";
        }

        audit('update', 'benevole_candidatures', $profil->id, $avant, [
            'statut' => 'Validé',
            'action' => 'validation',
            'role' => $roleCode,
            'deja_mot_de_passe' => $dejaMotDePasse,
        ]);

        return redirect()->route('admin.benevoles.index')->with('success', $messageFlash);
    }

    public function rejeter(int $id): RedirectResponse
    {
        $profil = BenevoleProfil::findOrFail($id);

        if ($profil->statut !== 'Reçu') {
            return redirect()->route('admin.benevoles.index')
                ->with('error', 'Cette candidature n\'est plus en attente.');
        }

        $avant = $profil->toArray();
        $profil->statut = 'Rejeté';
        $profil->save();

        audit('update', 'benevole_candidatures', $profil->id, $avant, [
            'statut' => 'Rejeté',
            'action' => 'candidature refusée',
        ]);

        return redirect()->route('admin.benevoles.index')->with('success', 'Candidature refusée.');
    }
}
