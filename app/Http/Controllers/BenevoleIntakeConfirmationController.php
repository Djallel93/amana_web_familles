<?php
// app/Http/Controllers/BenevoleIntakeConfirmationController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BenevoleDemandeAttente;
use App\Models\Personne;
use App\Notifications\NouvelleCandidatureBenevoleNotification;
use App\Services\BenevoleIntakeAttenteService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

/**
 * Page publique de confirmation d'une candidature bénévole — accessible
 * via le lien reçu par email (BenevoleIntakeConfirmationNotification).
 * Miroir exact d'IntakeConfirmationController (familles) : la Personne et
 * le BenevoleProfil ne sont créés/liés qu'ici, pas à la soumission du
 * formulaire. Token à durée de vie limitée (48h, voir
 * BenevoleIntakeAttenteService::DUREE_VALIDITE_HEURES).
 */
class BenevoleIntakeConfirmationController extends Controller
{
    public function __construct(
        private readonly BenevoleIntakeAttenteService $attenteService,
    ) {
    }

    public function show(string $token): View
    {
        $demande = BenevoleDemandeAttente::where('token', $token)->first();

        if (!$demande) {
            return view('benevole.confirmer', ['etat' => 'introuvable', 'langue' => 'fr']);
        }

        if ($demande->estExpiree()) {
            return view('benevole.confirmer', ['etat' => 'expiree', 'demande' => $demande, 'langue' => $demande->langue]);
        }

        return view('benevole.confirmer', ['etat' => 'a_confirmer', 'demande' => $demande, 'langue' => $demande->langue]);
    }

    public function confirmer(string $token): View
    {
        $demande = BenevoleDemandeAttente::where('token', $token)->first();

        if (!$demande) {
            return view('benevole.confirmer', ['etat' => 'introuvable', 'langue' => 'fr']);
        }

        if ($demande->estExpiree()) {
            return view('benevole.confirmer', ['etat' => 'expiree', 'demande' => $demande, 'langue' => $demande->langue]);
        }

        $langue = $demande->langue;
        $resultat = $this->attenteService->confirmer($demande);
        $personne = $resultat['personne'];

        // Notifie le staff admin — seul rôle avec accès aux routes
        // /admin/benevoles/* (voir routes/web.php : le groupe /admin est
        // restreint à role:admin, contrairement à amana_web_planning).
        try {
            $destinataires = Personne::whereHas('roles', function ($q) {
                $q->where('code', 'admin')
                    ->whereHas('application', fn($q2) => $q2->where('code', 'familles'));
            })->get();

            if ($destinataires->isNotEmpty()) {
                Notification::send($destinataires, new NouvelleCandidatureBenevoleNotification($resultat['profil'], $personne));
            }
        } catch (\Throwable $e) {
            Log::error('[BenevoleIntakeConfirmationController] Échec notification nouvelle candidature', [
                'id_personne' => $personne->id,
                'message' => $e->getMessage(),
            ]);
        }

        return view('benevole.confirmer', ['etat' => 'confirmee', 'personne' => $personne, 'langue' => $langue]);
    }
}
