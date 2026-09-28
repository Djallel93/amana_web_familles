<?php
// tests/Unit/Support/FamilleFiltersTest.php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Famille;
use App\Models\Organisation;
use App\Support\FamilleFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DB-backed (Famille itself, plus Quartier/Secteur/Ville living on the
 * `commun` connection for the geographic filters) — tests the shared
 * filter logic directly against a real query, since two different UIs
 * (Dossier Familles and the livraison domain — campagnes éligibles,
 * suivi des contacts) depend on this one class staying correct, per the
 * Phase 4 brief.
 */
class FamilleFiltersTest extends TestCase
{
    /**
     * Quartier/Secteur/Ville's `boundary` column is a required native
     * MySQL MULTIPOLYGON with no Eloquent cast (see their docblocks:
     * "jamais de l'UTF-8 valide") — inserted via raw SQL here since the
     * actual shape is irrelevant to id-based filtering, only a valid
     * geometry value is needed to satisfy the NOT NULL column.
     */
    private function creerVille(): int
    {
        $connexion = config('amana-shared.connection', 'commun');
        $id = DB::connection($connexion)->table('villes')->insertGetId([
            'nom' => 'Nantes',
            'boundary' => DB::raw("ST_GeomFromText('MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))', 4326)"),
        ]);

        return $id;
    }

    private function creerSecteur(int $idVille): int
    {
        $connexion = config('amana-shared.connection', 'commun');

        return DB::connection($connexion)->table('secteurs')->insertGetId([
            'nom' => 'Centre',
            'id_ville' => $idVille,
        ]);
    }

    private function creerQuartier(int $idSecteur): int
    {
        $connexion = config('amana-shared.connection', 'commun');

        return DB::connection($connexion)->table('quartiers')->insertGetId([
            'nom' => 'Bouffay',
            'id_secteur' => $idSecteur,
            'boundary' => DB::raw("ST_GeomFromText('MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))', 4326)"),
        ]);
    }

    private function requete(array $params): Request
    {
        return Request::create('/', 'GET', $params);
    }

    private function idsApresFiltre(array $params): array
    {
        $query = Famille::query();
        FamilleFilters::appliquer($query, $this->requete($params));

        return $query->pluck('id')->sort()->values()->all();
    }

    // ── id_quartier / id_secteur / id_ville ─────────────────────────────

    public function test_id_quartier_filtre_exactement_ce_quartier(): void
    {
        $ville = $this->creerVille();
        $secteur = $this->creerSecteur($ville);
        $quartierA = $this->creerQuartier($secteur);
        $quartierB = $this->creerQuartier($secteur);

        $dansA = Famille::factory()->create(['id_quartier' => $quartierA]);
        Famille::factory()->create(['id_quartier' => $quartierB]);

        $this->assertSame([$dansA->id], $this->idsApresFiltre(['id_quartier' => $quartierA]));
    }

    public function test_id_secteur_filtre_tous_les_quartiers_de_ce_secteur(): void
    {
        $ville = $this->creerVille();
        $secteurA = $this->creerSecteur($ville);
        $secteurB = $this->creerSecteur($ville);
        $quartierA1 = $this->creerQuartier($secteurA);
        $quartierA2 = $this->creerQuartier($secteurA);
        $quartierB1 = $this->creerQuartier($secteurB);

        $f1 = Famille::factory()->create(['id_quartier' => $quartierA1]);
        $f2 = Famille::factory()->create(['id_quartier' => $quartierA2]);
        Famille::factory()->create(['id_quartier' => $quartierB1]);

        $this->assertSame(
            collect([$f1->id, $f2->id])->sort()->values()->all(),
            $this->idsApresFiltre(['id_secteur' => $secteurA]),
        );
    }

    public function test_id_ville_filtre_tous_les_quartiers_de_tous_les_secteurs_de_cette_ville(): void
    {
        $villeA = $this->creerVille();
        $villeB = $this->creerVille();
        $secteurA = $this->creerSecteur($villeA);
        $secteurB = $this->creerSecteur($villeB);
        $quartierA = $this->creerQuartier($secteurA);
        $quartierB = $this->creerQuartier($secteurB);

        $dansA = Famille::factory()->create(['id_quartier' => $quartierA]);
        Famille::factory()->create(['id_quartier' => $quartierB]);

        $this->assertSame([$dansA->id], $this->idsApresFiltre(['id_ville' => $villeA]));
    }

    // ── booléens ─────────────────────────────────────────────────────────

    public function test_les_filtres_booleens_ne_retiennent_que_true_et_ignorent_absent_ou_false(): void
    {
        $conforme = Famille::factory()->create(['est_hotel' => true]);
        Famille::factory()->create(['est_hotel' => false]);

        $this->assertSame([$conforme->id], $this->idsApresFiltre(['est_hotel' => '1']));
        // Explicit false and absent both mean "don't filter" — both families returned.
        $this->assertCount(2, $this->idsApresFiltre(['est_hotel' => '0']));
        $this->assertCount(2, $this->idsApresFiltre([]));
    }

