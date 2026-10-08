<?php
// app/Services/RetraitHqNotificationService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Famille;
use App\Models\Livraison;
use App\Notifications\RetraitHqAnnuleNotification;
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
    ) {}

    public function notifierPour(Livraison $livraison): bool
    {
        $famille = $this->familleAvecEmail($livraison);

        if ($famille === null || empty($famille->email) || $livraison->heure_arrivee_prevue_hq === null) {
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

    /**
     * « Vous n'avez plus à venir au QG » (06/10/2026) : la famille avait un
     * rendez-vous de retrait et repasse en livraison (voir
     * LivraisonChangementService::changerSeDeplace()). Même garde-fou que
     * notifierPour() (pas d'email = no-op silencieux).
     */
    public function notifierAnnulation(Livraison $livraison): bool
    {
        $famille = $this->familleAvecEmail($livraison);

        if ($famille === null || empty($famille->email)) {
            return false;
        }

        try {
            Notification::route('mail', $famille->email)
                ->notify(new RetraitHqAnnuleNotification($famille));

            return true;
        } catch (\Throwable $e) {
            Log::error('[RetraitHqNotificationService] Échec envoi email d\'annulation de retrait QG', [
                'id_livraison' => $livraison->id,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * RetraitHqSchedulingService::planifierPour() charge la famille avec
     * `famille:id,criticite` seulement : sans email, donc aucun email ne
     * partait. On relit la famille complète (requête typée plutôt que la
     * relation, typée `Model` par l'analyse statique).
     */
    private function familleAvecEmail(Livraison $livraison): ?Famille
    {
        return Famille::find($livraison->id_famille);
    }
}
