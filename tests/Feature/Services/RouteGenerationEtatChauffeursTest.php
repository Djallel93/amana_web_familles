<?php
// tests/Feature/Services/RouteGenerationEtatChauffeursTest.php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\BenevoleDisponibilite;
use App\Models\CampagneJournee;
use App\Models\RouteLivraison;
use App\Services\RouteGenerationService;
use App\Support\Creneau;
use Illuminate\Support\Facades\Notification;
use ReflectionMethod;
use Tests\Concerns\BuildsDisponibiliteFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * 18/10/2026 — pool de chauffeurs : un chauffeur « parti » (réponse « Non » à « Revenez-vous au QG ? ») n'est
 * plus proposé, quel que soit le créneau ; un chauffeur « en livraison » reste proposé avec un avertissement.
 */
class RouteGenerationEtatChauffeursTest extends TestCase
{
    use BuildsDisponibiliteFixtures;
    use SeedsCommunFixtures;

    private array $vehicules;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
        $this->vehicules = $this->creerVehicules();
    }

    private function chauffeurDisponible(CampagneJournee $journee, string $creneau = Creneau::MATIN_2): int
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        $dispo = BenevoleDisponibilite::create([
            'id_personne' => $benevole->id,
            'id_campagne_journee' => $journee->id,
            'statut' => 'confirme',
            'vehicule_confirme' => true,
        ]);
        $dispo->creneaux()->create(['creneau' => $creneau]);

        return $benevole->id;
    }

    private function route(CampagneJournee $journee, int $idBenevole, string $statut, ?bool $retourQg = null, ?string $creneau = Creneau::MATIN_1): RouteLivraison
    {
        return RouteLivraison::create([
            'id_campagne' => $journee->id_campagne,
            'id_campagne_journee' => $journee->id,
            'id_benevole' => $idBenevole,
            'id_vehicule_type' => $this->vehicules['voiture'],
            'creneau' => $creneau,
            'statut' => $statut,
            'retour_qg' => $retourQg,
        ]);
    }

    /** @return array<string, mixed> */
    private function ligne(CampagneJournee $journee, int $idBenevole, string $creneau = Creneau::MATIN_2): array
    {
        $ligne = collect(app(RouteGenerationService::class)->chauffeursDisponibles($journee, $creneau))
            ->firstWhere('id_personne', $idBenevole);
        $this->assertNotNull($ligne, 'Le chauffeur doit figurer dans la liste.');

        return $ligne;
    }

    /** @return array<int, array<string, mixed>> */
    private function pool(CampagneJournee $journee, string $creneau = Creneau::MATIN_2): array
    {
        $methode = new ReflectionMethod(RouteGenerationService::class, 'vehiculesDisponiblesPour');
        $methode->setAccessible(true);

        return $methode->invoke(app(RouteGenerationService::class), $journee, $creneau);
    }

    public function test_un_chauffeur_sans_tournee_n_est_ni_parti_ni_en_livraison(): void
    {
        [, $journee] = $this->creerCampagneAvecJournee();
        $id = $this->chauffeurDisponible($journee);

        $ligne = $this->ligne($journee, $id);

        $this->assertFalse($ligne['parti']);
        $this->assertFalse($ligne['en_livraison']);
        $this->assertFalse($ligne['occupe']);
    }

    public function test_un_non_au_retour_au_qg_met_le_chauffeur_parti_sur_tous_les_creneaux(): void
    {
        [, $journee] = $this->creerCampagneAvecJournee();
        $id = $this->chauffeurDisponible($journee, Creneau::MATIN_2);
        $this->route($journee, $id, 'livraisons_terminees', retourQg: false, creneau: Creneau::MATIN_1);

        $ligne = $this->ligne($journee, $id, Creneau::MATIN_2);

        // Sa tournée était sur un AUTRE créneau : « occupé » ne le couvrait pas, « parti » oui.
        $this->assertFalse($ligne['occupe']);
        $this->assertTrue($ligne['parti']);
        $this->assertFalse($ligne['en_livraison']);
    }

    public function test_un_chauffeur_parti_est_en_fin_de_liste_et_hors_du_pool_de_vehicules(): void
    {
        [, $journee] = $this->creerCampagneAvecJournee();
        $parti = $this->chauffeurDisponible($journee);
        $libre = $this->chauffeurDisponible($journee);
        $this->route($journee, $parti, 'livraisons_terminees', retourQg: false);

        $liste = app(RouteGenerationService::class)->chauffeursDisponibles($journee, Creneau::MATIN_2);
        $this->assertSame($libre, $liste[0]['id_personne']);
        $this->assertSame($parti, $liste[1]['id_personne']);

        $this->assertSame([$libre], array_column($this->pool($journee), 'id_benevole'));
    }

    public function test_un_chauffeur_en_cours_de_tournee_est_en_livraison_mais_reste_dans_le_pool(): void
    {
        [, $journee] = $this->creerCampagneAvecJournee();
        $id = $this->chauffeurDisponible($journee);
        $this->route($journee, $id, 'en_cours', creneau: Creneau::MATIN_1);

        $ligne = $this->ligne($journee, $id);

        $this->assertTrue($ligne['en_livraison']);
        $this->assertFalse($ligne['parti']);
        $this->assertSame([$id], array_column($this->pool($journee), 'id_benevole'));
    }

    public function test_livraisons_terminees_sans_reponse_compte_comme_en_livraison(): void
    {
        [, $journee] = $this->creerCampagneAvecJournee();
        $id = $this->chauffeurDisponible($journee);
        $this->route($journee, $id, 'livraisons_terminees', retourQg: null, creneau: Creneau::MATIN_1);

        $this->assertTrue($this->ligne($journee, $id)['en_livraison']);
    }

    public function test_un_chauffeur_de_retour_n_est_plus_ni_parti_ni_en_livraison(): void
    {
        [, $journee] = $this->creerCampagneAvecJournee();
        $id = $this->chauffeurDisponible($journee);
        $this->route($journee, $id, 'terminee', retourQg: true, creneau: Creneau::MATIN_1);

        $ligne = $this->ligne($journee, $id);

        $this->assertFalse($ligne['parti']);
        $this->assertFalse($ligne['en_livraison']);
    }

    public function test_chauffeur_est_parti_reflete_la_reponse_non_de_la_journee(): void
    {
        [, $journee] = $this->creerCampagneAvecJournee();
        $service = app(RouteGenerationService::class);
        $id = $this->chauffeurDisponible($journee);

        $this->assertFalse($service->chauffeurEstParti($journee, $id));

        $this->route($journee, $id, 'livraisons_terminees', retourQg: false);

        $this->assertTrue($service->chauffeurEstParti($journee, $id));
    }

    public function test_un_non_d_une_autre_journee_ne_rend_pas_le_chauffeur_parti(): void
    {
        [$campagne, $journee] = $this->creerCampagneAvecJournee();
        $autreJournee = $campagne->ajouterJournee('2026-11-11');
        $id = $this->chauffeurDisponible($journee);
        $this->route($autreJournee, $id, 'livraisons_terminees', retourQg: false);

        $this->assertFalse(app(RouteGenerationService::class)->chauffeurEstParti($journee, $id));
    }
}
