<?php
// tests/Feature/Services/RouteGenerationVehiculesTest.php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\BenevoleDisponibilite;
use App\Services\RouteGenerationService;
use Illuminate\Support\Facades\Notification;
use ReflectionMethod;
use Tests\Concerns\BuildsDisponibiliteFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Pool de véhicules d'un créneau (01/10/2026) : le véhicule est celui
 * déclaré pour la JOURNÉE, sinon celui du profil ; une capacité nulle
 * (« Sans permis », « Non véhiculé ») n'entre jamais dans le pool.
 */
class RouteGenerationVehiculesTest extends TestCase
{
    use SeedsCommunFixtures;
    use BuildsDisponibiliteFixtures;

    private array $vehicules;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chargerRolesFamilles();
        $this->vehicules = $this->creerVehicules();
    }

    /** @return array<int, array<string, mixed>> */
    private function pool($journee, string $creneau = '08-10'): array
    {
        $methode = new ReflectionMethod(RouteGenerationService::class, 'vehiculesDisponiblesPour');
        $methode->setAccessible(true);

        return $methode->invoke(app(RouteGenerationService::class), $journee, $creneau);
    }

    private function disponibilite(int $idPersonne, int $idJournee, array $attributs): void
    {
        $dispo = BenevoleDisponibilite::create(array_merge([
            'id_personne' => $idPersonne, 'id_campagne_journee' => $idJournee, 'statut' => 'confirme',
        ], $attributs));
        $dispo->creneaux()->create(['creneau' => '08-10']);
    }

    public function test_sans_vehicule_declare_pour_la_journee_le_vehicule_du_profil_s_applique(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        [, $journee] = $this->creerCampagneAvecJournee();
        $this->disponibilite($benevole->id, $journee->id, ['vehicule_confirme' => true]);

        $pool = $this->pool($journee);

        $this->assertCount(1, $pool);
        $this->assertSame($this->vehicules['voiture'], $pool[0]['id_vehicule_type']);
        $this->assertSame(200.0, $pool[0]['capacite_kg']);
    }

    public function test_une_ligne_sans_information_vehicule_retombe_sur_le_profil(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        [, $journee] = $this->creerCampagneAvecJournee();
        // Ex. un gestionnaire n'a modifié que les créneaux.
        $this->disponibilite($benevole->id, $journee->id, ['vehicule_confirme' => false]);

        $this->assertSame($this->vehicules['voiture'], $this->pool($journee)[0]['id_vehicule_type']);
    }

    public function test_le_vehicule_de_la_journee_remplace_celui_du_profil(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        [, $journee] = $this->creerCampagneAvecJournee();
        $this->disponibilite($benevole->id, $journee->id, [
            'vehicule_confirme' => false, 'permis' => true, 'id_vehicule_type' => $this->vehicules['utilitaire'],
        ]);

        $pool = $this->pool($journee);

        $this->assertCount(1, $pool);
        $this->assertSame($this->vehicules['utilitaire'], $pool[0]['id_vehicule_type']);
        $this->assertSame(500.0, $pool[0]['capacite_kg']);
    }

    public function test_un_benevole_sans_permis_ce_jour_la_est_ignore_meme_avec_une_voiture_au_profil(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        [, $journee] = $this->creerCampagneAvecJournee();
        $this->disponibilite($benevole->id, $journee->id, [
            'vehicule_confirme' => false, 'permis' => false, 'id_vehicule_type' => $this->vehicules['sans_permis'],
        ]);

        $this->assertSame([], $this->pool($journee));
    }

    public function test_un_profil_non_vehicule_confirme_tel_quel_est_ignore(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['non_vehicule']);
        [, $journee] = $this->creerCampagneAvecJournee();
        $this->disponibilite($benevole->id, $journee->id, ['vehicule_confirme' => true]);

        $this->assertSame([], $this->pool($journee));
    }

    public function test_seuls_les_benevoles_du_creneau_demande_comptent(): void
    {
        $benevole = $this->creerBenevole($this->vehicules['voiture']);
        [, $journee] = $this->creerCampagneAvecJournee();
        $this->disponibilite($benevole->id, $journee->id, ['vehicule_confirme' => true]);

        $this->assertSame([], $this->pool($journee, '16-18'));
    }

    public function test_un_compte_sans_profil_et_sans_vehicule_declare_n_entre_pas_dans_le_pool(): void
    {
        $compte = $this->creerPersonne(['benevole']);
        [, $journee] = $this->creerCampagneAvecJournee();
        $this->disponibilite($compte->id, $journee->id, ['vehicule_confirme' => true]);

        $this->assertSame([], $this->pool($journee));
    }
}
