<?php
// app/Http/Controllers/FamilleCreationController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ResoudreAdresseFamille;
use App\Models\Famille;
use App\Models\FamilleDocument;
use App\Models\Organisation;
use App\Models\OrganismeAide;
use App\Models\SecteurActivite;
use App\Services\FamilleUpsertService;
use App\Support\FamilleIntakeRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Création d'un dossier famille par le staff (gestionnaire et plus) —
 * demandée le 03/10/2026 (bouton « Créer une famille » sur Dossiers
 * familles). Réutilise le formulaire public IntakeForm.vue en « mode
 * staff » et les mêmes règles de validation (FamilleIntakeRules), mais :
 *
 *  - pas de consentement RGPD (le staff saisit pour le compte de la
 *    famille), pas de limite de débit ni de piège à robots ;
 *  - pas d'email de confirmation en attente (IntakeAttenteService) : le
 *    dossier est créé directement, en 'En attente' (déjà vu par le staff,
 *    contrairement à 'Recu' réservé aux soumissions publiques — voir
 *    Famille::ETATS_MODIFIABLES) ;
 *  - un doublon (même email, ou même téléphone + nom — règle de
 *    FamilleUpsertService::trouverDoublon()) est REFUSÉ avec un lien vers
 *    le dossier existant, au lieu d'être fusionné silencieusement comme le
 *    fait le flux public : un agent qui crée « une nouvelle famille » ne
 *    s'attend pas à écraser les données d'un dossier existant.
 */
class FamilleCreationController extends Controller
{
    private const LANGUES_VALIDES = ['fr', 'ar', 'en'];

    public function __construct(
        private readonly FamilleUpsertService $upsertService,
    ) {
    }

    public function create(string $langue = 'fr'): InertiaResponse
    {
        if (!in_array($langue, self::LANGUES_VALIDES, true)) {
            $langue = 'fr';
        }

        return Inertia::render('Familles/Creer', [
            'langue' => $langue,
            'storeUrl' => route('familles.store'),
            'retourUrl' => route('familles.index'),
            'secteursActivite' => SecteurActivite::actifs()->get(['id', 'code', 'libelle_fr', 'libelle_ar', 'libelle_en']),
            'organismesAide' => OrganismeAide::actifs()->get(['id', 'code', 'libelle_fr', 'libelle_ar', 'libelle_en']),
            'organisations' => Organisation::actifs()->orderBy('nom')->get(['id', 'code', 'nom']),
            'googlePlacesApiKey' => config('services.google.maps.places_api_key'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), FamilleIntakeRules::rules(avecConsentement: false), FamilleIntakeRules::messages());
        FamilleIntakeRules::ajouterValidationSecteurs($validator, $request);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        ['donnees' => $donnees, 'secteurs' => $secteurs, 'organismes' => $organismes]
            = FamilleIntakeRules::normaliser($validator->validated());

        $doublon = $this->upsertService->trouverDoublon($donnees);
        if ($doublon) {
            return response()->json([
                'success' => false,
                'message' => 'Une famille correspondant à cet email ou à ce téléphone existe déjà.',
                'doublon' => [
                    'id' => $doublon->id,
                    'nom' => $doublon->nom,
                    'prenom' => $doublon->prenom,
                    'url' => route('familles.index', ['ouvrir' => $doublon->id, 'etat_dossier' => '']),
                ],
            ], 409);
        }

        $resultat = $this->upsertService->upsert(
            $donnees,
            ['etat_dossier' => 'En attente', 'criticite' => 0],
            $secteurs,
            $organismes,
            'manuel',
            (int) auth()->id(),
        );
        $famille = $resultat['famille'];

        $typeDocumentAide = ($donnees['type_piece_identite'] ?? null) === 'autre' ? 'ame' : 'caf';

        $this->enregistrerDocuments($famille, 'identity', $request->file('documents_identite', []), $request->input('labels_identite', []));
        $this->enregistrerDocuments($famille, $typeDocumentAide, $request->file('documents_aide', []), $request->input('labels_aide', []));
        $this->enregistrerDocuments($famille, 'resource', $request->file('documents_resource', []), $request->input('labels_resource', []));

        // Résolution géographique asynchrone, comme à la confirmation d'une
        // demande publique (IntakeConfirmationController). Pas de
        // notification « nouvelle demande » : c'est le staff qui crée.
        ResoudreAdresseFamille::dispatch($famille->id);

        return response()->json([
            'success' => true,
            'id' => $famille->id,
            'redirect' => route('familles.index', ['ouvrir' => $famille->id, 'etat_dossier' => '']),
        ], 201);
    }

    /**
     * @param UploadedFile[] $fichiers
     * @param array<int, string|null> $labels Même index que $fichiers
     */
    private function enregistrerDocuments(Famille $famille, string $type, array $fichiers, array $labels): void
    {
        foreach ($fichiers as $index => $fichier) {
            if (!$fichier || !$fichier->isValid()) {
                continue;
            }

            $document = FamilleDocument::create([
                'id_famille' => $famille->id,
                'type' => $type,
                'disk_path' => $fichier->store("familles/{$famille->id}", 'local'),
                'original_name' => FamilleDocument::nomAvecLabel($labels[$index] ?? null, $fichier->getClientOriginalName()),
                'mime_type' => $fichier->getClientMimeType(),
                'uploaded_at' => now(),
            ]);

            audit('create', 'familles_documents', $document->id, null, $document->toArray());
        }
    }
}
