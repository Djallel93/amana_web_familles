<?php
// app/Services/ContactTokenService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContactToken;
use App\Models\Livraison;
use App\Notifications\LivraisonConfirmationNotification;
use App\Support\TokenHasher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Émission des jetons de confirmation famille (contact_tokens) — voir le
 * prompt du 30/08/2026 §3.1 : uniquement pour les familles disposant d'un
 * email ; sinon, contact téléphonique par le staff (voir
 * ContactTrackingController, pas cette classe).
 *
 * Jeton haché dès la conception (voir create_contact_tokens_table.php et
 * App\Support\TokenHasher, réutilisé tel quel depuis la correction du
 * 31/08/2026 sur les 3 flux existants) — jamais de jeton en clair
 * persisté, seule la Notification le reçoit, jamais relu depuis le
 * modèle.
 */
class ContactTokenService
{
    private const DUREE_VALIDITE_JOURS = 14;

    /**
     * Émet un jeton pour cette livraison et envoie l'email — no-op
     * silencieux si la famille n'a pas d'email (à traiter par contact
     * téléphonique, voir ContactTrackingController) ou si un jeton valide
     * existe déjà (évite de spammer plusieurs emails pour la même
     * livraison en cas de relance répétée par l'admin).
     */
    public function emettrePour(Livraison $livraison): bool
    {
        $famille = $livraison->famille;

        if (empty($famille->email)) {
            return false;
        }

        if ($this->jetonValideExistant($livraison)) {
            return false;
        }

        $tokenEnClair = Str::random(60);

        ContactToken::create([
            'id_livraison' => $livraison->id,
            'token' => TokenHasher::hash($tokenEnClair),
            'expires_at' => now()->addDays(self::DUREE_VALIDITE_JOURS),
        ]);

        try {
            Notification::route('mail', $famille->email)
                ->notify(new LivraisonConfirmationNotification($livraison, $famille, $tokenEnClair));
            return true;
        } catch (\Throwable $e) {
            Log::error('[ContactTokenService] Échec envoi email de confirmation', [
                'id_livraison' => $livraison->id,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function jetonValideExistant(Livraison $livraison): bool
    {
        return $livraison->contactTokens()
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Résout un jeton EN CLAIR reçu via l'URL publique — comparaison par
     * hash (voir App\Support\TokenHasher), jamais en clair. Renvoie null
     * si introuvable, expiré, ou déjà utilisé — le contrôleur distingue
     * ces cas pour l'affichage (voir ContactConfirmationController).
     */
    public function resoudre(string $tokenEnClair): ?ContactToken
    {
        return ContactToken::with('livraison.famille')
            ->where('token', TokenHasher::hash($tokenEnClair))
            ->first();
    }
}