    // ── criticite (multi-valeurs, filtré à [0..5]) ──────────────────────

    public function test_criticite_accepte_plusieurs_valeurs(): void
    {
        $f2 = Famille::factory()->create(['criticite' => 2]);
        $f4 = Famille::factory()->create(['criticite' => 4]);
        Famille::factory()->create(['criticite' => 0]);

        $this->assertSame(
            collect([$f2->id, $f4->id])->sort()->values()->all(),
            $this->idsApresFiltre(['criticite' => [2, 4]]),
        );
    }

    public function test_criticite_ignore_les_valeurs_hors_0_5(): void
    {
        $f3 = Famille::factory()->create(['criticite' => 3]);
        Famille::factory()->create(['criticite' => 0]);

        // 99 is out of [0..5] and dropped, leaving only the valid `3`.
        $this->assertSame([$f3->id], $this->idsApresFiltre(['criticite' => [3, 99]]));
    }

    public function test_criticite_ne_filtre_pas_du_tout_quand_toutes_les_valeurs_sont_hors_bornes(): void
    {
        Famille::factory()->create(['criticite' => 3]);
        Famille::factory()->create(['criticite' => 0]);

        $this->assertCount(2, $this->idsApresFiltre(['criticite' => [99, 100]]));
    }

    // ── recherche vs nom+telephone, et id_selection court-circuite les deux ──

    public function test_recherche_correspond_sur_nom_prenom_ou_telephone(): void
    {
        $parNom = Famille::factory()->create(['nom' => 'Benali', 'prenom' => 'X', 'telephone' => '0600000001']);
        $parTelephone = Famille::factory()->create(['nom' => 'Autre', 'prenom' => 'Y', 'telephone' => '0611112222']);
        Famille::factory()->create(['nom' => 'SansRapport', 'prenom' => 'Z', 'telephone' => '0699999999']);

        $this->assertSame([$parNom->id], $this->idsApresFiltre(['recherche' => 'Benali']));
        $this->assertSame([$parTelephone->id], $this->idsApresFiltre(['recherche' => '1111']));
    }

    public function test_nom_et_telephone_sont_des_filtres_separes_de_recherche(): void
    {
        $famille = Famille::factory()->create(['nom' => 'Benali', 'telephone' => '0611112222']);
        Famille::factory()->create(['nom' => 'Autre', 'telephone' => '0699999999']);

        $this->assertSame([$famille->id], $this->idsApresFiltre(['nom' => 'Benali']));
        $this->assertSame([$famille->id], $this->idsApresFiltre(['telephone' => '1111']));
    }

    public function test_id_selection_court_circuite_nom_et_telephone(): void
    {
        $cible = Famille::factory()->create(['nom' => 'NeCorrespondPas']);
        $autre = Famille::factory()->create(['nom' => 'NeCorrespondPas']);

        // 'nom' would normally match BOTH (same nom) — id_selection must
        // override it down to exactly the one selected id.
        $resultat = $this->idsApresFiltre(['id_selection' => $cible->id, 'nom' => 'NeCorrespondPas']);

        $this->assertSame([$cible->id], $resultat);
        $this->assertNotContains($autre->id, $resultat);
    }

    // ── organisation ─────────────────────────────────────────────────────

    public function test_id_organisation_origine_filtre_par_organisation_dorigine(): void
    {
        $orgA = Organisation::create(['code' => 'ORG_A', 'nom' => 'Org A', 'actif' => true]);
        $orgB = Organisation::create(['code' => 'ORG_B', 'nom' => 'Org B', 'actif' => true]);
        $deA = Famille::factory()->create(['id_organisation' => $orgA->id]);
        Famille::factory()->create(['id_organisation' => $orgB->id]);

        $this->assertSame([$deA->id], $this->idsApresFiltre(['id_organisation_origine' => $orgA->id]));
    }

    public function test_id_organisation_rattachee_filtre_par_organisation_dattachement_pas_dorigine(): void
    {
        $origine = Organisation::create(['code' => 'ORIGINE', 'nom' => 'Origine', 'actif' => true]);
        $partenaire = Organisation::create(['code' => 'PARTENAIRE', 'nom' => 'Partenaire', 'actif' => true]);
        $famille = Famille::factory()->create(['id_organisation' => $origine->id]);
        $famille->organisations()->attach($partenaire->id, ['rattachee_le' => now()]);
        // A second family with the SAME origine but no attachment to $partenaire.
        Famille::factory()->create(['id_organisation' => $origine->id]);

        // Filtering by origine alone would return both; by rattachee it's just the one attached.
        $this->assertSame([$famille->id], $this->idsApresFiltre(['id_organisation_rattachee' => $partenaire->id]));
    }
}
