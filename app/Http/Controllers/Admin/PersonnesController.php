<?php
// app/Http/Controllers/Admin/PersonnesController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Amana\Shared\Services\AccountChangeNotifier;
use App\Http\Controllers\Controller;
use App\Models\Campagne;
use App\Models\Organisation;
use App\Models\Personne;
use App\Notifications\InvitationFamillesNotification;
use App\Notifications\InvitationFamillesDejaInscritNotification;
use App\Services\RoleService;
use Amana\Shared\Models\Secteur;
use Amana\Shared\Models\VehiculeType;
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
        private readonly AccountChangeNotifier $notifier,
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
            // Multi-select "organisations" (ajouté le 28/08/2026) — vide/masqué
            // par défaut côté vue, affiché seulement quand role = gestionnaire_externe.
            'organisations' => Organisation::actifs()->orderBy('nom')->get(['id', 'nom']),
            'organisationsActuelles' => [],
            // Bloc "profil bénévole" (véhicule/secteurs) — n'a de sens qu'en
            // édition (voir edit()), une personne n'a pas encore de
            // BenevoleProfil à la création depuis cet écran.
            'benevoleProfil' => null,
            'vehicules' => collect(),
            'secteurs' => collect(),
            'secteursActuels' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'string', 'in:admin,gestionnaire,membre,benevole,gestionnaire_externe'],
            // Obligatoire uniquement pour gestionnaire_externe (voir
            // décision du 28/08/2026 : plusieurs organisations possibles
            // par compte) — validé plus bas via un after() plutôt qu'un
            // required_if imbriqué dans un tableau, pour un message d'erreur
            // plus clair.
            'organisations' => ['array'],
            'organisations.*' => ['integer', 'exists:organisations,id'],
        ], [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'email.required' => 'L\'adresse email est obligatoire.',
            'email.email' => 'Format d\'email invalide.',
            'role.required' => 'Veuillez sélectionner un rôle.',
            'role.in' => 'Rôle invalide.',
        ]);

        if ($request->input('role') === 'gestionnaire_externe' && empty($request->input('organisations'))) {
            return back()->withErrors(['organisations' => 'Sélectionnez au moins une organisation pour un gestionnaire externe.'])->withInput();
        }

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

        // Rattachement organisation(s) (ajouté le 28/08/2026) — vidé pour
        // tout rôle autre que gestionnaire_externe, même si le formulaire
        // ne devrait normalement pas en soumettre (défense en profondeur :
        // un ancien gestionnaire_externe rétrogradé ne doit pas garder un
        // accès résiduel via personne_organisation, voir
        // FamillesController::assertAccesFamille()).
        Organisation::syncPersonne($personne->id, $roleCode === 'gestionnaire_externe' ? $request->input('organisations', []) : []);

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

    public function edit(int $id, Request $request): View
    {
        $personne = Personne::findOrFail($id);
        $roles = $this->roleService->famillesRoles();
        $roleActuel = $this->roleService->currentRoleCode($personne);

        // Profil bénévole (véhicule + secteurs couverts) — n'existe que si
        // la personne a un BenevoleProfil (candidature bénévole acceptée,
        // voir BenevoleIntakeConfirmationController). Champs restés en
        // lecture seule sur cet écran jusqu'au 29/08/2026 — voir
        // resources/views/personnes/form.blade.php pour l'édition.
        $benevoleProfil = $personne->benevoleProfil;

        return view('personnes.form', [
            'personne' => $personne,
            'roleActuel' => $roleActuel,
            'roles' => $roles,
            'organisations' => Organisation::actifs()->orderBy('nom')->get(['id', 'nom']),
            'organisationsActuelles' => Organisation::idsPourPersonne($personne->id),
            'benevoleProfil' => $benevoleProfil,
            'vehicules' => VehiculeType::orderBy('id')->get(['id', 'type']),
            'secteurs' => Secteur::with('ville')->orderBy('nom')->get(['id', 'nom', 'id_ville'])
                ->map(fn($secteur) => [
                    'id' => $secteur->id,
                    'libelle' => ($secteur->ville?->nom ?? '?') . ' - ' . $secteur->nom,
                ])
                ->sortBy('libelle')
                ->values(),
            'secteursActuels' => $benevoleProfil ? $benevoleProfil->secteurs()->pluck('secteurs.id')->all() : [],
            // Retour conditionnel (24/09/2026, prompt de cette date §1.1) :
            // "Add this button only if user comes from campagnes/{id}/
            // benevoles. If user access from sidebar this button does
            // not appear" — voir infosRetour() ci-dessous pour la
            // validation (URL construite ici, jamais transmise brute par
            // le client, pour exclure tout risque d'open-redirect).
            // "Future proof" (prompt, réponse à ma Q1) : infosRetour()
            // n'est volontairement PAS câblée sur un seul cas
            // ('campagne_benevoles') — toute future origine n'a qu'à
            // ajouter une entrée à la petite table qu'elle contient.
            'urlRetour' => $this->infosRetour($request)['url'] ?? null,
        ]);
    }

    /**
     * Retour conditionnel vers l'écran d'origine (24/09/2026, prompt de
     * cette date §1.1) — table VOLONTAIREMENT ouverte à d'autres entrées
     * futures ("future proof", voir réponse du prompt à ma Q1) : chaque
     * origine possible n'a qu'à ajouter son cas ici, plutôt qu'un seul
     * if() câblé sur campagne_benevoles. Construit l'URL CÔTÉ SERVEUR à
     * partir d'un id validé (jamais transmise brute par le client) — pas
     * d'open-redirect possible, contrairement à un ?retour=<url libre>.
     *
     * @return array{origine: string, url: string}|null
     */
    private function infosRetour(Request $request): ?array
    {
        $origine = $request->input('retour');

        return match ($origine) {
            'campagne_benevoles' => $this->retourVersCampagneBenevoles($request),
            default => null,
        };
    }

    private function retourVersCampagneBenevoles(Request $request): ?array
    {
        $idCampagne = $request->input('id_campagne');
        if (!is_numeric($idCampagne)) {
            return null;
        }

        $campagne = Campagne::find((int) $idCampagne);
        if (!$campagne) {
            return null;
        }

        return ['origine' => 'campagne_benevoles', 'url' => route('livraison.campagnes.benevoles.index', $campagne)];
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
            'telephone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'string', 'in:admin,gestionnaire,membre,benevole,gestionnaire_externe'],
            'organisations' => ['array'],
            'organisations.*' => ['integer', 'exists:organisations,id'],
            // Bloc "profil bénévole" — seulement présent/soumis quand la
            // personne a déjà un BenevoleProfil (voir edit()/form.blade.php),
            // donc pas de required ici : simplement ignoré s'il n'y a pas de
            // profil à mettre à jour.
            'permis' => ['nullable', 'boolean'],
            'id_vehicule_type' => ['nullable', 'integer', 'exists:commun.ref_vehicules,id'],
            'secteurs' => ['array'],
            'secteurs.*' => ['integer', 'exists:commun.secteurs,id'],
        ], [
            'role.required' => 'Veuillez sélectionner un rôle.',
            'role.in' => 'Rôle invalide.',
        ]);

        if ($request->input('role') === 'gestionnaire_externe' && empty($request->input('organisations'))) {
            return back()->withErrors(['organisations' => 'Sélectionnez au moins une organisation pour un gestionnaire externe.'])->withInput();
        }

        $personne = Personne::findOrFail($id);
        $avant = $personne->toArray();

        $personne->fill($request->only(['nom', 'prenom', 'telephone']));
        $personne->save();

        $this->roleService->syncRoleFamilles($personne, $request->input('role'));
        Organisation::syncPersonne($personne->id, $request->input('role') === 'gestionnaire_externe' ? $request->input('organisations', []) : []);

        // Véhicule / secteurs couverts (ajouté le 29/08/2026) — seulement
        // si un BenevoleProfil existe déjà pour cette personne (candidature
        // acceptée) ; ce formulaire ne crée jamais de profil bénévole.
        $benevoleProfil = $personne->benevoleProfil;
        if ($benevoleProfil) {
            $benevoleProfil->permis = $request->boolean('permis');
            $benevoleProfil->id_vehicule_type = $request->input('id_vehicule_type') ?: null;
            $benevoleProfil->save();
            $benevoleProfil->secteurs()->sync($request->input('secteurs', []));
        }

        audit('update', 'familles_personnes', $personne->id, $avant, $personne->toArray());

        // Redirection conditionnelle (24/09/2026, prompt de cette date
        // §1.1, "after saving, redirect back to campagnes/{id}/benevoles
        // too") — mêmes hidden inputs retour/id_campagne que le bouton de
        // retour (voir form.blade.php), revalidés ici via infosRetour()
        // plutôt que de faire confiance à une URL transmise par le
        // formulaire.
        $retour = $this->infosRetour($request);
        if ($retour) {
            return redirect($retour['url'])
                ->with('success', "Fiche de {$personne->prenom} {$personne->nom} mise à jour.");
        }

        return redirect()->route('admin.personnes.index')
            ->with('success', "Fiche de {$personne->prenom} {$personne->nom} mise à jour.");
    }

    /**
     * « Envoyer un lien de réinitialisation » : envoie à la personne l'email
     * standard de « mot de passe oublié » (broker 'personnes'). L'administrateur
     * ne saisit ni ne voit jamais de mot de passe ni de jeton. Audité par le
     * notifier (acteur = admin connecté, cible = la personne, jamais le jeton) ;
     * limité à 5 envois par minute (routes/admin.php).
     *
     * NB : l'écran admin de familles ne modifie pas l'adresse email d'une personne
     * (nom, prénom, téléphone, rôle seulement) : aucune notice de changement
     * d'email à envoyer ici.
     */
    public function envoyerLienReinitialisation(int $id): RedirectResponse
    {
        $personne = Personne::findOrFail($id);
        $nom = "{$personne->prenom} {$personne->nom}";

        return match ($this->notifier->sendResetLink($personne)) {
            Password::RESET_LINK_SENT => back()->with('success', "Lien de réinitialisation envoyé à {$personne->email} ({$nom})."),
            Password::RESET_THROTTLED => back()->with('warning', "Un lien vient déjà d'être envoyé à {$nom} : patientez une minute avant d'en renvoyer un."),
            default => back()->with('error', "L'envoi du lien de réinitialisation à {$nom} a échoué. Vérifiez la configuration email."),
        };
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
