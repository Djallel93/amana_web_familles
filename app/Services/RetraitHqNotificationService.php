<?php
// app/Services/RetraitHqNotificationService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Livraison;
use App\Notifications\RetraitHqNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Envoi de RetraitHqNotification — voir le prompt du 24/09/2026
 * §Additional points 1/6. Même garde-fou "pas d'email = no-op silencieux"
 * que App\Services\ContactTokenService::emettrePour() : le repli
 * téléphonique ("we'll explain over the phone") est entièrement hors app
 * (§6 — "purely offline"), cette classe n'a rien à faire dans ce cas
 * au-delà de ne pas tenter un envoi voué à l'échec.
 *
 * Appelée uniquement depuis App\Services\RetraitHqSchedulingService
 * (indirectement, via ses 2 appelants — voir son docblock) : jamais au
 * moment de la confirmation individuelle d'une famille, seulement une
 * fois le créneau réellement posé (heure_arrivee_prevue_hq non nul), pour
 * ne jamais envoyer un email "venez au QG" sans heure de rendez-vous.
 */
class RetraitHqNotificationService
{
    public function __construct(
        private readonly QrCodeService $qrCode,
    ) {
    }

    public function notifierPour(Livraison $livraison): bool
    {
        $famille = $livraison->famille;

        if (empty($famille->email) || $livraison->heure_arrivee_prevue_hq === null) {
            return false;
        }

        try {
            Notification::route('mail', $famille->email)
                ->notify(new RetraitHqNotification($livraison, $famille, $this->qrCode));
            return true;
        } catch (\Throwable $e) {
            Log::error('[RetraitHqNotificationService] Échec envoi email de retrait QG', [
                'id_livraison' => $livraison->id,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
