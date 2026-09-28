<?php
// tests/Feature/Policies/DonationPolicyTest.php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\Donation;
use App\Policies\DonationPolicy;
use Tests\Concerns\BuildsCampagneEquipeFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

class DonationPolicyTest extends TestCase
{
    use BuildsCampagneEquipeFixtures;
    use SeedsCommunFixtures;

    private DonationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new DonationPolicy();
        $this->chargerRolesFamilles();
    }

    private function creerDonation(int $idCampagne): Donation
    {
        return Donation::create([
            'id_campagne' => $idCampagne,
            'poids_kg' => 10.0,
            'logge_par' => 1,
        ]);
    }

    public function test_admin_est_autorise_sans_affectation_equipe(): void
    {
        $admin = $this->creerPersonne(['admin']);

        $this->assertTrue($this->policy->gerer($admin, $this->creerDonation($this->creerCampagne()->id)));
    }

    public function test_gestionnaire_est_autorise_sans_affectation_equipe(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);

        $this->assertTrue($this->policy->gerer($gestionnaire, $this->creerDonation($this->creerCampagne()->id)));
    }

    public function test_membre_equipe_pesee_de_la_bonne_campagne_est_autorise(): void
    {
        $campagne = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagne, $personne->id, 'equipe_pesee');

        $this->assertTrue($this->policy->gerer($personne, $this->creerDonation($campagne->id)));
    }

    public function test_membre_equipe_pesee_dune_autre_campagne_nest_pas_autorise(): void
    {
        $campagneA = $this->creerCampagne();
        $campagneB = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagneA, $personne->id, 'equipe_pesee');

        $this->assertFalse($this->policy->gerer($personne, $this->creerDonation($campagneB->id)));
    }

    public function test_membre_dune_autre_equipe_nest_pas_autorise(): void
    {
        $campagne = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagne, $personne->id, 'equipe_reception'); // wrong team

        $this->assertFalse($this->policy->gerer($personne, $this->creerDonation($campagne->id)));
    }

    public function test_benevole_sans_affectation_nest_pas_autorise(): void
    {
        $benevole = $this->creerPersonne(['benevole']);

        $this->assertFalse($this->policy->gerer($benevole, $this->creerDonation($this->creerCampagne()->id)));
    }
}
