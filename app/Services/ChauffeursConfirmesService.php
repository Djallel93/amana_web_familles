<?php
// app/Services/ChauffeursConfirmesService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Models\BenevoleProfil;
use Amana\Shared\Models\VehiculeType;
use App\Models\BenevoleDisponibilite;
use App\Models\CampagneJournee;
use App\Models\PersonneDesactivee;

/**
 * Bénévoles CONFIRMÉS comme chauffeurs pour une journée (09/10/2026) — source
 * unique du sélecteur « Prise en charge par un chauffeur » de Suivi des
 * contacts (PickersController) et de sa validation serveur
 * (ContactTrackingController::prendreEnCharge()).
 *
 * Mêmes critères que RouteGenerationService::candidatsPour() (qui ajoute en
 * plus le filtre par créneau, sans objet pour une livraison imposée : le
 * chauffeur la fait quand il veut) :
 *   - disponibilité au statut 'confirme' sur la journée ;
 *   - personne non désactivée pour Familles ;
 *   - véhicule effectif de la journée (celui déclaré pour la journée, sinon
 *     celui du profil) avec une capacité > 0 — « Sans permis »/« Non
 *     véhiculé » ne sont jamais proposés.
 */
class ChauffeursConfirmesService
{
    /**
     * @return array<int, VehiculeType> id_personne => véhicule effectif de la journée
     */
    public function pourJournee(?int $idJournee, ?int $idCampagne = null): array
    {
        $idsJournees = $idJournee !== null
            ? [$idJournee]
            // Livraison sans journée (nullable en base) : n'importe quelle journée de la campagne.
            : ($idCampagne !== null ? CampagneJournee::where('id_campagne', $idCampagne)->pluck('id')->all() : []);

        if ($idsJournees === []) {
            return [];
        }

        $disponibilites = BenevoleDisponibilite::whereIn('id_campagne_journee', $idsJournees)
            ->where('statut', 'confirme')
            ->whereNotIn('id_personne', PersonneDesactivee::ids())
            ->orderBy('id')
            ->get();

        $profils = BenevoleProfil::whereIn('id_personne', $disponibilites->pluck('id_personne')->all())
            ->with('vehiculeType')
            ->get()
            ->keyBy('id_personne');

        $chauffeurs = [];
        foreach ($disponibilites as $dispo) {
            $vehicule = $dispo->vehiculeEffectif($profils->get($dispo->id_personne));

            if ($vehicule === null || (float) $vehicule->capacite_kg <= 0) {
                continue;
            }

            $chauffeurs[$dispo->id_personne] ??= $vehicule;
        }

        return $chauffeurs;
    }

    public function estConfirme(int $idPersonne, ?int $idJournee, ?int $idCampagne = null): bool
    {
        return array_key_exists($idPersonne, $this->pourJournee($idJournee, $idCampagne));
    }
}
