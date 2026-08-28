<?php
// app/Http/Controllers/Admin/RattachementsController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FamilleOrganisationDemande;
use App\Services\FamilleOrganisationDemandeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Revue des demandes de rattachement d'organisation — décision du
 * 28/08/2026 (voir échange sur le multi-organisation) : quand une
 * organisation B soumet/importe une famille déjà rattachée à une
 * organisation A, le dossier n'est pas modifié et B n'obtient pas
 * automatiquement l'accès — un admin/gestionnaire (staff interne
 * uniquement, jamais un gestionnaire_externe même de l'organisation A
 * déjà rattachée) doit valider ici.
 *
 * Miroir de Admin\BenevoleCandidaturesController (liste/valider/rejeter) —
 * routé sous /rattachements avec role:gestionnaire (admin + gestionnaire),
 * PAS sous /admin (role:admin uniquement dans cette app) : voir routes/web.php.
 */
class RattachementsController extends Controller
{
    public function __construct(
        private readonly FamilleOrganisationDemandeService $demandeService,
    ) {
    }

    public function index(): View
    {
        $demandes = FamilleOrganisationDemande::with(['famille', 'organisation'])
            ->enAttente()
            ->orderBy('created_at')
            ->paginate(30);

        return view('admin.rattachements.index', compact('demandes'));
    }

    public function valider(FamilleOrganisationDemande $demande): RedirectResponse
    {
        $this->demandeService->valider($demande, Auth::id());

        return back()->with('success', "Rattachement de « {$demande->organisation->nom} » validé pour le dossier de {$demande->famille->prenom} {$demande->famille->nom}.");
    }

    public function rejeter(FamilleOrganisationDemande $demande): RedirectResponse
    {
        $this->demandeService->rejeter($demande, Auth::id());

        return back()->with('success', 'Demande de rattachement rejetée.');
    }
}
