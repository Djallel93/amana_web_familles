<?php
// tests/Feature/Policies/CampagnePolicyTest.php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\Campagne;
use App\Policies\CampagnePolicy;
use Tests\Concerns\BuildsCampagneEquipeFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

class CampagnePolicyTest extends TestCase
{
    use BuildsCampagneEquipeFixtures;
    use SeedsCommunFixtures;

    private CampagnePolicy $policy;

    /** @var array<string, string> ability name => equipe_* role code it requires */
    private array $abilites = [
        'equipeReception' => 'equipe_reception',
        'equipePesee' => 'equipe_pesee',
        'equipePackaging' => 'equipe_packaging',
        'equipeChargement' => 'equipe_chargement',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CampagnePolicy();
        $this->chargerRolesFamilles();
    }

    public function test_admin_est_toujours_autorise_pour_toutes_les_abilites_sans_aucune_affectation_equipe(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $campagne = $this->creerCampagne();

        foreach (array_keys($this->abilites) as $abilite) {
            $this->assertTrue($this->policy->{$abilite}($admin, $campagne), "admin devrait être autorisé pour {$abilite}");
        }
    }

    public function test_gestionnaire_est_toujours_autorise_pour_toutes_les_abilites_sans_aucune_affectation_equipe(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $campagne = $this->creerCampagne();

        foreach (array_keys($this->abilites) as $abilite) {
            $this->assertTrue($this->policy->{$abilite}($gestionnaire, $campagne), "gestionnaire devrait être autorisé pour {$abilite}");
        }
    }

    public function test_un_membre_dequipe_est_autorise_uniquement_pour_labilite_correspondant_a_son_role(): void
    {
        $campagne = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagne, $personne->id, 'equipe_pesee');

        foreach ($this->abilites as $abilite => $roleRequis) {
            $attendu = $roleRequis === 'equipe_pesee';
            $this->assertSame(
                $attendu,
                $this->policy->{$abilite}($personne, $campagne),
                "{$abilite} devrait être " . ($attendu ? 'autorisé' : 'refusé') . ' pour un membre equipe_pesee uniquement',
            );
        }
    }

    /**
     * Phase 3 brief: "verify a user assigned to one campaign's team does
     * NOT get access to another campaign's" equipe_* actions.
     */
    public function test_un_membre_dequipe_dune_autre_campagne_nest_pas_autorise(): void
    {
        $campagneA = $this->creerCampagne();
        $campagneB = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagneA, $personne->id, 'equipe_pesee');

        $this->assertTrue($this->policy->equipePesee($personne, $campagneA));
        $this->assertFalse($this->policy->equipePesee($personne, $campagneB), 'equipe_pesee sur la campagne A ne doit pas donner accès à la campagne B');
    }

    public function test_une_personne_sans_role_equipe_ni_admin_ni_gestionnaire_nest_jamais_autorisee(): void
    {
        $benevole = $this->creerPersonne(['benevole']);
        $campagne = $this->creerCampagne();

        foreach (array_keys($this->abilites) as $abilite) {
            $this->assertFalse($this->policy->{$abilite}($benevole, $campagne), "{$abilite} devrait être refusé à un simple bénévole sans affectation");
        }
    }
}
