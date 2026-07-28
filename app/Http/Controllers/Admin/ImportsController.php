<?php
// app/Http/Controllers/Admin/ImportsController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FamilleImport;
use App\Models\FamilleImportRow;
use App\Services\FamilleImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Import/mise à jour en masse — décision 6.9 : deux modes d'alimentation
 * (UI manuelle, upload CSV) branchés sur le MÊME pipeline de traitement par
 * ligne (FamilleImportService::traiterLigne), avec statuts par ligne
 * (pending/success/error/skipped — équivalent du BULK_STATUS de l'ancien
 * système).
 *
 * Traitement synchrone dans la requête HTTP (pas de job dédié) : un import
 * de quelques dizaines/centaines de lignes reste rapide, et ça évite
 * d'avoir à gérer un état "en cours" avec polling côté UI pour ce volume.
 * À revoir si les imports deviennent nettement plus gros.
 */
class ImportsController extends Controller
{
    public function __construct(
        private readonly FamilleImportService $importService,
    ) {
    }

    public function index(): View
    {
        $imports = FamilleImport::withCount([
            'rows',
            'rows as rows_success_count' => fn($q) => $q->where('status', 'success'),
            'rows as rows_error_count' => fn($q) => $q->where('status', 'error'),
            'rows as rows_skipped_count' => fn($q) => $q->where('status', 'skipped'),
        ])->orderByDesc('created_at')->paginate(20);

        return view('admin.imports.index', compact('imports'));
    }

    public function create(): View
    {
        return view('admin.imports.create');
    }

    public function show(int $id): View
    {
        $import = FamilleImport::with('rows')->findOrFail($id);

        return view('admin.imports.show', compact('import'));
    }

    // ── Upload CSV ────────────────────────────────────────────────────────

    public function storeCsv(Request $request): RedirectResponse
    {
        $request->validate([
            'fichier' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ], [
            'fichier.required' => 'Merci de sélectionner un fichier CSV.',
            'fichier.mimes' => 'Le fichier doit être au format CSV.',
            'fichier.max' => 'Le fichier ne doit pas dépasser 5 Mo.',
        ]);

        $lignes = $this->parserCsv($request->file('fichier')->getRealPath());

        if (empty($lignes)) {
            return back()->withErrors(['fichier' => 'Le fichier CSV est vide ou illisible.']);
        }

        $import = $this->traiterImport($lignes, 'csv');

        return redirect()->route('admin.imports.show', $import->id)
            ->with('success', "Import terminé : {$import->rows_success_count} réussi(s), {$import->rows_error_count} en erreur.");
    }

    /**
     * Colonnes CSV attendues (en-tête, insensible à la casse) : nom, prenom,
     * telephone, email, telephone_bis, adresse, code_postal, ville,
     * nombre_adulte, nombre_enfant, zakat_el_fitr, sadaqa, se_deplace,
     * criticite, langue, etat_dossier, commentaire_dossier.
     * "ville" (pas "ville_texte") côté CSV pour rester lisible côté staff ;
     * mappé vers ville_texte en interne.
     */
    private function parserCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            return [];
        }

        $entetes = fgetcsv($handle, 0, ';') ?: fgetcsv($handle, 0, ',');
        if (!$entetes) {
            fclose($handle);
            return [];
        }
        // Détection automatique du séparateur (';' usage courant Excel FR, ',' sinon).
        $separateur = count($entetes) > 1 ? ';' : ',';
        if ($separateur === ',') {
            rewind($handle);
            $entetes = fgetcsv($handle, 0, ',');
        }

        $entetes = array_map(fn($e) => strtolower(trim((string) $e)), $entetes);
        $mapVille = array_search('ville', $entetes, true);
        if ($mapVille !== false) {
            $entetes[$mapVille] = 'ville_texte';
        }

        $champsBooleens = ['zakat_el_fitr', 'sadaqa', 'se_deplace'];
        $lignes = [];

        while (($valeurs = fgetcsv($handle, 0, $separateur)) !== false) {
            if (count($valeurs) === 1 && trim((string) $valeurs[0]) === '') {
                continue; // ligne vide
            }
            $ligne = [];
            foreach ($entetes as $i => $cle) {
                $valeur = trim((string) ($valeurs[$i] ?? ''));
                if (in_array($cle, $champsBooleens, true)) {
                    $valeur = in_array(strtolower($valeur), ['1', 'oui', 'yes', 'true', 'vrai', 'x'], true) ? '1' : '0';
                }
                $ligne[$cle] = $valeur;
            }
            $lignes[] = $ligne;
        }

        fclose($handle);
        return $lignes;
    }

    // ── Saisie manuelle (UI, plusieurs lignes) ──────────────────────────────

    public function storeManuel(Request $request): JsonResponse
    {
        $request->validate([
            'lignes' => ['required', 'array', 'min:1'],
        ]);

        $import = $this->traiterImport($request->input('lignes'), 'manual');

        return response()->json([
            'importId' => $import->id,
            'redirect' => route('admin.imports.show', $import->id),
        ]);
    }

    // ── Pipeline commun ──────────────────────────────────────────────────

    private function traiterImport(array $lignes, string $source): FamilleImport
    {
        $import = FamilleImport::create([
            'type' => 'import',
            'source' => $source,
            'uploaded_by' => Auth::id(),
            'status' => 'pending',
        ]);

        foreach ($lignes as $i => $payload) {
            $resultat = $this->importService->traiterLigne($payload);

            FamilleImportRow::create([
                'id_import' => $import->id,
                'row_number' => $i + 1,
                'payload' => $payload,
                'status' => $resultat['status'],
                'error_message' => $resultat['error_message'],
            ]);
        }

        $import->status = 'terminé';
        $import->save();

        audit('create', 'familles_import', $import->id, null, [
            'source' => $source,
            'nombre_lignes' => count($lignes),
        ]);

        return $import->loadCount([
            'rows',
            'rows as rows_success_count' => fn($q) => $q->where('status', 'success'),
            'rows as rows_error_count' => fn($q) => $q->where('status', 'error'),
            'rows as rows_skipped_count' => fn($q) => $q->where('status', 'skipped'),
        ]);
    }
}
