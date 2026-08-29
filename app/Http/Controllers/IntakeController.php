<?php
// app/Http/Controllers/IntakeController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use Amana\Shared\Models\Setting;
use Amana\Shared\Services\PersonneIntakeService;
use App\Models\Famille;
use App\Models\IntakeConsentRefusal;
use App\Models\Organisation;
use App\Models\OrganismeAide;
use App\Models\SecteurActivite;
use App\Notifications\IntakeConfirmationNotification;
use App\Services\IntakeAttenteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

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
    ) {
    }

    public function showForm(string $langue = 'fr'): View
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

        return view('intake.show', [
            'langue' => $langue,
            'secteursActivite' => SecteurActivite::actifs()->get(['id', 'code', 'libelle_fr', 'libelle_ar', 'libelle_en']),
            'organismesAide' => OrganismeAide::actifs()->get(['id', 'code', 'libelle_fr', 'libelle_ar', 'libelle_en']),
            // Étape "organisation" (ajoutée le 28/08/2026) — liste fermée,
            // pas de saisie libre (voir migration create_organisations_table)
            // : seules les organisations avec de vrais comptes
            // gestionnaire_externe doivent apparaître ici.
            'organisations' => Organisation::actifs()->orderBy('nom')->get(['id', 'code', 'nom']),
            'googlePlacesApiKey' => config('services.google.maps.places_api_key'),
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

        // Bloc identité (nom/prenom/email/telephone/langue) : règles
        // communes désormais centralisées dans amana/shared (extrait le
        // 24/08/2026, voir PersonneIntakeService) — pas d'unicité email ici,
        // les familles bénéficiaires n'ont pas de compte ref_personnes.
        // 'nom'/'prenom' passent ensuite de max:100 (défaut du service,
        // pensé pour un compte) à max:150 via l'override juste après : la
        // famille peut saisir un nom composé plus long qu'un nom de compte.
        $validator = Validator::make($request->all(), array_merge(
            PersonneIntakeService::validationRules(telephoneMax: 30),
            [
                'nom' => ['required', 'string', 'max:150'],
                'prenom' => ['required', 'string', 'max:150'],
                'telephone_bis' => ['nullable', 'string', 'max:30'],

                'nombre_adulte' => ['required', 'integer', 'min:0', 'max:255'],
                'nombre_enfant' => ['required', 'integer', 'min:0', 'max:255'],
                'etudiant' => ['boolean'],

                'adresse' => ['required', 'string'],
                'code_postal' => ['required', 'string', 'max:10'],
                'ville_texte' => ['required', 'string', 'max:150'],
                'se_deplace' => ['boolean'],
                'est_hotel' => ['boolean'],

                'circonstances' => ['required', 'string'],

                // ── Organisation ───────────────────────────────────────────
                // Ajouté le 28/08/2026 — liste fermée (voir showForm()),
                // obligatoire : chaque dossier doit être rattaché à une
                // organisation dès sa création.
                'id_organisation' => ['required', 'integer', 'exists:organisations,id'],

                // ── Hébergement ────────────────────────────────────────────
                'type_hebergement' => ['required', 'string', 'in:' . implode(',', Famille::TYPES_HEBERGEMENT)],
                'hosted_by' => ['nullable', 'string', 'max:255', 'required_if:type_hebergement,organisation'],

                // ── Situation administrative ──────────────────────────────
                'type_piece_identite' => ['required', 'string', 'in:' . implode(',', Famille::TYPES_PIECE_IDENTITE)],
                'documents_identite' => ['required', 'array', 'min:1', 'max:5'],
                'documents_identite.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
                'documents_aide' => ['required', 'array', 'min:1', 'max:5'],
                'documents_aide.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],

                // ── Activité professionnelle ───────────────────────────────
                'type_activite' => ['required', 'string', 'in:' . implode(',', Famille::TYPES_ACTIVITE)],
                // Plafonné à 4 (temps partiel ≠ semaine complète) — demande du
                // 09/08/2026, l'ancien Google Form proposait 1/2/3/4/"autre"
                // sans jamais aller jusqu'à 7 non plus.
                'work_days' => ['nullable', 'integer', 'min:0', 'max:4', 'required_if:type_activite,temps_partiel'],
                // Pas de required_unless ici : "au moins un secteur COCHÉ" est
                // trop strict si la famille a rempli secteur_activite_autre à la
                // place — la combinaison des deux est validée ci-dessous via
                // ->after(), pour rester cohérent avec la validation côté Vue.
                'secteurs_activite' => ['nullable', 'array'],
                'secteurs_activite.*' => ['integer', 'exists:secteurs_activite,id'],
                'secteur_activite_autre' => ['nullable', 'string', 'max:150'],

                // ── Ressources ──────────────────────────────────────────────
                // 'nullable' plutôt que 'required' : "aucune aide perçue" est une
                // réponse valide, contrairement au CHECKBOX obligatoire du
                // Google Form d'origine qui ne proposait aucune option "aucune"
                // — corrigé ici (voir échange du 09/08/2026). L'absence de clé
                // (aucune case cochée côté formulaire) est traitée comme un
                // tableau vide dans store().
                'organismes_aide' => ['nullable', 'array'],
                'organismes_aide.*' => ['integer', 'exists:organismes_aide,id'],
                'organisme_aide_autre' => ['nullable', 'string', 'max:150'],
                'documents_resource' => ['nullable', 'array', 'max:10'],
                'documents_resource.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],

                'consentement' => ['required', 'accepted'],
            ],
        ), [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'telephone.required' => 'Le téléphone est obligatoire.',
            'telephone.regex' => 'Numéro de téléphone invalide.',
            'email.required' => "L'email est obligatoire.",
            'email.email' => 'Format d\'email invalide.',
            'adresse.required' => 'L\'adresse est obligatoire.',
            'code_postal.required' => 'Le code postal est obligatoire.',
            'ville_texte.required' => 'La ville est obligatoire.',
            'circonstances.required' => 'Merci de décrire brièvement votre situation.',
            'hosted_by.required_if' => 'Merci d\'indiquer le nom de l\'organisation.',
            'documents_identite.required' => 'Au moins un justificatif d\'identité est obligatoire.',
            'documents_identite.min' => 'Au moins un justificatif d\'identité est obligatoire.',
            'documents_identite.max' => 'Cinq justificatifs d\'identité maximum.',
            'documents_identite.*.mimes' => 'Formats acceptés : PDF, JPG, PNG, DOC, DOCX.',
            'documents_aide.required' => 'Ce justificatif est obligatoire.',
            'documents_aide.min' => 'Ce justificatif est obligatoire.',
            'work_days.required_if' => 'Merci d\'indiquer le nombre de jours travaillés.',
            'consentement.required' => 'Vous devez accepter le traitement de vos données pour continuer.',
            'consentement.accepted' => 'Vous devez accepter le traitement de vos données pour continuer.',
        ]);

        $validator->after(function ($validator) use ($request) {
            $typeActivite = $request->input('type_activite');
            $aSecteur = !empty($request->input('secteurs_activite', []))
                || filled($request->input('secteur_activite_autre'));

            if (in_array($typeActivite, ['temps_plein', 'temps_partiel'], true) && !$aSecteur) {
                $validator->errors()->add('secteurs_activite', 'Merci de sélectionner au moins un secteur, ou de préciser "autre".');
            }
        });

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $donnees = $validator->validated();

        $secteursActivite = array_map('intval', $donnees['secteurs_activite'] ?? []);
        $organismesAide = array_map('intval', $donnees['organismes_aide'] ?? []);
        $donnees['id_organisation'] = (int) $donnees['id_organisation'];
        unset(
            $donnees['consentement'],
            $donnees['documents_identite'],
            $donnees['documents_aide'],
            $donnees['documents_resource'],
            $donnees['secteurs_activite'],
            $donnees['organismes_aide'],
        );

        // "Non" (pas d'activité) : pas de secteur ni de jours, même si
        // envoyés par erreur — cohérence avec le branchement du formulaire.
        if ($donnees['type_activite'] === 'non') {
            $donnees['work_days'] = null;
            $donnees['secteur_activite_autre'] = null;
            $secteursActivite = [];
        } elseif ($donnees['type_activite'] !== 'temps_partiel') {
            $donnees['work_days'] = null;
        }

        if ($donnees['type_hebergement'] !== 'organisation') {
            $donnees['hosted_by'] = null;
        }

        // Plus d'upsert ni de documents créés ici depuis le 11/08/2026 : la
        // demande est stockée en attente de confirmation par email (48h) —
        // voir IntakeAttenteService::creerDemande(), qui gère aussi l'écrasement
        // silencieux d'une éventuelle demande non confirmée déjà en attente
        // pour la même famille (même email, ou même téléphone+nom).
        $demande = $this->attenteService->creerDemande(
            $donnees,
            $secteursActivite,
            $organismesAide,
            $donnees['langue'],
            [
                'identite' => $request->file('documents_identite', []),
                'aide' => $request->file('documents_aide', []),
                'resource' => $request->file('documents_resource', []),
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
                ->notify(new IntakeConfirmationNotification($demande));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[IntakeController] Échec envoi email de confirmation', [
                'token' => $demande->token,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'pending' => true,
        ], 202);
    }
}
