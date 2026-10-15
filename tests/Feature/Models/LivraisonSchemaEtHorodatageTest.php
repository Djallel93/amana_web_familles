<?php
// tests/Feature/Models/LivraisonSchemaEtHorodatageTest.php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Models\Famille;
use App\Models\Livraison;
use App\Models\RouteIncident;
use Illuminate\Database\QueryException;
use Tests\Concerns\BuildsDisponibiliteFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Socle du lot du 11/10/2026 : colonnes de préparation prioritaire, horodatage
 * de confirmation (FIFO Packaging), contrainte « chauffeur imposé XOR se_deplace »,
 * statut de campagne `collecte` retiré, nouveau type d'incident.
 */
class LivraisonSchemaEtHorodatageTest extends TestCase
{
    use BuildsDisponibiliteFixtures;
    use SeedsCommunFixtures;

    private Campagne $campagne;

    private CampagneJournee $journee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerRolesFamilles();
        [$this->campagne, $this->journee] = $this->creerCampagneAvecJournee();
    }

    private function livraison(array $surcharge = []): Livraison
    {
        return Livraison::create(array_merge([
            'id_famille' => Famille::factory()->create()->id,
            'id_campagne' => $this->campagne->id,
            'id_campagne_journee' => $this->journee->id,
            'statut' => 'non_assignee',
            'statut_conditionnement' => 'en_attente',
            'nombre_personnes' => 2,
            'poids_kg' => 10.0,
            'statut_contact' => 'a_contacter',
        ], $surcharge));
    }

    public function test_la_preparation_prioritaire_est_desactivee_par_defaut(): void
    {
        $livraison = $this->livraison()->fresh();

        $this->assertFalse($livraison->preparation_prioritaire);
        $this->assertNull($livraison->prioritaire_at);
    }

    public function test_confirme_at_est_pose_au_passage_a_confirme(): void
    {
        $livraison = $this->livraison();
        $this->assertNull($livraison->fresh()->confirme_at);

        $this->travelTo(now()->startOfSecond());
        $livraison->update(['statut_contact' => 'confirme']);

        $this->assertNotNull($livraison->fresh()->confirme_at);
        $this->assertTrue($livraison->fresh()->confirme_at->equalTo(now()));
    }

    public function test_confirme_at_est_pose_a_la_creation_d_une_livraison_deja_confirmee(): void
    {
        $this->assertNotNull($this->livraison(['statut_contact' => 'confirme'])->fresh()->confirme_at);
    }

    public function test_confirme_at_est_efface_quand_la_famille_quitte_confirme(): void
    {
        $livraison = $this->livraison(['statut_contact' => 'confirme']);

        $livraison->update(['statut_contact' => 'a_contacter']);

        $this->assertNull($livraison->fresh()->confirme_at);
    }

    public function test_confirme_at_ne_bouge_pas_quand_un_autre_champ_change(): void
    {
        $livraison = $this->livraison(['statut_contact' => 'confirme']);
        $avant = $livraison->fresh()->confirme_at;

        $this->travel(5)->minutes();
        $livraison->update(['poids_kg' => 12.0]);

        $this->assertTrue($livraison->fresh()->confirme_at->equalTo($avant));
    }

    public function test_une_famille_ne_peut_pas_etre_a_la_fois_se_deplace_et_imposee(): void
    {
        $this->expectException(QueryException::class);

        $this->livraison(['se_deplace' => true, 'id_benevole_impose' => 42]);
    }

    public function test_se_deplace_seul_ou_impose_seul_reste_possible(): void
    {
        $this->assertTrue($this->livraison(['se_deplace' => true])->fresh()->se_deplace);
        $this->assertSame(42, $this->livraison(['id_benevole_impose' => 42])->fresh()->id_benevole_impose);
    }

    public function test_le_statut_collecte_n_existe_plus(): void
    {
        $this->assertSame(['preparation', 'en_cours', 'terminee'], Campagne::STATUTS);

        $this->expectException(QueryException::class);
        $this->campagne->forceFill(['statut' => 'collecte'])->save();
    }

    public function test_le_type_d_incident_se_deplace_remplace_est_enregistrable(): void
    {
        $livraison = $this->livraison(['statut_contact' => 'confirme']);

        $incident = RouteIncident::create([
            'id_route' => null,
            'id_campagne' => $this->campagne->id,
            'type' => 'se_deplace_remplace',
            'id_livraison' => $livraison->id,
            'signale_par' => 1,
            'statut' => 'ouvert',
        ]);

        $this->assertContains('se_deplace_remplace', RouteIncident::TYPES);
        $this->assertSame('Retrait QG remplacé par une livraison', RouteIncident::LABELS_TYPE[$incident->type]);
    }
}
