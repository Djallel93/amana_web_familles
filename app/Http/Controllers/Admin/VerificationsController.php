<?php
// app/Http/Controllers/Admin/VerificationsController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FamilleVerification;
use App\Services\FamilleVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Déclenchement manuel des envois de vérification par lot — remplace
 * l'endpoint API sendverificationemails de l'ancien système. Reste une
 * action staff explicite (bouton), pas une tâche 100% automatique, pour
 * garder le contrôle sur le moment de l'envoi (comme l'original, qui était
 * lui aussi déclenché manuellement par un admin Google Apps Script).
 */
class VerificationsController extends Controller
{
    public function __construct(
        private readonly FamilleVerificationService $verificationService,
    ) {
    }

    public function index(): View
    {
        $verifications = FamilleVerification::with('famille')
            ->orderByDesc('created_at')
            ->paginate(30);

        return view('admin.verifications.index', compact('verifications'));
    }

    public function envoyer(): RedirectResponse
    {
        $resultats = $this->verificationService->envoyerParLot();

        audit('create', 'familles_verification_lot', null, null, $resultats);

        return redirect()->route('admin.verifications.index')->with(
            'success',
            "Envoi terminé : {$resultats['envoyes']} envoyé(s), {$resultats['ignores']} ignoré(s) (déjà en cours ou récemment confirmé), {$resultats['echecs']} échec(s)."
        );
    }
}
