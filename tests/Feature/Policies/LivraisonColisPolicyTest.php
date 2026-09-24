<?php
// tests/Feature/Policies/LivraisonColisPolicyTest.php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\Famille;
use App\Models\Livraison;
use App\Models\LivraisonColis;
use App\Policies\LivraisonColisPolicy;
use Tests\Concerns\BuildsCampagneEquipeFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

class LivraisonColisPolicyTest extends TestCase
{
    use BuildsCampagneEquipeFixtures;
    use SeedsCommunFixtures;

    private LivraisonColisPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new LivraisonColisPolicy();
        $this->chargerRolesFamilles();
    }

    /**
     * Exercises the actual two-hop resolution path (colis → livraison →
     * campagne, see the policy's own docblock on why there's no
     * denormalized id_campagne shortcut) rather than stubbing it.
     */
    private function creerColis(int $idCampagne): LivraisonColis
    {
        $livraison = Livraison::create([
            'id_famille' => Famille::factory()->create()->id,
            'id_campagne' => $idCampagne,
            'statut' => 'non_assignee',
            'statut_conditionnement' => 'en_attente',
            'nombre_personnes' => 1,
            'poids_kg' => 10.0,
            'statut_contact' => 'a_contacter',
        ]);

        return $livraison->colis()->create(['numero' => 1, 'statut' => 'a_preparer']);
    }

    public function test_admin_est_autorise_sans_affectation_equipe(): void
    {
        $admin = $this->creerPersonne(['admin']);

        $this->assertTrue($this->policy->gerer($admin, $this->creerColis($this->creerCampagne()->id)));
    }

    public function test_gestionnaire_est_autorise_sans_affectation_equipe(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);

        $this->assertTrue($this->policy->gerer($gestionnaire, $this->creerColis($this->creerCampagne()->id)));
    }

    public function test_membre_equipe_packaging_de_la_bonne_campagne_est_autorise(): void
    {
        $campagne = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagne, $personne->id, 'equipe_packaging');

        $this->assertTrue($this->policy->gerer($personne, $this->creerColis($campagne->id)));
    }

    public function test_membre_equipe_packaging_dune_autre_campagne_nest_pas_autorise(): void
    {
        $campagneA = $this->creerCampagne();
        $campagneB = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagneA, $personne->id, 'equipe_packaging');

        $this->assertFalse($this->policy->gerer($personne, $this->creerColis($campagneB->id)));
    }

    public function test_membre_dune_autre_equipe_nest_pas_autorise(): void
    {
        $campagne = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagne, $personne->id, 'equipe_reception'); // wrong team

        $this->assertFalse($this->policy->gerer($personne, $this->creerColis($campagne->id)));
    }

    public function test_benevole_sans_affectation_nest_pas_autorise(): void
    {
        $benevole = $this->creerPersonne(['benevole']);

        $this->assertFalse($this->policy->gerer($benevole, $this->creerColis($this->creerCampagne()->id)));
    }
}
