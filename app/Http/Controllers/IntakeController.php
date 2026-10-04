<?php
// app/Http/Controllers/IntakeController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use Amana\Shared\Models\Setting;
use App\Models\IntakeConsentRefusal;
use App\Models\Organisation;
use App\Models\OrganismeAide;
use App\Models\SecteurActivite;
use App\Notifications\IntakeConfirmationNotification;
use App\Services\IntakeAttenteService;
use App\Support\FamilleIntakeRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Formulaire public d'intake multilingue (FR/AR/EN, RTL pour l'arabe) —
 * remplace les Google Forms de l'ancien système (section 8.2 du prompt de
 * migration). Reconstruit en Vue (assistant pas-à-pas) dans cette app,
 * servi par une seule vue Blade paramétrée par langue.
 *
 * Reprend le branchement exact du Google Form historique
 * (formulaire_famille_fr/en/ar.json — mêmes goToSectionId sur les 3
 * langues) :
 *  - Consentement RGPD en première question ; un refus ne collecte RIEN
 *    d'autre — voir refuserConsentement().
 *  - Hébergement (organisation/proche/non) : "par qui" seulement si
 *    organisation.
 *  - Type de pièce d'identité : Nationalité/Titre de séjour/Demande d'asile
 *    → justificatif CAF requis ; Autre → justificatif AME requis à la place.
 *  - Activité : temps plein/partiel/non — jours/semaine seulement si
 *    partiel, secteur d'activité si plein OU partiel (pas si non).
 *
 * Reprend la logique de dédup de amana_familles (utilsIO.js
 * findDuplicateFamily) : priorité email, puis téléphone + nom — voir
 * FamilleUpsertService.
 *
 * Contrairement à l'ancien système (géocodage synchrone via l'API Google
 * Maps pendant la requête), la résolution géographique est déclenchée de
 * façon asynchrone après l'enregistrement (ResoudreAdresseFamille).
 *
 * Depuis le 11/08/2026, store() NE crée PLUS le dossier Famille
 * directement : la soumission est stockée en attente
 * (IntakeDemandeAttente, valable 48h) et un email de confirmation est
 * envoyé à l'adresse fournie. Le dossier (upsert + documents + notification
 * staff + résolution géographique) n'est créé qu'au clic sur le lien de
 * confirmation — voir IntakeConfirmationController::confirmer() et
 * IntakeAttenteService, qui portent désormais cette logique.
 */
class IntakeController extends Controller
{
    private const LANGUES_VALIDES = ['fr', 'ar', 'en'];

    public function __construct(
        private readonly IntakeAttenteService $attenteService,
    ) {}

    /**
     * Section E4 du refactor (16/09/2026, dernier chunk public de la
     * section) — page Inertia, remplace resources/views/intake/
     * show.blade.php (supprimée dans ce même chunk). Formulaire public :
     * réutilise app-public.blade.php (racine sans sidebar/auth, créée
     * dans le chunk bénévole précédent — voir son docblock) via
     * rootView()/withViewData(), même mécanisme exact que
     * BenevoleIntakeController::showForm().
     *
     * L'état "inscription fermée" (Setting) reste une View Blade
     * classique (intake.suspendue, partagée avec BenevoleIntakeController)
     * — même raisonnement que là-bas : page statique sans interactivité,
     * pas de gain à la convertir. showForm() a donc un type de retour
     * View|InertiaResponse, comme BenevoleIntakeController::showForm().
     */
    public function showForm(string $langue = 'fr'): View|InertiaResponse
    {
        if (!in_array($langue, self::LANGUES_VALIDES, true)) {
            $langue = 'fr';
        }

        // Interrupteur "Inscription des familles ouverte" (Paramètres,
        // ajouté le 29/08/2026) — coupe l'accès au formulaire public sans
        // toucher aux dossiers déjà enregistrés. Vérifié aussi côté
        // store() ci-dessous en défense en profondeur (accès direct à la
        // route POST, contournant showForm()).
        if (Setting::get('inscription_familles_ouverte', 'familles') === false) {
            return view('intake.suspendue', ['formulaire' => 'familles']);
        }

        return Inertia::render('Intake/Show', [
            'langue' => $langue,
            'storeUrl' => route('intake.store'),
            'refusUrl' => route('intake.refus-consentement'),
            'secteursActivite' => SecteurActivite::actifs()->get(['id', 'code', 'libelle_fr', 'libelle_ar', 'libelle_en']),
            'organismesAide' => OrganismeAide::actifs()->get(['id', 'code', 'libelle_fr', 'libelle_ar', 'libelle_en']),
            // Étape "organisation" (ajoutée le 28/08/2026) — liste fermée,
            // pas de saisie libre (voir migration create_organisations_domain_tables.php)
            // : seules les organisations avec de vrais comptes
            // gestionnaire_externe doivent apparaître ici.
            'organisations' => Organisation::actifs()->orderBy('nom')->get(['id', 'code', 'nom']),
            'googlePlacesApiKey' => config('services.google.maps.places_api_key'),
        ])
            ->rootView('app-public')
            ->withViewData([
                'langue' => $langue,
                'titre' => "AMANA Familles — Demande d'aide",
                'tagline' => "Formulaire d'inscription",
                'langueSwitchRoute' => 'intake.show',
            ]);
    }

