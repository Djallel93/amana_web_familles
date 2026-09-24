<?php
// tests/Feature/Policies/LivraisonPolicyTest.php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\Famille;
use App\Models\Livraison;
use App\Policies\LivraisonPolicy;
use Tests\Concerns\BuildsCampagneEquipeFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

class LivraisonPolicyTest extends TestCase
{
    use BuildsCampagneEquipeFixtures;
    use SeedsCommunFixtures;

    private LivraisonPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new LivraisonPolicy();
        $this->chargerRolesFamilles();
    }

    private function creerLivraison(int $idCampagne): Livraison
    {
        return Livraison::create([
            'id_famille' => Famille::factory()->create()->id,
            'id_campagne' => $idCampagne,
            'statut' => 'non_assignee',
            'statut_conditionnement' => 'en_attente',
            'nombre_personnes' => 1,
            'poids_kg' => 10.0,
            'statut_contact' => 'a_contacter',
        ]);
    }

    public function test_admin_est_autorise_sans_affectation_equipe(): void
    {
        $admin = $this->creerPersonne(['admin']);

        $this->assertTrue($this->policy->gerer($admin, $this->creerLivraison($this->creerCampagne()->id)));
    }

    public function test_gestionnaire_est_autorise_sans_affectation_equipe(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);

        $this->assertTrue($this->policy->gerer($gestionnaire, $this->creerLivraison($this->creerCampagne()->id)));
    }

    public function test_membre_equipe_packaging_de_la_bonne_campagne_est_autorise(): void
    {
        $campagne = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagne, $personne->id, 'equipe_packaging');

        $this->assertTrue($this->policy->gerer($personne, $this->creerLivraison($campagne->id)));
    }

    public function test_membre_equipe_packaging_dune_autre_campagne_nest_pas_autorise(): void
    {
        $campagneA = $this->creerCampagne();
        $campagneB = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagneA, $personne->id, 'equipe_packaging');

        $this->assertFalse($this->policy->gerer($personne, $this->creerLivraison($campagneB->id)));
    }

    public function test_membre_dune_autre_equipe_nest_pas_autorise(): void
    {
        $campagne = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagne, $personne->id, 'equipe_chargement'); // wrong team

        $this->assertFalse($this->policy->gerer($personne, $this->creerLivraison($campagne->id)));
    }

    public function test_benevole_sans_affectation_nest_pas_autorise(): void
    {
        $benevole = $this->creerPersonne(['benevole']);

        $this->assertFalse($this->policy->gerer($benevole, $this->creerLivraison($this->creerCampagne()->id)));
    }
}
