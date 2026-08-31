<?php
// app/Services/BenevoleDisponibiliteService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Models\BenevoleProfil;
use App\Models\BenevoleDisponibilite;
use App\Models\Campagne;
use App\Notifications\CampagneDisponibiliteNotification;
use Illuminate\Support\Facades\Log;

/**
 * Notification de lancement de campagne aux bénévoles + upsert de leur
 * disponibilité — voir le prompt du 30/08/2026 §3.2. Miroir de
 * FamilleVerificationService::envoyerParLot() côté structure (envoi par
 * lot, ne fait pas échouer l'ensemble si un envoi individuel échoue).
 */
class BenevoleDisponibiliteService
{
    /**
     * @return array{envoyes: int, echecs: int}
     */
    public function notifierCampagne(Campagne $campagne): array
    {
        $resultats = ['envoyes' => 0, 'echecs' => 0];

        $profils = BenevoleProfil::where('statut', 'Validé')->with('personne')->get();

        foreach ($profils as $profil) {
            if (!$profil->personne) {
                continue;
            }

            try {
                $profil->personne->notify(new CampagneDisponibiliteNotification($campagne, $profil));
                $resultats['envoyes']++;
            } catch (\Throwable $e) {
                Log::error('[BenevoleDisponibiliteService] Échec envoi', [
                    'id_personne' => $profil->id_personne,
                    'id_campagne' => $campagne->id,
                    'erreur' => $e->getMessage(),
                ]);
                $resultats['echecs']++;
            }
        }

        return $resultats;
    }

    /**
     * Crée ou met à jour la disponibilité d'un bénévole pour une
     * campagne — éditable à tout moment après la confirmation initiale
     * (voir le prompt §3.2 : "Editable by them at any time after"), donc
     * un simple upsert plutôt qu'un flux "renvoyer le formulaire".
     *
     * @param string[] $creneaux
     */
    public function confirmer(int $idPersonne, Campagne $campagne, array $donnees, array $creneaux): BenevoleDisponibilite
    {
        $disponibilite = BenevoleDisponibilite::updateOrCreate(
            ['id_personne' => $idPersonne, 'id_campagne' => $campagne->id],
            [
                'vehicule_confirme' => $donnees['vehicule_confirme'] ?? false,
                'coverage_confirmee' => $donnees['coverage_confirmee'] ?? false,
                'coverage_notes' => $donnees['coverage_notes'] ?? null,
                'statut' => 'confirme',
            ],
        );

        $disponibilite->creneaux()->delete();
        foreach ($creneaux as $creneau) {
            $disponibilite->creneaux()->create(['creneau' => $creneau]);
        }

        return $disponibilite;
    }
}
