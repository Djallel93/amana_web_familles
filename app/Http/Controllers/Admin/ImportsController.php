<?php
// app/Http/Controllers/Admin/ImportsController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SynchroniserContactGoogle;
use App\Models\FamilleImport;
use App\Models\FamilleImportRow;
use App\Services\FamilleImportRollbackService;
use App\Services\FamilleImportService;
use App\Support\FamilleCsvParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;

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
        private readonly FamilleImportRollbackService $rollbackService,
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
     * Colonnes CSV attendues : voir FamilleCsvParser (logique partagée avec
     * FamilleCsvSeeder, pour ne pas diverger entre les deux chemins d'import).
     */
    private function parserCsv(string $path): array
    {
        return FamilleCsvParser::parse($path);
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

    // ── Rollback ─────────────────────────────────────────────────────────

    public function rollback(int $id): RedirectResponse
    {
        $import = FamilleImport::findOrFail($id);

        try {
            $nombre = $this->rollbackService->annuler($import);
        } catch (RuntimeException $e) {
            return back()->withErrors(['import' => $e->getMessage()]);
        }

        return redirect()->route('admin.imports.show', $import->id)
            ->with('success', "Import annulé : {$nombre} dossier(s) restauré(s) ou supprimé(s).");
    }

    // ── Synchronisation Google Contacts ─────────────────────────────────────

    public function syncGoogleContacts(int $id): RedirectResponse
    {
        $import = FamilleImport::findOrFail($id);

        if ($import->rolled_back_at) {
            return back()->withErrors(['sync' => 'Cet import a été annulé — synchronisation impossible.']);
        }

        $idsFamilles = $import->rows()
            ->where('status', 'success')
            ->whereNotNull('id_famille')
            ->pluck('id_famille');

        foreach ($idsFamilles as $idFamille) {
            SynchroniserContactGoogle::dispatch($idFamille);
        }

        return back()->with('success', "Synchronisation Google Contacts lancée pour {$idsFamilles->count()} dossier(s).");
    }

    public function syncGoogleContactsRow(int $id, int $rowId): RedirectResponse
    {
        $import = FamilleImport::findOrFail($id);
        $row = $import->rows()->findOrFail($rowId);

        if ($import->rolled_back_at) {
            return back()->withErrors(['sync' => 'Cet import a été annulé — synchronisation impossible.']);
        }

        if ($row->status !== 'success' || !$row->id_famille) {
            return back()->withErrors(['sync' => "Cette ligne n'a pas de dossier associé à synchroniser."]);
        }

        SynchroniserContactGoogle::dispatch($row->id_famille);

        return back()->with('success', 'Synchronisation Google Contacts lancée pour ce dossier.');
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
                'id_famille' => $resultat['id_famille'],
                'cree' => $resultat['cree'],
                'donnees_avant' => $resultat['donnees_avant'],
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
