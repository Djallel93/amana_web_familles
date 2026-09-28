<?php
// tests/Feature/Policies/CampagneArriveePolicyTest.php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\CampagneArrivee;
use App\Policies\CampagneArriveePolicy;
use Tests\Concerns\BuildsCampagneEquipeFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

class CampagneArriveePolicyTest extends TestCase
{
    use BuildsCampagneEquipeFixtures;
    use SeedsCommunFixtures;

    private CampagneArriveePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CampagneArriveePolicy();
        $this->chargerRolesFamilles();
    }

    private function creerArrivee(int $idCampagne): CampagneArrivee
    {
        return CampagneArrivee::create([
            'id_campagne' => $idCampagne,
            'nombre_donateur' => 1,
            'logge_par' => 1,
        ]);
    }

    public function test_admin_est_autorise_sans_affectation_equipe(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $arrivee = $this->creerArrivee($this->creerCampagne()->id);

        $this->assertTrue($this->policy->gerer($admin, $arrivee));
    }

    public function test_gestionnaire_est_autorise_sans_affectation_equipe(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $arrivee = $this->creerArrivee($this->creerCampagne()->id);

        $this->assertTrue($this->policy->gerer($gestionnaire, $arrivee));
    }

    public function test_membre_equipe_reception_de_la_bonne_campagne_est_autorise(): void
    {
        $campagne = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagne, $personne->id, 'equipe_reception');

        $this->assertTrue($this->policy->gerer($personne, $this->creerArrivee($campagne->id)));
    }

    /**
     * Docblock: "no restriction de propriété (n'importe quel
     * equipe_reception peut éditer une ligne saisie par quelqu'un
     * d'autre)" — a DIFFERENT equipe_reception member than the one who
     * logged the arrival must still be authorized.
     */
    public function test_un_autre_membre_equipe_reception_de_la_meme_campagne_est_aussi_autorise(): void
    {
        $campagne = $this->creerCampagne();
        $quiALogge = $this->creerPersonne(['membre']);
        $autreMembre = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagne, $quiALogge->id, 'equipe_reception');
        $this->assignerEquipe($campagne, $autreMembre->id, 'equipe_reception');

        $arrivee = CampagneArrivee::create(['id_campagne' => $campagne->id, 'nombre_donateur' => 1, 'logge_par' => $quiALogge->id]);

        $this->assertTrue($this->policy->gerer($autreMembre, $arrivee));
    }

    public function test_membre_equipe_reception_dune_autre_campagne_nest_pas_autorise(): void
    {
        $campagneA = $this->creerCampagne();
        $campagneB = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagneA, $personne->id, 'equipe_reception');

        $this->assertFalse($this->policy->gerer($personne, $this->creerArrivee($campagneB->id)));
    }

    public function test_membre_dune_autre_equipe_nest_pas_autorise(): void
    {
        $campagne = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagne, $personne->id, 'equipe_pesee'); // wrong team

        $this->assertFalse($this->policy->gerer($personne, $this->creerArrivee($campagne->id)));
    }

    public function test_benevole_sans_affectation_nest_pas_autorise(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $arrivee = $this->creerArrivee($this->creerCampagne()->id);

        $this->assertFalse($this->policy->gerer($benevole, $arrivee));
    }
}
