<?php
// app/Http/Controllers/IntakeConfirmationController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ResoudreAdresseFamille;
use App\Models\IntakeDemandeAttente;
use App\Models\Personne;
use App\Notifications\NouvelleDemandeFamilleNotification;
use App\Services\IntakeAttenteService;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

/**
 * Page publique de confirmation d'une demande d'aide — accessible via le
 * lien reçu par email (IntakeConfirmationNotification), ajoutée le
 * 11/08/2026 devant IntakeController::store() : le dossier Famille n'est
 * créé (ou mis à jour, en cas de doublon) qu'ici, pas à la soumission du
 * formulaire. Token à durée de vie limitée (48h, voir
 * IntakeAttenteService::DUREE_VALIDITE_HEURES).
 *
 * Reprend ce qui se passait auparavant en fin d'IntakeController::store() :
 * notification staff + résolution géographique asynchrone — désormais
 * déclenchées seulement quand la famille a effectivement confirmé.
 */
class IntakeConfirmationController extends Controller
{
    public function __construct(
        private readonly IntakeAttenteService $attenteService,
    ) {
    }

    public function show(string $token): View
    {
        $demande = IntakeDemandeAttente::where('token', $token)->first();

        if (!$demande) {
            return view('intake.confirmer', ['etat' => 'introuvable', 'langue' => 'fr']);
        }

        if ($demande->estExpiree()) {
            return view('intake.confirmer', ['etat' => 'expiree', 'demande' => $demande, 'langue' => $demande->langue]);
        }

        return view('intake.confirmer', ['etat' => 'a_confirmer', 'demande' => $demande, 'langue' => $demande->langue]);
    }

    public function confirmer(string $token): View
    {
        $demande = IntakeDemandeAttente::where('token', $token)->first();

        if (!$demande) {
            return view('intake.confirmer', ['etat' => 'introuvable', 'langue' => 'fr']);
        }

        if ($demande->estExpiree()) {
            return view('intake.confirmer', ['etat' => 'expiree', 'demande' => $demande, 'langue' => $demande->langue]);
        }

        $langue = $demande->langue;
        $resultat = $this->attenteService->confirmer($demande);
        $famille = $resultat['famille'];

        // Rattachement en attente de validation staff (organisation
        // différente de celle(s) déjà rattachées au dossier trouvé — voir
        // FamilleUpsertService::upsert()) : le dossier existant n'a pas été
        // modifié, pas de notification "nouvelle demande" ni de résolution
        // d'adresse à déclencher pour un dossier qui n'a pas bougé. Écran
        // dédié plutôt que 'confirmee', pour ne pas laisser croire à la
        // famille qu'un nouveau dossier vient d'être créé pour cette
        // organisation.
        if ($resultat['rattachement_en_attente']) {
            return view('intake.confirmer', ['etat' => 'rattachement_en_attente', 'langue' => $langue]);
        }

        // Notifie le staff (admin + gestionnaire) — désormais déclenché ici,
        // une fois la famille effectivement créée/mise à jour, plutôt qu'à
        // la simple soumission du formulaire (voir IntakeController::store()).
        try {
            $destinataires = Personne::whereHas('roles', function ($q) {
                $q->whereIn('code', ['admin', 'gestionnaire'])
                    ->whereHas('application', fn($q2) => $q2->where('code', 'familles'));
            })->get();

            if ($destinataires->isNotEmpty()) {
                Notification::send($destinataires, new NouvelleDemandeFamilleNotification($famille));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[IntakeConfirmationController] Échec notification nouvelle demande', [
                'id_famille' => $famille->id,
                'message' => $e->getMessage(),
            ]);
        }

        // Résolution géographique asynchrone (webhook Make.com + ST_Contains)
        // — voir ResoudreAdresseFamille.
        ResoudreAdresseFamille::dispatch($famille->id);

        return view('intake.confirmer', ['etat' => 'confirmee', 'famille' => $famille, 'langue' => $langue]);
    }
}
