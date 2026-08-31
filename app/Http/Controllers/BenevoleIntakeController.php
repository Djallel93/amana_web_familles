<?php
// app/Http/Controllers/BenevoleIntakeController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use Amana\Shared\Models\Secteur;
use Amana\Shared\Models\Setting;
use Amana\Shared\Models\VehiculeType;
use Amana\Shared\Services\PersonneIntakeService;
use App\Models\BenevoleConsentRefusal;
use App\Models\Organisation;
use App\Notifications\BenevoleIntakeConfirmationNotification;
use App\Services\BenevoleIntakeAttenteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Formulaire public de candidature bénévole (fr/ar/en) — reconstruit
 * depuis l'ancien Google Form (amana_benevoles), voir le prompt de
 * migration du 24/08/2026 pour le détail du branchement retenu. Même
 * squelette que IntakeController (familles) : soumission → attente 48h →
 * email de confirmation → BenevoleIntakeConfirmationController::confirmer()
 * crée/lie réellement la Personne + le BenevoleProfil.
 *
 * Révisions du 24/08/2026 (retour utilisateur après premier essai) :
 *  - Ajout de refuserConsentement() (bouton "Je refuse" manquant).
 *  - vehicule_type/capacite_kg/nombre_part_max (saisie libre) remplacés
 *    par id_vehicule_type (sélection dans le référentiel ref_vehicules,
 *    capacité définie par le staff — voir VehiculeTypesController).
 *  - Secteurs enrichis d'un libellé "{Ville} - {Secteur}" (plusieurs
 *    secteurs partagent le même nom court, ex. "Centre").
 *  - Disponibilités retirées (fonctionnalité event-related future).
 */
class BenevoleIntakeController extends Controller
{
    private const LANGUES_VALIDES = ['fr', 'ar', 'en'];

    public function __construct(
        private readonly BenevoleIntakeAttenteService $attenteService,
    ) {
    }

    public function showForm(string $langue = 'fr'): View
    {
        if (!in_array($langue, self::LANGUES_VALIDES, true)) {
            $langue = 'fr';
        }

        // Interrupteur "Inscription des bénévoles ouverte" (Paramètres) —
        // miroir de IntakeController::showForm() (familles), voir ce
        // commentaire là-bas pour le raisonnement.
        if (Setting::get('inscription_benevoles_ouverte', 'familles') === false) {
            return view('intake.suspendue', ['formulaire' => 'benevoles']);
        }

        return view('benevole.show', [
            'langue' => $langue,
            'secteurs' => Secteur::with('ville')->orderBy('nom')->get(['id', 'nom', 'id_ville'])
                // Libellé "{Ville} - {Secteur}" : plusieurs secteurs de villes
                // différentes partagent le même nom (ex. "Centre"), voir
                // retour du 24/08/2026 — le nom seul prêtait à confusion.
                ->map(fn($secteur) => [
                    'id' => $secteur->id,
                    'libelle' => ($secteur->ville?->nom ?? '?') . ' - ' . $secteur->nom,
                ])
                ->sortBy('libelle')
                ->values(),
            'vehicules' => VehiculeType::orderBy('id')->get(['id', 'type', 'capacite_kg', 'nombre_part_max']),
            // Question "organisation" ajoutée le 28/08/2026 — obligatoire,
            // une seule organisation par bénévole (contrairement au dossier
            // famille, pas de dédup multi-organisation ici, voir migration
            // create_benevole_profil_organisation_table).
            'organisations' => Organisation::actifs()->orderBy('nom')->get(['id', 'code', 'nom']),
        ]);
    }