    /**
     * Étape 0 : refus du consentement RGPD (radio "Je refuse...", section
     * "Refus" du Google Form). Aucune donnée personnelle n'est collectée à
     * ce stade côté frontend, donc rien d'autre à valider ici — seule la
     * langue accompagne la requête pour information.
     */
    public function refuserConsentement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'langue' => ['required', 'string', 'in:fr,ar,en'],
        ]);

        IntakeConsentRefusal::create([
            'langue' => $validated['langue'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function store(Request $request): JsonResponse
    {
        // Piège à robots : champ caché côté Vue (jamais rempli par un
        // humain, invisible en CSS mais présent dans le DOM — voir
        // IntakeForm.vue). Un formulaire soumis avec ce champ non-vide vient
        // d'un bot ; on répond succès pour ne pas l'alerter, sans rien
        // enregistrer ni consommer le quota d'upload (ajout du 09/08/2026,
        // en complément du throttle:5,1 déjà sur cette route).
        if (filled($request->input('site_web'))) {
            return response()->json(['success' => true, 'created' => false]);
        }

        // Défense en profondeur — showForm() bloque déjà l'accès normal au
        // formulaire, mais un POST direct sur cette route contournerait ce
        // garde sans cette vérification (voir Setting::get() ci-dessus).
        if (Setting::get('inscription_familles_ouverte', 'familles') === false) {
            return response()->json([
                'success' => false,
                'message' => "L'inscription est temporairement suspendue.",
            ], 403);
        }

        // Règles/messages/normalisation partagés avec la création par le
        // staff — voir App\Support\FamilleIntakeRules (extraits le
        // 03/10/2026). Le consentement RGPD n'est exigé qu'ici.
        $validator = Validator::make($request->all(), FamilleIntakeRules::rules(avecConsentement: true), FamilleIntakeRules::messages());
        FamilleIntakeRules::ajouterValidationSecteurs($validator, $request);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        ['donnees' => $donnees, 'secteurs' => $secteursActivite, 'organismes' => $organismesAide]
            = FamilleIntakeRules::normaliser($validator->validated());

        // Plus d'upsert ni de documents créés ici depuis le 11/08/2026 : la
        // demande est stockée en attente de confirmation par email (48h) —
        // voir IntakeAttenteService::creerDemande(), qui gère aussi l'écrasement
        // silencieux d'une éventuelle demande non confirmée déjà en attente
        // pour la même famille (même email, ou même téléphone+nom).
        //
        // creerDemande() renvoie désormais ['demande' => ..., 'token' => ...]
        // (jeton EN CLAIR) depuis le 31/08/2026 — $demande->token ne contient
        // plus que le hash, voir App\Support\TokenHasher.
        ['demande' => $demande, 'token' => $tokenEnClair] = $this->attenteService->creerDemande(
            $donnees,
            $secteursActivite,
            $organismesAide,
            $donnees['langue'],
            [
                'identite' => $request->file('documents_identite', []),
                'aide' => $request->file('documents_aide', []),
                'resource' => $request->file('documents_resource', []),
            ],
            [
                'identite' => $request->input('labels_identite', []),
                'aide' => $request->input('labels_aide', []),
                'resource' => $request->input('labels_resource', []),
            ],
        );

        // Le dossier Famille n'existe pas encore : la notification staff et
        // la résolution géographique n'ont donc plus leur place ici — elles
        // sont déclenchées par IntakeConfirmationController::confirmer(),
        // une fois la famille effectivement créée/mise à jour. N'échoue
        // jamais la requête si l'envoi d'email a un problème : la famille ne
        // doit jamais voir une erreur pour cet envoi.
        try {
            Notification::route('mail', $donnees['email'])
                ->notify(new IntakeConfirmationNotification($demande, $tokenEnClair));
        } catch (\Throwable $e) {
            Log::error('[IntakeController] Échec envoi email de confirmation', [
                'id_demande' => $demande->id,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'pending' => true,
        ], 202);
    }
}
