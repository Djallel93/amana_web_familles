<?php
// tests/Feature/Services/LivraisonGenerationServiceTest.php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Campagne;
use App\Models\Famille;
use App\Models\Livraison;
use App\Services\LivraisonGenerationService;
use Tests\TestCase;

class LivraisonGenerationServiceTest extends TestCase
{
    private LivraisonGenerationService $generation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generation = new LivraisonGenerationService();
    }

    private function creerCampagne(array $overrides = []): Campagne
    {
        return Campagne::create(array_merge([
            'type' => 'zakat_el_fitr',
            'statut' => 'en_cours',
            'date_livraison' => now()->addWeek()->toDateString(),
            'poids_moyen_kg' => 10.0,
            'poids_moyen_hotel_kg' => 5.0,
            'poids_moyen_etudiant_kg' => 6.0,
        ], $overrides));
    }

    // ── eligibles() ──────────────────────────────────────────────────────

    public function test_eligibles_ne_retourne_que_les_dossiers_valides(): void
    {
        Famille::factory()->create(['etat_dossier' => 'Validé']);
        Famille::factory()->create(['etat_dossier' => 'En cours']);
        Famille::factory()->create(['etat_dossier' => 'Recu']);

        $this->assertSame(1, $this->generation->eligibles()->count());
    }

    public function test_eligibles_exclut_les_familles_deja_pourvues_pour_cette_campagne(): void
    {
        $campagne = $this->creerCampagne();
        $autreCampagne = $this->creerCampagne();

        $dejaGeneree = Famille::factory()->create(['etat_dossier' => 'Validé']);
        Livraison::create([
            'id_famille' => $dejaGeneree->id,
            'id_campagne' => $campagne->id,
            'statut' => 'non_assignee',
            'statut_conditionnement' => 'en_attente',
            'nombre_personnes' => 1,
            'poids_kg' => 10.0,
            'statut_contact' => 'a_contacter',
        ]);
        $pasEncoreGeneree = Famille::factory()->create(['etat_dossier' => 'Validé']);

        $resultat = $this->generation->eligibles(campagne: $campagne)->pluck('id');

        $this->assertFalse($resultat->contains($dejaGeneree->id));
        $this->assertTrue($resultat->contains($pasEncoreGeneree->id));

        // Same family is still eligible for a DIFFERENT campagne — only
        // excluded for the one it already has a Livraison in.
        $resultatAutre = $this->generation->eligibles(campagne: $autreCampagne)->pluck('id');
        $this->assertTrue($resultatAutre->contains($dejaGeneree->id));
    }

    public function test_eligibles_filtre_par_criticite_minimum(): void
    {
        Famille::factory()->create(['etat_dossier' => 'Validé', 'criticite' => 2]);
        $critique = Famille::factory()->create(['etat_dossier' => 'Validé', 'criticite' => 5]);

        $resultat = $this->generation->eligibles(['criticite_min' => 4])->pluck('id');

        $this->assertSame([$critique->id], $resultat->all());
    }

    public function test_eligibles_trie_par_criticite_decroissante_par_defaut(): void
    {
        $basse = Famille::factory()->create(['etat_dossier' => 'Validé', 'criticite' => 1]);
        $haute = Famille::factory()->create(['etat_dossier' => 'Validé', 'criticite' => 5]);

        $resultat = $this->generation->eligibles()->pluck('id')->all();

        $this->assertSame([$haute->id, $basse->id], $resultat);
    }

    // ── genererPour() ────────────────────────────────────────────────────

    public function test_genererPour_cree_une_livraison_par_famille_selectionnee(): void
    {
        $campagne = $this->creerCampagne();
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé', 'nombre_adulte' => 2, 'nombre_enfant' => 1, 'etudiant' => false, 'est_hotel' => false]);

        $resultat = $this->generation->genererPour($campagne, [$famille->id]);

        $this->assertCount(1, $resultat['livraisons']);
        $this->assertSame(0, $resultat['deja_existantes']);
        $this->assertCount(0, $resultat['conflits']);

        $livraison = $resultat['livraisons']->first();
        $this->assertSame($famille->id, $livraison->id_famille);
        $this->assertSame(3, $livraison->nombre_personnes); // 2 adultes + 1 enfant
        $this->assertSame('non_assignee', $livraison->statut);
    }

    public function test_genererPour_saute_silencieusement_une_famille_deja_generee(): void
    {
        $campagne = $this->creerCampagne();
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé']);

        $premier = $this->generation->genererPour($campagne, [$famille->id]);
        $second = $this->generation->genererPour($campagne, [$famille->id]);

        $this->assertCount(0, $second['livraisons']);
        $this->assertSame(1, $second['deja_existantes']);
        // Still exactly one Livraison row for this famille/campagne pair.
        $this->assertSame(1, Livraison::where('id_famille', $famille->id)->where('id_campagne', $campagne->id)->count());
        $this->assertCount(1, $premier['livraisons']);
    }

    /**
     * Decision (31/08/2026, per the service docblock): etudiant AND
     * est_hotel at once is excluded from generation and reported in
     * `conflits`, rather than a random rate being picked.
     */
    public function test_genererPour_exclut_les_familles_etudiant_et_hotel_a_la_fois(): void
    {
        $campagne = $this->creerCampagne();
        $conflit = Famille::factory()->create(['etat_dossier' => 'Validé', 'etudiant' => true, 'est_hotel' => true]);
        $normale = Famille::factory()->create(['etat_dossier' => 'Validé', 'etudiant' => false, 'est_hotel' => false]);

        $resultat = $this->generation->genererPour($campagne, [$conflit->id, $normale->id]);

        $this->assertCount(1, $resultat['conflits']);
        $this->assertSame($conflit->id, $resultat['conflits']->first()->id);
        $this->assertCount(1, $resultat['livraisons']);
        $this->assertSame($normale->id, $resultat['livraisons']->first()->id_famille);
        $this->assertSame(0, Livraison::where('id_famille', $conflit->id)->count());
    }

    public function test_genererPour_applique_le_taux_hotel_quand_est_hotel_seul(): void
    {
        $campagne = $this->creerCampagne(['poids_moyen_kg' => 10.0, 'poids_moyen_hotel_kg' => 5.0]);
        $famille = Famille::factory()->create([
            'etat_dossier' => 'Validé', 'etudiant' => false, 'est_hotel' => true,
            'nombre_adulte' => 2, 'nombre_enfant' => 0,
        ]);

        $resultat = $this->generation->genererPour($campagne, [$famille->id]);

        // 2 personnes * 5.0kg (taux hotel) = 10.0kg, NOT 2 * 10.0 (taux normal).
        $this->assertEqualsWithDelta(10.0, (float) $resultat['livraisons']->first()->poids_kg, 0.01);
    }

    public function test_genererPour_cree_un_colis_par_personne(): void
    {
        $campagne = $this->creerCampagne();
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé', 'nombre_adulte' => 2, 'nombre_enfant' => 2, 'etudiant' => false, 'est_hotel' => false]);

        $resultat = $this->generation->genererPour($campagne, [$famille->id]);

        $this->assertCount(4, $resultat['livraisons']->first()->colis);
        $this->assertSame([1, 2, 3, 4], $resultat['livraisons']->first()->colis->pluck('numero')->sort()->values()->all());
    }

    public function test_genererPour_snapshot_note_besoins_speciaux_depuis_specificites(): void
    {
        $campagne = $this->creerCampagne();
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé', 'specificites' => 'Allergie arachides', 'etudiant' => false, 'est_hotel' => false]);

        $resultat = $this->generation->genererPour($campagne, [$famille->id]);

        $this->assertSame('Allergie arachides', $resultat['livraisons']->first()->note_besoins_speciaux);
    }
}
