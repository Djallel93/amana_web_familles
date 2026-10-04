<?php
// tests/Concerns/BuildsDisponibiliteFixtures.php

declare(strict_types=1);

namespace Tests\Concerns;

use Amana\Shared\Models\VehiculeType;
use App\Models\BenevoleProfil;
use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Models\Personne;
use Illuminate\Support\Facades\DB;

/**
 * Fixtures communes aux tests de la disponibilité PAR JOURNÉE (01/10/2026) :
 * types de véhicule, villes/secteurs, bénévole + profil, campagne + journées.
 * À utiliser avec SeedsCommunFixtures (creerPersonne()/chargerRolesFamilles()).
 */
trait BuildsDisponibiliteFixtures
{
    /** @return array{voiture: int, utilitaire: int, sans_permis: int, non_vehicule: int} */
    private function creerVehicules(): array
    {
        $creer = fn(string $type, float $capacite, int $parts) => VehiculeType::create([
            'type' => $type, 'capacite_kg' => $capacite, 'nombre_part_max' => $parts,
        ])->id;

        return [
            'voiture' => $creer('Voiture', 200, 5),
            'utilitaire' => $creer('Utilitaire', 500, 10),
            'sans_permis' => $creer('Sans permis', 0, 0),
            'non_vehicule' => $creer('Non véhiculé', 0, 0),
        ];
    }

    /**
     * Deux villes de deux secteurs chacune.
     *
     * @return array{ville_a: int, ville_b: int, a1: int, a2: int, b1: int}
     */
    private function creerGeographie(): array
    {
        $connexion = config('amana-shared.connection', 'commun');

        // `boundary` : MULTIPOLYGON NOT NULL, forme sans importance ici.
        $ville = fn(string $nom) => DB::connection($connexion)->table('villes')->insertGetId([
            'nom' => $nom,
            'boundary' => DB::raw("ST_GeomFromText('MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))', 4326)"),
        ]);
        $secteur = fn(string $nom, int $idVille) => DB::connection($connexion)->table('secteurs')->insertGetId([
            'nom' => $nom, 'id_ville' => $idVille,
        ]);

        $villeA = $ville('Nantes');
        $villeB = $ville('Rezé');

        return [
            'ville_a' => $villeA,
            'ville_b' => $villeB,
            'a1' => $secteur('Centre', $villeA),
            'a2' => $secteur('Nord', $villeA),
            'b1' => $secteur('Château', $villeB),
        ];
    }

    private function creerBenevole(int $idVehiculeProfil, array $roles = ['benevole']): Personne
    {
        $personne = $this->creerPersonne($roles);

        BenevoleProfil::create([
            'id_personne' => $personne->id,
            'id_vehicule_type' => $idVehiculeProfil,
            'statut' => 'Validé',
        ]);

        return $personne;
    }

    /** @return array{0: Campagne, 1: CampagneJournee} */
    private function creerCampagneAvecJournee(string $date = '2026-11-10'): array
    {
        $campagne = Campagne::create([
            'type' => 'zakat_el_fitr',
            'statut' => 'en_cours',
            'date_livraison' => $date,
            'hq_latitude' => 0.0,
            'hq_longitude' => 0.0,
        ]);

        return [$campagne, $campagne->ajouterJournee($date)];
    }
}
