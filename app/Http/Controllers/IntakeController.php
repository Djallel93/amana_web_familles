<?php
// app/Http/Controllers/IntakeController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ResoudreAdresseFamille;
use App\Models\Famille;
use App\Models\FamilleDocument;
use App\Services\FamilleUpsertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Formulaire public d'intake multilingue (FR/AR/EN, RTL pour l'arabe) —
 * remplace les Google Forms de l'ancien système (section 8.2 du prompt de
 * migration). Reconstruit en Vue dans cette app, servi par une seule vue
 * Blade paramétrée par langue.
 *
 * Reprend la logique de dédup de amana_familles (utilsIO.js
 * findDuplicateFamily) : priorité email, puis téléphone + nom. Un doublon
 * met à jour le dossier existant plutôt que d'en créer un second — mêmes
 * règles que l'ancien processInsert()/processGoogleFormSubmission().
 *
 * Contrairement à l'ancien système (géocodage synchrone via l'API Google
 * Maps pendant la requête), la résolution géographique est déclenchée de
 * façon asynchrone après l'enregistrement (ResoudreAdresseFamille) — la
 * famille est créée immédiatement avec id_quartier=null, résolu ensuite.
 */
class IntakeController extends Controller
{
    private const LANGUES_VALIDES = ['fr', 'ar', 'en'];

    public function __construct(
        private readonly FamilleUpsertService $upsertService,
    ) {
    }

    public function showForm(string $langue = 'fr'): View
    {
        if (!in_array($langue, self::LANGUES_VALIDES, true)) {
            $langue = 'fr';
        }

        return view('intake.show', ['langue' => $langue]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nom' => ['required', 'string', 'max:150'],
            'prenom' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'telephone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s().-]{6,}$/'],
            'telephone_bis' => ['nullable', 'string', 'max:30'],
            'zakat_el_fitr' => ['boolean'],
            'sadaqa' => ['boolean'],
            'nombre_adulte' => ['required', 'integer', 'min:0', 'max:255'],
            'nombre_enfant' => ['required', 'integer', 'min:0', 'max:255'],
            'adresse' => ['required', 'string'],
            'code_postal' => ['required', 'string', 'max:10'],
            'ville_texte' => ['required', 'string', 'max:150'],
            'se_deplace' => ['boolean'],
            'circonstances' => ['nullable', 'string'],
            'ressentit' => ['nullable', 'string'],
            'specificites' => ['nullable', 'string'],
            'langue' => ['required', 'string', 'in:fr,ar,en'],
            'hosted' => ['boolean'],
            'hosted_by' => ['nullable', 'string', 'max:255'],
            'working' => ['boolean'],
            'work_days' => ['nullable', 'integer', 'min:0', 'max:7'],
            'work_sector' => ['nullable', 'string', 'max:150'],
            'other_aid' => ['boolean'],
            'consentement' => ['required', 'accepted'],
            'documents_identite' => ['required', 'array', 'min:1'],
            'documents_identite.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            'documents_aides_etat' => ['nullable', 'array'],
            'documents_aides_etat.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            'documents_resource' => ['nullable', 'array'],
            'documents_resource.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ], [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'telephone.required' => 'Le téléphone est obligatoire.',
            'telephone.regex' => 'Numéro de téléphone invalide.',
            'email.email' => 'Format d\'email invalide.',
            'adresse.required' => 'L\'adresse est obligatoire.',
            'code_postal.required' => 'Le code postal est obligatoire.',
            'ville_texte.required' => 'La ville est obligatoire.',
            'consentement.required' => 'Vous devez accepter le traitement de vos données pour continuer.',
            'consentement.accepted' => 'Vous devez accepter le traitement de vos données pour continuer.',
            'documents_identite.required' => 'Au moins un justificatif d\'identité est obligatoire.',
            'documents_identite.min' => 'Au moins un justificatif d\'identité est obligatoire.',
            'documents_identite.*.mimes' => 'Formats acceptés : PDF, JPG, PNG.',
        ]);

        // Le foyer doit contenir au moins une personne — même règle que
        // validateRequiredFields() (amana_familles).
        $validator->after(function ($validator) use ($request) {
            $total = (int) $request->input('nombre_adulte', 0) + (int) $request->input('nombre_enfant', 0);
            if ($total === 0) {
                $validator->errors()->add('nombre_adulte', 'Le foyer doit contenir au moins une personne.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $donnees = $validator->validated();
        unset($donnees['consentement'], $donnees['documents_identite'], $donnees['documents_aides_etat'], $donnees['documents_resource']);

        $resultat = $this->upsertService->upsert($donnees, ['etat_dossier' => 'En cours', 'criticite' => 0]);
        $famille = $resultat['famille'];
        $creee = $resultat['cree'];

        $this->enregistrerDocuments($famille, $request->file('documents_identite', []), 'identity');
        $this->enregistrerDocuments($famille, $request->file('documents_aides_etat', []), 'aides_etat');
        $this->enregistrerDocuments($famille, $request->file('documents_resource', []), 'resource');

        // Résolution géographique asynchrone (webhook Make.com + ST_Contains)
        // — voir ResoudreAdresseFamille. La famille est visible côté staff
        // immédiatement, id_quartier se remplit dès que le job s'exécute
        // (immédiat en pratique avec QUEUE_CONNECTION=sync par défaut).
        ResoudreAdresseFamille::dispatch($famille->id);

        return response()->json([
            'success' => true,
            'familyId' => $famille->id,
            'created' => $creee,
        ], $creee ? 201 : 200);
    }

    /**
     * @param \Illuminate\Http\UploadedFile[] $fichiers
     */
    private function enregistrerDocuments(Famille $famille, array $fichiers, string $type): void
    {
        foreach ($fichiers as $fichier) {
            if (!$fichier || !$fichier->isValid()) {
                continue;
            }

            $path = $fichier->store("familles/{$famille->id}", 'local');

            FamilleDocument::create([
                'id_famille' => $famille->id,
                'type' => $type,
                'disk_path' => $path,
                'original_name' => $fichier->getClientOriginalName(),
                'mime_type' => $fichier->getClientMimeType(),
                'uploaded_at' => now(),
            ]);
        }
    }
}
