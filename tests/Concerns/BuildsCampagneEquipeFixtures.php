<?php
// tests/Concerns/BuildsCampagneEquipeFixtures.php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Campagne;
use App\Models\CampagneEquipeMembre;

/**
 * Shared by the Phase 3 policy tests (CampagnePolicy and the 5
 * subresource policies that delegate to the same
 * App\Policies\Concerns\AutoriseEquipeCampagne trait) — building a
 * Campagne and a campagne_equipe_membres row is identical across all of
 * them, only the resource passed to the policy's gerer()/equipeX() method
 * differs.
 */
trait BuildsCampagneEquipeFixtures
{
    private function creerCampagne(array $overrides = []): Campagne
    {
        return Campagne::create(array_merge([
            'type' => 'zakat_el_fitr',
            'statut' => 'en_cours',
            'date_livraison' => now()->addWeek()->toDateString(),
        ], $overrides));
    }

    /**
     * Assigns $personne to $campagne's team for $role (one of the 4
     * equipe_* codes) — a CampagneEquipeMembre row, campaign-scoped by
     * design (see Campagne::aRole()), distinct from the GLOBAL
     * ref_roles-based roles SeedsCommunFixtures::creerPersonne() attaches.
     */
    private function assignerEquipe(Campagne $campagne, int $idPersonne, string $role): CampagneEquipeMembre
    {
        return CampagneEquipeMembre::create([
            'id_campagne' => $campagne->id,
            'id_personne' => $idPersonne,
            'role' => $role,
        ]);
    }
}
