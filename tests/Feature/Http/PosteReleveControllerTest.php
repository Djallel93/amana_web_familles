<?php
// tests/Feature/Http/PosteReleveControllerTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\CampagneArrivee;
use App\Models\Donation;
use Tests\Concerns\BuildsCampagneEquipeFixtures;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Covers both flows served by the merged PosteReleveController (Section
 * A1 of the refactor: PeseeController + ReceptionController → one class,
 * see its own docblock) at the HTTP/route level — hitting the actual
 * named routes rather than calling controller methods directly, so this
 * stays correct across any further internal restructuring, per the
 * Phase 4 brief.
 *
 * Each test loops over both { type, role, champ, valeur } cases via
 * casPourType() rather than duplicating the whole file twice — the two
 * flows are close to identical by design (RelevePosteDefinition is the
 * single source of what differs).
 */
class PosteReleveControllerTest extends TestCase
{
    use BuildsCampagneEquipeFixtures;
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargerRolesFamilles();
    }

    /**
     * @return array<string, array{type: string, role: string, champ: string, valeur: float|int, accesseurTotal: string}>
     */
    private function casPourType(): array
    {
        return [
            'pesee' => ['type' => 'pesee', 'role' => 'equipe_pesee', 'champ' => 'poids_kg', 'valeur' => 12.5, 'accesseurTotal' => 'poids_collecte_kg'],
            'reception' => ['type' => 'reception', 'role' => 'equipe_reception', 'champ' => 'nombre_donateur', 'valeur' => 3, 'accesseurTotal' => 'nombre_menages'],
        ];
    }

    // ── choisir() ────────────────────────────────────────────────────────

    public function test_choisir_est_accessible_a_un_membre_du_role_global_equipe(): void
    {
        foreach ($this->casPourType() as $cas) {
            $personne = $this->creerPersonne([$cas['role']]);

            $this->actingAs($personne)
                ->get(route("livraison.{$cas['type']}.choisir"))
                ->assertOk();
        }
    }

    public function test_choisir_redirige_une_personne_sans_aucun_role_equipe(): void
    {
        foreach ($this->casPourType() as $cas) {
            $benevole = $this->creerPersonne(['benevole']);

            $this->actingAs($benevole)
                ->get(route("livraison.{$cas['type']}.choisir"))
                ->assertRedirect(); // EnsureLivraisonRole redirects, doesn't 403 — see its docblock
        }
    }

    // ── show()/enregistrer()/journal() — accès (CampagnePolicy) ─────────

    public function test_show_enregistrer_journal_refusent_une_personne_hors_equipe_avec_403(): void
    {
        foreach ($this->casPourType() as $cas) {
            $campagne = $this->creerCampagne();
            $benevole = $this->creerPersonne(['benevole']);

            $this->actingAs($benevole)->get(route("livraison.{$cas['type']}.show", $campagne))->assertForbidden();
            $this->actingAs($benevole)->postJson(route("livraison.{$cas['type']}.enregistrer", $campagne), [$cas['champ'] => $cas['valeur']])->assertForbidden();
            $this->actingAs($benevole)->getJson(route("livraison.{$cas['type']}.journal", $campagne))->assertForbidden();
        }
    }

    public function test_show_enregistrer_journal_sont_accessibles_a_un_membre_de_lequipe_affecte_a_cette_campagne(): void
    {
        foreach ($this->casPourType() as $cas) {
            $campagne = $this->creerCampagne();
            $personne = $this->creerPersonne(['membre']);
            $this->assignerEquipe($campagne, $personne->id, $cas['role']);

            $this->actingAs($personne)->get(route("livraison.{$cas['type']}.show", $campagne))->assertOk();
            $this->actingAs($personne)->postJson(route("livraison.{$cas['type']}.enregistrer", $campagne), [$cas['champ'] => $cas['valeur']])->assertOk();
            $this->actingAs($personne)->getJson(route("livraison.{$cas['type']}.journal", $campagne))->assertOk();
        }
    }

    // ── enregistrer() ────────────────────────────────────────────────────

    public function test_enregistrer_cree_une_ligne_et_met_a_jour_le_total_de_campagne(): void
    {
        foreach ($this->casPourType() as $cas) {
            $campagne = $this->creerCampagne();
            $personne = $this->creerPersonne(['membre']);
            $this->assignerEquipe($campagne, $personne->id, $cas['role']);

            $reponse = $this->actingAs($personne)->postJson(
                route("livraison.{$cas['type']}.enregistrer", $campagne),
                [$cas['champ'] => $cas['valeur']],
            );

            $reponse->assertOk()->assertJsonPath('success', true);
            $this->assertEqualsWithDelta($cas['valeur'], $reponse->json('total_campagne'), 0.01);
            $accesseur = $cas['accesseurTotal'];
            $this->assertEqualsWithDelta($cas['valeur'], $campagne->fresh()->{$accesseur}, 0.01);
        }
    }

    public function test_enregistrer_rejette_une_valeur_hors_bornes_avec_422(): void
    {
        $campagne = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagne, $personne->id, 'equipe_pesee');

        // poids_kg max is 2000 (RelevePosteDefinition::pesee()).
        $this->actingAs($personne)
            ->postJson(route('livraison.pesee.enregistrer', $campagne), ['poids_kg' => 5000])
            ->assertStatus(422);

        $this->assertSame(0, Donation::count());
    }

    public function test_enregistrer_associe_la_journee_quand_fournie(): void
    {
        $campagne = $this->creerCampagne();
        $journee = $campagne->journees()->create(['date' => now()->addDay()->toDateString(), 'ordre' => 1]);
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagne, $personne->id, 'equipe_pesee');

        $this->actingAs($personne)->postJson(route('livraison.pesee.enregistrer', $campagne), [
            'poids_kg' => 10.0,
            'id_campagne_journee' => $journee->id,
        ])->assertOk();

        $this->assertSame($journee->id, Donation::first()->id_campagne_journee);
    }

    // ── journal() ────────────────────────────────────────────────────────

    public function test_journal_liste_les_relevés_les_plus_recents_dabord_et_totalise(): void
    {
        $campagne = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagne, $personne->id, 'equipe_pesee');

        $ancien = Donation::create(['id_campagne' => $campagne->id, 'poids_kg' => 5.0, 'horodatage' => now()->subHour(), 'logge_par' => $personne->id]);
        $recent = Donation::create(['id_campagne' => $campagne->id, 'poids_kg' => 7.0, 'horodatage' => now(), 'logge_par' => $personne->id]);

        $reponse = $this->actingAs($personne)->getJson(route('livraison.pesee.journal', $campagne));

        $reponse->assertOk();
        $this->assertSame($recent->id, $reponse->json('dons.0.id'));
        $this->assertSame($ancien->id, $reponse->json('dons.1.id'));
        $this->assertEqualsWithDelta(12.0, $reponse->json('total_kg'), 0.01);
    }

    public function test_journal_filtre_par_journee_quand_fournie(): void
    {
        $campagne = $this->creerCampagne();
        $journeeA = $campagne->journees()->create(['date' => now()->toDateString(), 'ordre' => 1]);
        $journeeB = $campagne->journees()->create(['date' => now()->addDay()->toDateString(), 'ordre' => 2]);
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagne, $personne->id, 'equipe_reception');

        CampagneArrivee::create(['id_campagne' => $campagne->id, 'id_campagne_journee' => $journeeA->id, 'nombre_donateur' => 2, 'logge_par' => $personne->id]);
        CampagneArrivee::create(['id_campagne' => $campagne->id, 'id_campagne_journee' => $journeeB->id, 'nombre_donateur' => 5, 'logge_par' => $personne->id]);

        $reponse = $this->actingAs($personne)->getJson(
            route('livraison.reception.journal', $campagne) . '?id_campagne_journee=' . $journeeA->id,
        );

        $reponse->assertOk();
        $this->assertCount(1, $reponse->json('arrivees'));
        $this->assertSame(2, $reponse->json('total_donateurs'));
    }

    // ── modifierDon()/modifierArrivee() / supprimerDon()/supprimerArrivee() ──

    /**
     * Docblock: "pas de restriction de propriété (n'importe quel membre
     * de l'équipe peut modifier une ligne saisie par quelqu'un d'autre)".
     */
    public function test_un_autre_membre_de_lequipe_peut_modifier_une_ligne_saisie_par_quelquun_dautre(): void
    {
        $campagne = $this->creerCampagne();
        $quiASaisi = $this->creerPersonne(['membre']);
        $autreMembre = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagne, $quiASaisi->id, 'equipe_pesee');
        $this->assignerEquipe($campagne, $autreMembre->id, 'equipe_pesee');
        $don = Donation::create(['id_campagne' => $campagne->id, 'poids_kg' => 5.0, 'logge_par' => $quiASaisi->id]);

        $this->actingAs($autreMembre)
            ->patchJson(route('livraison.pesee.dons.modifier', $don), ['poids_kg' => 8.0])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertEqualsWithDelta(8.0, $don->fresh()->poids_kg, 0.01);
    }

    public function test_supprimer_don_et_arrivee_retirent_bien_la_ligne(): void
    {
        $campagneA = $this->creerCampagne();
        $campagneB = $this->creerCampagne();
        $personne = $this->creerPersonne(['membre']);
        $this->assignerEquipe($campagneA, $personne->id, 'equipe_pesee');
        $this->assignerEquipe($campagneB, $personne->id, 'equipe_reception');
        $don = Donation::create(['id_campagne' => $campagneA->id, 'poids_kg' => 5.0, 'logge_par' => $personne->id]);
        $arrivee = CampagneArrivee::create(['id_campagne' => $campagneB->id, 'nombre_donateur' => 1, 'logge_par' => $personne->id]);

        $this->actingAs($personne)->deleteJson(route('livraison.pesee.dons.supprimer', $don))->assertOk();
        $this->actingAs($personne)->deleteJson(route('livraison.reception.arrivees.supprimer', $arrivee))->assertOk();

        $this->assertSame(0, Donation::count());
        $this->assertSame(0, CampagneArrivee::count());
    }

    public function test_modifier_et_supprimer_refusent_une_personne_hors_equipe(): void
    {
        $campagne = $this->creerCampagne();
        $benevole = $this->creerPersonne(['benevole']);
        $don = Donation::create(['id_campagne' => $campagne->id, 'poids_kg' => 5.0, 'logge_par' => 1]);

        $this->actingAs($benevole)->patchJson(route('livraison.pesee.dons.modifier', $don), ['poids_kg' => 1.0])->assertForbidden();
        $this->actingAs($benevole)->deleteJson(route('livraison.pesee.dons.supprimer', $don))->assertForbidden();
        $this->assertSame(1, Donation::count());
    }
}
