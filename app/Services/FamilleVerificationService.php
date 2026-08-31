<?php
// app/Services/FamilleVerificationService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Famille;
use App\Models\FamilleVerification;
use App\Notifications\FamilleVerificationNotification;
use App\Support\TokenHasher;
use Illuminate\Support\Str;

/**
 * Reprend le comportement de emailVerificationService.js (amana_familles) :
 * un envoi PAR LOT déclenché par le staff (pas automatique à chaque
 * soumission), vers les familles au dossier VALIDÉ possédant un email.
 * La famille reçoit ses infos actuelles + deux choix : "tout est à jour"
 * (confirme) ou "mes informations ont changé" (renvoie vers le formulaire
 * public pour mise à jour).
 *
 * Différence avec l'ancien système : chaque envoi génère un token unique à
 * durée de vie limitée (famille_verifications.expires_at) au lieu de
 * réutiliser une clé API statique partagée pour toutes les familles —
 * répond explicitement à la décision 6.10 ("avec gestion d'expiration de
 * token").
 */
class FamilleVerificationService
{
    private const DUREE_VALIDITE_JOURS = 7;

    /**
     * Ne renvoie pas un email à une famille qui a déjà une vérification
     * en cours (envoyée il y a moins de DUREE_VALIDITE_JOURS, pas encore
     * expirée) — évite le spam en cas de déclenchements répétés du staff.
     * Une famille déjà confirmée récemment (moins de 90 jours) est aussi
     * sautée : ses infos sont considérées encore à jour.
     *
     * @return array{envoyes: int, ignores: int, echecs: int}
     */
    public function envoyerParLot(): array
    {
        $resultats = ['envoyes' => 0, 'ignores' => 0, 'echecs' => 0];

        $familles = Famille::where('etat_dossier', 'Validé')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->with('verifications')
            ->get();

        foreach ($familles as $famille) {
            if ($this->verificationRecenteExistante($famille)) {
                $resultats['ignores']++;
                continue;
            }

            if ($this->envoyerPourFamille($famille)) {
                $resultats['envoyes']++;
            } else {
                $resultats['echecs']++;
            }
        }

        return $resultats;
    }

    public function envoyerPourFamille(Famille $famille): bool
    {
        if (empty($famille->email)) {
            return false;
        }

        // Jeton haché à partir du 31/08/2026 (voir App\Support\TokenHasher) :
        // seul $tokenEnClair (jamais persisté) part dans l'email — la ligne
        // ne conserve que son hash.
        $tokenEnClair = Str::random(48);

        $verification = FamilleVerification::create([
            'id_famille' => $famille->id,
            'token' => TokenHasher::hash($tokenEnClair),
            'expires_at' => now()->addDays(self::DUREE_VALIDITE_JOURS),
        ]);

        try {
            $famille->notify(new FamilleVerificationNotification($verification, $tokenEnClair));
            audit('create', 'familles_verification', $verification->id, null, ['id_famille' => $famille->id]);
            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[FamilleVerificationService] Échec envoi', [
                'id_famille' => $famille->id,
                'erreur' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function verificationRecenteExistante(Famille $famille): bool
    {
        return $famille->verifications->contains(function (FamilleVerification $v) {
            if ($v->confirmed_at) {
                return $v->confirmed_at->gt(now()->subDays(90));
            }
            return $v->expires_at->isFuture();
        });
    }
}
