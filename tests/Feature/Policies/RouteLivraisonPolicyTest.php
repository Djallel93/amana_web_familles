<?php
// tests/Feature/Policies/RouteLivraisonPolicyTest.php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\RouteLivraison;
use App\Policies\RouteLivraisonPolicy;
use Tests\Concerns\BuildsCampagneEquipeFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

class RouteLivraisonPolicyTest extends TestCase
{
    use BuildsCampagneEquipeFixtures;
    use SeedsCommunFixtures;

    private RouteLivraisonPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new RouteLivraisonPolicy();
        $this->chargerRolesFamilles();
    }

    private function creerRoute(int $idCampagne, int $idBenevole): RouteLivraison
    {
        return RouteLivraison::create([
            'id_campagne' => $idCampagne,
            'id_benevole' => $idBenevole,
            'id_vehicule_type' => 1, // ref_vehicules.id, cross-DB, no FK — see migration comment
            'creneau' => 'matin1',
            'statut' => 'planifiee',
        ]);
    }

    public function test_admin_est_autorise_sans_affectation_equipe(): void
    {
        $admin = $this->creerPersonne(['admin']);
        $chauffeur = $this->creerPersonne(['benevole']);

        $this->assertTrue($this->policy->gerer($admin, $this->creerRoute($this->creerCampagne()->id, $chauffeur->id)));
    }

    public function test_gestionnaire_est_autorise_sans_affectation_equipe(): void
    {
        $gestionnaire = $this->creerPersonne(['gestionnaire']);
        $chauffeur = $this->creerPersonne(['benevole']);

        $this->assertTrue($this->policy->gerer($gestionnaire, $this->creerRoute($this->creerCampagne()->id, $chauffeur->id)));
    }

    public function test_membre_equipe_chargement_de_la_bonne_campagne_est_autorise(): void
    {
        $campagne = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $chauffeur = $this->creerPersonne(['benevole']);
        $this->assignerEquipe($campagne, $personne->id, 'equipe_chargement');

        $this->assertTrue($this->policy->gerer($personne, $this->creerRoute($campagne->id, $chauffeur->id)));
    }

    /**
     * The route's OWN driver (id_benevole) being the acting personne must
     * make no difference — RouteLivraisonPolicy is entirely about the
     * equipe_chargement STAFF role, not the assigned driver. The driver's
     * own access to their route lives under role:benevole/MaRouteController
     * instead (see this policy's own docblock — explicitly out of scope).
     */
    public function test_le_chauffeur_assigne_lui_meme_nest_pas_autorise_sans_role_equipe_chargement(): void
    {
        $campagne = $this->creerCampagne();
        $chauffeur = $this->creerPersonne(['benevole']);

        $this->assertFalse($this->policy->gerer($chauffeur, $this->creerRoute($campagne->id, $chauffeur->id)));
    }

    public function test_membre_equipe_chargement_dune_autre_campagne_nest_pas_autorise(): void
    {
        $campagneA = $this->creerCampagne();
        $campagneB = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $chauffeur = $this->creerPersonne(['benevole']);
        $this->assignerEquipe($campagneA, $personne->id, 'equipe_chargement');

        $this->assertFalse($this->policy->gerer($personne, $this->creerRoute($campagneB->id, $chauffeur->id)));
    }

    public function test_membre_dune_autre_equipe_nest_pas_autorise(): void
    {
        $campagne = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $chauffeur = $this->creerPersonne(['benevole']);
        $this->assignerEquipe($campagne, $personne->id, 'equipe_packaging'); // wrong team

        $this->assertFalse($this->policy->gerer($personne, $this->creerRoute($campagne->id, $chauffeur->id)));
    }
}