    /**
     * Étape 0 : refus du consentement RGPD — miroir exact d'
     * IntakeController::refuserConsentement() (familles). Aucune donnée
     * personnelle n'est collectée à ce stade côté frontend.
     */
    public function refuserConsentement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'langue' => ['required', 'string', 'in:fr,ar,en'],
        ]);

        BenevoleConsentRefusal::create([
            'langue' => $validated['langue'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function store(Request $request): JsonResponse
    {
        // Même piège à robots que IntakeController::store() (champ site_web,
        // jamais rempli par un humain — voir BenevoleForm.vue).
        if (filled($request->input('site_web'))) {
            return response()->json(['success' => true, 'created' => false]);
        }

        // Défense en profondeur, miroir de IntakeController::store().
        if (Setting::get('inscription_benevoles_ouverte', 'familles') === false) {
            return response()->json([
                'success' => false,
                'message' => "L'inscription est temporairement suspendue.",
            ], 403);
        }

        $validator = Validator::make($request->all(), array_merge(
            PersonneIntakeService::validationRules(telephoneMax: 30),
            [
                'permis' => ['required', 'boolean'],
                // Type de véhicule : sélection dans le référentiel ref_vehicules
                // (amana/shared) — capacite_kg/nombre_part_max ne sont plus
                // saisis ici, voir docblock de classe.
                'id_vehicule_type' => ['required', 'integer', 'exists:commun.ref_vehicules,id'],

                // Organisation — ajoutée le 28/08/2026, obligatoire (voir
                // showForm()).
                'id_organisation' => ['required', 'integer', 'exists:organisations,id'],

                // Zone de livraison — même branchement que l'ancien Google
                // Form : "certains lieux spécifiques" impose une sélection
                // d'au moins un secteur, les deux autres choix ne demandent
                // rien de plus (voir ->after() ci-dessous pour "Nantes +
                // extérieur" = tous secteurs, "Nantes seulement" = laissé
                // vide ici, résolu côté service si besoin plus tard).
                // Uniquement requis si le candidat a le permis (30/08/2026) —
                // étape masquée côté formulaire sinon, voir BenevoleForm.vue
                // (visibleSteps) : sans permis, pas de livraisons, donc pas
                // de zone à choisir. `$secteurs` retombe sur [] via le
                // `default` du match() ci-dessous quand le champ est absent.
                'zone_livraison' => ['required_if:permis,1', 'nullable', 'string', 'in:nantes_et_exterieur,nantes_seulement,secteurs_specifiques'],
                'secteurs' => ['nullable', 'array'],
                'secteurs.*' => ['integer', 'exists:commun.secteurs,id'],

                'consentement' => ['required', 'accepted'],
            ],
        ), [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'telephone.required' => 'Le téléphone est obligatoire.',
            'telephone.regex' => 'Numéro de téléphone invalide.',
            'email.required' => "L'email est obligatoire.",
            'email.email' => 'Format d\'email invalide.',
            'id_vehicule_type.required' => 'Merci de sélectionner un type de véhicule.',
            'zone_livraison.required' => 'Merci de sélectionner une zone de livraison.',
            'zone_livraison.required_if' => 'Merci de sélectionner une zone de livraison.',
            'consentement.required' => 'Vous devez accepter le traitement de vos données pour continuer.',
            'consentement.accepted' => 'Vous devez accepter le traitement de vos données pour continuer.',
        ]);

        $validator->after(function ($validator) use ($request) {
            if ($request->input('zone_livraison') === 'secteurs_specifiques' && empty($request->input('secteurs', []))) {
                $validator->errors()->add('secteurs', 'Merci de sélectionner au moins un secteur.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $donnees = $validator->validated();

        // "Nantes + extérieur" couvre tous les secteurs existants ; "Nantes
        // seulement" (sans détail) ne pré-sélectionne aucun secteur — le
        // staff affine à la validation si besoin. Voir Secteur (amana/shared).
        // Sans permis (30/08/2026), le champ n'est pas envoyé du tout par le
        // formulaire (voir BenevoleForm.vue::submit) — ->validated() omet
        // alors purement et simplement la clé plutôt que de la mettre à
        // null, d'où le ?? null explicite ici (accès direct à
        // $donnees['zone_livraison'] plantait avec "Undefined array key").
        $secteurs = match ($donnees['zone_livraison'] ?? null) {
            'nantes_et_exterieur' => Secteur::pluck('id')->all(),
            'secteurs_specifiques' => array_map('intval', $donnees['secteurs'] ?? []),
            default => [],
        };

        unset($donnees['consentement'], $donnees['secteurs'], $donnees['zone_livraison']);

        // creerDemande() renvoie désormais ['demande' => ..., 'token' => ...]
        // (jeton EN CLAIR) depuis le 31/08/2026 — $demande->token ne
        // contient plus que le hash, voir App\Support\TokenHasher.
        ['demande' => $demande, 'token' => $tokenEnClair] = $this->attenteService->creerDemande($donnees, $secteurs, $donnees['langue']);

        try {
            Notification::route('mail', $donnees['email'])
                ->notify(new BenevoleIntakeConfirmationNotification($demande, $tokenEnClair));
        } catch (\Throwable $e) {
            Log::error('[BenevoleIntakeController] Échec envoi email de confirmation', [
                'id_demande' => $demande->id,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json(['success' => true, 'pending' => true], 202);
    }
}
