<?php
// app/Services/BenevoleDisponibiliteService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Models\BenevoleProfil;
use App\Models\BenevoleDisponibilite;
use App\Models\Campagne;
use App\Models\CampagneJournee;
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
     * Crée ou met à jour la disponibilité d'un bénévole pour UNE journée
     * de campagne — éditable à tout moment après la confirmation
     * initiale (voir le prompt §3.2 : "Editable by them at any time
     * after"), donc un simple upsert plutôt qu'un flux "renvoyer le
     * formulaire".
     *
     * Rescopée de Campagne vers CampagneJournee le 05/09/2026 : un
     * bénévole confirme désormais séparément pour chaque journée (ex:
     * dispo le jour de collecte, pas le jour de livraison) — voir
     * create_benevole_disponibilites_table.php.
     *
     * @param string[] $creneaux
     *
     * vehicule_confirme/coverage_confirmee ne sont plus édités depuis ce
     * flux pour un enregistrement déjà existant (07/09/2026, prompt §5.1 :
     * "Modifier disponibilités" ne touche plus qu'aux créneaux, l'édition
     * réelle du véhicule/de la couverture se fait désormais via
     * "Modifier informations" → BenevoleProfil). $donnees peut donc ne
     * plus contenir ces clés du tout — on retombe alors sur la valeur déjà
     * enregistrée plutôt que sur `false` en dur, pour ne pas écraser
     * silencieusement une confirmation existante à chaque sauvegarde de
     * créneaux. `false` reste le défaut uniquement à la toute première
     * confirmation (aucune ligne existante à préserver).
     */
    public function confirmer(int $idPersonne, CampagneJournee $journee, array $donnees, array $creneaux): BenevoleDisponibilite
    {
        $existante = BenevoleDisponibilite::where('id_personne', $idPersonne)
            ->where('id_campagne_journee', $journee->id)
            ->first();

        $disponibilite = BenevoleDisponibilite::updateOrCreate(
            ['id_personne' => $idPersonne, 'id_campagne_journee' => $journee->id],
            [
                'vehicule_confirme' => $donnees['vehicule_confirme'] ?? $existante?->vehicule_confirme ?? false,
                'coverage_confirmee' => $donnees['coverage_confirmee'] ?? $existante?->coverage_confirmee ?? false,
                'coverage_notes' => $donnees['coverage_notes'] ?? $existante?->coverage_notes ?? null,
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
