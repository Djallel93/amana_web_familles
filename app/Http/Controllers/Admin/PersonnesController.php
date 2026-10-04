<?php
// app/Http/Controllers/Admin/PersonnesController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Amana\Shared\Models\BenevoleProfil;
use Amana\Shared\Services\AccountChangeNotifier;
use App\Http\Controllers\Controller;
use App\Models\BenevoleDisponibilite;
use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Models\Organisation;
use App\Models\Personne;
use App\Models\PersonneDesactivee;
use App\Notifications\InvitationFamillesDejaInscritNotification;
use App\Notifications\InvitationFamillesNotification;
use App\Services\BenevoleDisponibiliteService;
use App\Services\PersonneActivationService;
use App\Services\RoleService;
use App\Support\GeographiePicker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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
        private readonly BenevoleDisponibiliteService $disponibiliteService,
        private readonly PersonneActivationService $activationService,
    ) {}

    /**
     * Liste du staff avec recherche, filtre par rôle et compteurs par rôle
     * (01/10/2026).
     *
     * Les compteurs sont TOUJOURS les totaux complets (indépendants de la
     * recherche et du filtre en cours) ; seul le tableau est filtré. Le
     * rôle affiché/compté est le rôle de RANG (RoleService::ROLES_RANG) —
     * jamais un rôle equipe_*, qui peut coexister avec lui. Tout est
     * filtré en mémoire : le staff n'est pas paginé, et ça permet une
     * recherche insensible à la casse ET aux accents quelle que soit la
     * collation de la base.
     *
     * Personnes désactivées (03/10/2026, voir PersonneActivationService) :
     * masquées par défaut et exclues des compteurs de rôle et du total ;
     * ?desactives=1 les affiche (pastille « Désactivée » + bouton
     * Réactiver). Leur nombre est toujours indiqué à part.
     */
    public function index(Request $request): View
    {
        $tous = Personne::staffFamilles()
            ->with(['roles' => fn($q) => $q->whereHas('application', fn($q2) => $q2->where('code', 'familles'))])
            ->orderBy('nom')
            ->get();

        $compteurs = array_fill_keys(RoleService::ROLES_RANG, 0);
        $compteurs['aucun'] = 0;

        $idsDesactives = array_flip(PersonneDesactivee::ids());
        $nbDesactives = 0;

        foreach ($tous as $personne) {
            $role = $personne->roles->first(fn($r) => in_array($r->code, RoleService::ROLES_RANG, true));
            $code = $role?->code;
            $personne->setAttribute('role_code', $code);

            $desactivee = isset($idsDesactives[$personne->id]);
            $personne->setAttribute('desactivee', $desactivee);
            if ($desactivee) {
                $nbDesactives++;

                continue;
            }
            $compteurs[$code ?? 'aucun']++;
        }

        $afficherDesactives = $request->boolean('desactives');

        $recherche = trim((string) $request->query('q', ''));
        $roleFiltre = (string) $request->query('role', '');
        if (!in_array($roleFiltre, [...RoleService::ROLES_RANG, 'aucun'], true)) {
            $roleFiltre = '';
        }

        $personnes = $tous
            ->when(!$afficherDesactives, fn($c) => $c->reject(fn($p) => $p->desactivee))
            ->when($roleFiltre !== '', fn($c) => $c->filter(fn($p) => ($p->role_code ?? 'aucun') === $roleFiltre))
            ->when($recherche !== '', function ($c) use ($recherche) {
                $mots = array_filter(explode(' ', $this->normaliserRecherche($recherche)));

                return $c->filter(function ($p) use ($mots) {
                    $texte = $this->normaliserRecherche("{$p->prenom} {$p->nom} {$p->email} {$p->telephone}");
                    foreach ($mots as $mot) {
                        if (!str_contains($texte, $mot)) {
                            return false;
                        }
                    }

                    return true;
                });
            })
            ->values();

        return view('personnes.index', [
            'personnes' => $personnes,
            'total' => $tous->count() - $nbDesactives,
            'compteurs' => $compteurs,
            'nbDesactives' => $nbDesactives,
            'afficherDesactives' => $afficherDesactives,
            'recherche' => $recherche,
            'roleFiltre' => $roleFiltre,
        ]);
    }

    private function normaliserRecherche(string $texte): string
    {
        return Str::ascii(mb_strtolower($texte));
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

        // Profil bénévole — n'existe que si la personne a un BenevoleProfil
        // (candidature bénévole acceptée, voir
        // BenevoleIntakeConfirmationController). Depuis le 01/10/2026 le
        // permis, le véhicule et les secteurs ne s'éditent PLUS sur le
        // profil : ils sont propres à chaque campagne/journée (voir la
        // section « Par campagne / journée » de personnes/form.blade.php) ;
        // le profil ne sert plus que de valeur de repli (« même que mon
        // profil »).
        $benevoleProfil = $personne->benevoleProfil;

        $parJournee = $benevoleProfil ? $this->donneesParJournee($personne, $request) : [];

        return view('personnes.form', [
            'personne' => $personne,
            'roleActuel' => $roleActuel,
            'roles' => $roles,
            'organisations' => Organisation::actifs()->orderBy('nom')->get(['id', 'nom']),
            'organisationsActuelles' => Organisation::idsPourPersonne($personne->id),
            'benevoleProfil' => $benevoleProfil,
            'vehicules' => $benevoleProfil ? GeographiePicker::vehiculesAvecPermis() : [],
            'villes' => $benevoleProfil ? GeographiePicker::villesAvecSecteurs() : [],
            'groupesJournees' => $parJournee['groupes'] ?? [],
            'etatsJournees' => $parJournee['etats'] ?? (object) [],
            'journeeSelectionnee' => $parJournee['selectionnee'] ?? null,
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

    /**
     * Données de la section « Par campagne / journée » de la fiche
     * personne : TOUTES les journées de TOUTES les campagnes, les plus
     * récentes d'abord (campagnes groupées, journées de chaque campagne
     * dans l'ordre), avec l'état véhicule/couverture déjà enregistré pour
     * cette personne. La journée présélectionnée vient de
     * ?id_campagne_journee= (lien « Modifier informations » de Suivi des
     * bénévoles) si elle existe, sinon la plus récente.
     *
     * @return array{groupes: array<int, array<string, mixed>>, etats: array<int, array<string, mixed>>, selectionnee: int|null}
     */
    private function donneesParJournee(Personne $personne, Request $request): array
    {
        $journees = CampagneJournee::with('campagne')
            ->orderByDesc('date')
            ->orderBy('ordre')
            ->get()
            ->filter(fn(CampagneJournee $j) => $j->campagne !== null);

        $disponibilites = BenevoleDisponibilite::with('secteurs')
            ->where('id_personne', $personne->id)
            ->get()
            ->keyBy('id_campagne_journee');

        $typeLabels = [
            'zakat_el_fitr' => 'Zakat el-fitr',
            'collecte_alimentaire' => 'Collecte alimentaire',
            'don_ponctuel' => 'Don ponctuel',
        ];

        $groupes = $journees
            ->groupBy('id_campagne')
            ->map(function ($groupe) use ($typeLabels, $disponibilites) {
                $campagne = $groupe->first()->campagne;

                return [
                    'libelle' => ($typeLabels[$campagne->type] ?? $campagne->type) . ' — ' . $campagne->date_livraison->format('d/m/Y'),
                    'journees' => $groupe->sortBy('ordre')->map(fn(CampagneJournee $j) => [
                        'id' => $j->id,
                        'libelle' => ($j->label ?? 'Journée') . ' — ' . $j->date->format('d/m/Y')
                            . ($disponibilites->has($j->id) ? ' ✓' : ''),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        $etats = $journees->mapWithKeys(fn(CampagneJournee $j) => [
            $j->id => $this->disponibiliteService->etatFormulaire($disponibilites->get($j->id)),
        ])->all();

        $demandee = $request->integer('id_campagne_journee');
        $selectionnee = $journees->contains('id', $demandee) ? $demandee : $journees->first()?->id;

        return ['groupes' => $groupes, 'etats' => $etats, 'selectionnee' => $selectionnee];
    }

    /**
     * Enregistre le véhicule (permis + type) et la couverture (secteurs)
     * d'une personne pour UNE journée, depuis la fiche personne
     * (01/10/2026). Une modification admin vaut confirmation : la
     * disponibilité passe à `confirme` (créneaux intacts — ils se règlent
     * depuis Suivi des bénévoles). Mêmes règles que la page du bénévole
     * (BenevoleDisponibiliteService::validateur()).
     */
    public function majDisponibilite(Request $request, int $id, int $idJournee): JsonResponse
    {
        $personne = Personne::findOrFail($id);
        $profil = BenevoleProfil::where('id_personne', $personne->id)->first();
        abort_unless($profil, 404);

        $journee = CampagneJournee::findOrFail($idJournee);

        $validator = $this->disponibiliteService->validateur($request->all());
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $avant = BenevoleDisponibilite::where('id_personne', $personne->id)
            ->where('id_campagne_journee', $journee->id)
            ->first()?->toArray();

        $disponibilite = $this->disponibiliteService->enregistrerInformations(
            $personne->id,
            $journee,
            $validator->validated(),
        );

        audit('update', 'familles_personnes', $personne->id, $avant, [
            'action' => 'disponibilité journée (véhicule/couverture)',
            'id_campagne_journee' => $journee->id,
        ]);

        return response()->json([
            'success' => true,
            'etat' => $this->disponibiliteService->etatFormulaire($disponibilite->load('secteurs')),
        ]);
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
     * Désactive la personne POUR Familles (03/10/2026, remplace l'ancien
     * « Révoquer l'accès » qui supprimait ses rôles) : plus de connexion et
     * plus proposable pour une campagne, mais rôles et historique intacts
     * — voir PersonneActivationService et App\Models\PersonneDesactivee.
     * Le compte ref_personnes partagé n'est jamais touché.
     *
     * Refusée si la personne est encore engagée sur une campagne non
     * terminée : la page se rouvre avec la liste de TOUTES les campagnes
     * concernées (session 'desactivation_bloquee', rendue en bandeau par
     * personnes/index.blade.php). Refusée aussi pour soi-même (l'acteur est
     * forcément un admin actif, il en reste donc toujours au moins un).
     */
    public function desactiver(int $id): RedirectResponse
    {
        $personne = Personne::findOrFail($id);
        $nom = "{$personne->prenom} {$personne->nom}";

        if ((int) auth()->id() === $personne->id) {
            return redirect()->route('admin.personnes.index')
                ->with('error', 'Vous ne pouvez pas désactiver votre propre compte.');
        }

        if (PersonneDesactivee::estDesactivee($personne->id)) {
            return redirect()->route('admin.personnes.index')
                ->with('warning', "{$nom} est déjà désactivé(e).");
        }

        $bloquantes = $this->activationService->desactiver($personne->id, (int) auth()->id());

        if ($bloquantes !== []) {
            return redirect()->route('admin.personnes.index')
                ->with('desactivation_bloquee', ['personne' => $nom, 'campagnes' => $bloquantes]);
        }

        audit('update', 'familles_personnes', $personne->id, ['desactivee' => false], ['desactivee' => true]);

        return redirect()->route('admin.personnes.index')
            ->with('success', "{$nom} est désactivé(e) : plus d'accès à AMANA Familles ni de nouvelle campagne, son historique est conservé.");
    }

    public function reactiver(int $id): RedirectResponse
    {
        $personne = Personne::findOrFail($id);

        $this->activationService->reactiver($personne->id);

        audit('update', 'familles_personnes', $personne->id, ['desactivee' => true], ['desactivee' => false]);

        return redirect()->route('admin.personnes.index', ['desactives' => 1])
            ->with('success', "{$personne->prenom} {$personne->nom} est réactivé(e) avec ses rôles d'origine.");
    }
}
