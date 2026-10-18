<?php
// tests/Feature/Http/FamilleCommentairesTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Jobs\ResoudreAdresseFamille;
use App\Models\Campagne;
use App\Models\Famille;
use App\Models\Livraison;
use App\Services\FamilleConfirmationSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * 17/10/2026 — commentaire_dossier : une ligne par changement de statut, datée, signée et marquée de sa
 * source (« - 17/10/2026 14:32 · Prénom NOM · manuel : … », « · System · sync_confirmation : … »), et
 * commentaire obligatoire pour changer le statut depuis le panneau du dossier.
 */
class FamilleCommentairesTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow('2026-10-17 14:32:00');
        $this->chargerRolesFamilles();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Famille::ajouterCommentaire() ────────────────────────────────────

    public function test_la_premiere_ligne_remplit_un_commentaire_vide_puis_les_suivantes_sajoutent(): void
    {
        $famille = Famille::factory()->create(['commentaire_dossier' => null]);

        $famille->ajouterCommentaire('Premier motif', 'sync_externe');
        $this->assertSame('- 17/10/2026 14:32 · System · sync_externe : Premier motif', $famille->fresh()->commentaire_dossier);

        $famille->ajouterCommentaire('Second motif', 'import');
        $this->assertSame(
            "- 17/10/2026 14:32 · System · sync_externe : Premier motif\n- 17/10/2026 14:32 · System · import : Second motif",
            $famille->fresh()->commentaire_dossier,
        );
    }

    public function test_un_commentaire_existant_nest_jamais_ecrase(): void
    {
        $famille = Famille::factory()->create(['commentaire_dossier' => "Note libre du staff\n"]);

        $famille->ajouterCommentaire('Ajout', 'sync_confirmation');

        $this->assertSame(
            "Note libre du staff\n- 17/10/2026 14:32 · System · sync_confirmation : Ajout",
            $famille->fresh()->commentaire_dossier,
        );
    }

    public function test_lauteur_est_la_personne_connectee_pour_la_source_manuelle(): void
    {
        $personne = $this->creerPersonne(['gestionnaire']);
        $this->actingAs($personne);
        $famille = Famille::factory()->create(['commentaire_dossier' => null]);

        $famille->ajouterCommentaire('Fait à la main', 'manuel');

        $this->assertSame(
            "- 17/10/2026 14:32 · {$personne->nom_complet} · manuel : Fait à la main",
            $famille->fresh()->commentaire_dossier,
        );
    }

    public function test_sans_utilisateur_la_source_manuelle_retombe_sur_system(): void
    {
        $famille = Famille::factory()->create(['commentaire_dossier' => null]);

        $famille->ajouterCommentaire('Sans session', 'manuel');

        $this->assertSame('- 17/10/2026 14:32 · System · manuel : Sans session', $famille->fresh()->commentaire_dossier);
    }

    public function test_les_retours_a_la_ligne_sont_aplatis_et_un_texte_vide_est_ignore(): void
    {
        $famille = Famille::factory()->create(['commentaire_dossier' => null]);

        $famille->ajouterCommentaire("Ligne 1\n- fausse ligne\r\n   Ligne 3", 'import');
        $famille->ajouterCommentaire("  \n ", 'import');

        $this->assertSame(
            '- 17/10/2026 14:32 · System · import : Ligne 1 - fausse ligne Ligne 3',
            $famille->fresh()->commentaire_dossier,
        );
    }

    public function test_une_source_inconnue_est_refusee(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Famille::factory()->create()->ajouterCommentaire('x', 'inconnue');
    }

    // ── Panneau du dossier : changement de statut ────────────────────────

    /** @return array<string, mixed> */
    private function payload(Famille $famille, array $surcharge = []): array
    {
        return array_merge($famille->only([
            'nom', 'prenom', 'email', 'telephone', 'nombre_adulte', 'nombre_enfant',
            'adresse', 'code_postal', 'ville_texte', 'criticite', 'langue', 'etat_dossier', 'commentaire_dossier',
        ]), [
            'zakat_el_fitr' => false,
            'sadaqa' => false,
            'est_hotel' => false,
            'etudiant' => false,
        ], $surcharge);
    }

    public function test_changer_le_statut_sans_commentaire_est_refuse(): void
    {
        Queue::fake();
        $famille = Famille::factory()->create(['etat_dossier' => 'En attente', 'commentaire_dossier' => null]);

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->putJson(route('familles.update', $famille->id), $this->payload($famille, ['etat_dossier' => 'Validé']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['commentaire_statut']);

        $famille->refresh();
        $this->assertSame('En attente', $famille->etat_dossier);
        $this->assertNull($famille->commentaire_dossier);

        // Un commentaire d'espaces n'en est pas un.
        $this->putJson(route('familles.update', $famille->id), $this->payload($famille, ['etat_dossier' => 'Validé', 'commentaire_statut' => '   ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['commentaire_statut']);
    }

    public function test_changer_le_statut_avec_commentaire_ajoute_une_ligne_signee(): void
    {
        Queue::fake();
        $personne = $this->creerPersonne(['gestionnaire']);
        $famille = Famille::factory()->create(['etat_dossier' => 'En attente', 'commentaire_dossier' => null]);

        $this->actingAs($personne)
            ->putJson(route('familles.update', $famille->id), $this->payload($famille, [
                'etat_dossier' => 'Validé',
                'commentaire_statut' => 'Pièces vérifiées',
            ]))
            ->assertOk();

        $famille->refresh();
        $this->assertSame('Validé', $famille->etat_dossier);
        $this->assertSame(
            "- 17/10/2026 14:32 · {$personne->nom_complet} · manuel : Statut En attente → Validé : Pièces vérifiées",
            $famille->commentaire_dossier,
        );
    }

    public function test_la_saisie_manuelle_du_commentaire_est_conservee_avant_la_ligne_de_statut(): void
    {
        Queue::fake();
        $personne = $this->creerPersonne(['gestionnaire']);
        $famille = Famille::factory()->create(['etat_dossier' => 'En attente', 'commentaire_dossier' => null]);

        $this->actingAs($personne)
            ->putJson(route('familles.update', $famille->id), $this->payload($famille, [
                'etat_dossier' => 'Archivé',
                'commentaire_dossier' => 'Rappelée deux fois',
                'commentaire_statut' => 'Déménagement',
            ]))
            ->assertOk();

        $this->assertSame(
            "Rappelée deux fois\n- 17/10/2026 14:32 · {$personne->nom_complet} · manuel : Statut En attente → Archivé : Déménagement",
            $famille->fresh()->commentaire_dossier,
        );
    }

    public function test_enregistrer_sans_toucher_au_statut_ne_demande_pas_de_commentaire(): void
    {
        Queue::fake();
        $famille = Famille::factory()->create(['etat_dossier' => 'Validé', 'commentaire_dossier' => 'Déjà là']);

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->putJson(route('familles.update', $famille->id), $this->payload($famille, ['nom' => 'Nouveau nom']))
            ->assertOk();

        $famille->refresh();
        $this->assertSame('Nouveau nom', $famille->nom);
        $this->assertSame('Déjà là', $famille->commentaire_dossier);
    }

    public function test_dossier_ouvert_la_reference_est_le_statut_davant_verrouillage_pas_en_cours(): void
    {
        Queue::fake();
        // État tel que laissé par show() : 'En cours' stocké, vrai statut mis de côté.
        $famille = Famille::factory()->create(['etat_dossier' => 'En cours', 'commentaire_dossier' => null]);
        // Colonne non fillable : posée comme le fait FamillesController::prendreVerrou().
        $famille->forceFill(['etat_dossier_avant_verrouillage' => 'Validé'])->saveQuietly();

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->putJson(route('familles.update', $famille->id), $this->payload($famille, ['etat_dossier' => 'Validé']))
            ->assertOk();

        $famille->refresh();
        $this->assertSame('Validé', $famille->etat_dossier);
        $this->assertNull($famille->commentaire_dossier);
    }

    // ── Archiver / rejeter / réinitialiser un contact ────────────────────

    private function livraison(array $surcharge = []): Livraison
    {
        $campagne = Campagne::create([
            'type' => 'zakat_el_fitr',
            'statut' => 'en_cours',
            'date_livraison' => '2026-11-10',
            'poids_moyen_kg' => 5,
        ]);

        return Livraison::create(array_merge([
            'id_famille' => Famille::factory()->create(['etat_dossier' => 'Validé', 'commentaire_dossier' => null])->id,
            'id_campagne' => $campagne->id,
            'statut' => 'non_assignee',
            'statut_conditionnement' => 'en_attente',
            'nombre_personnes' => 2,
            'poids_kg' => 10.0,
            'statut_contact' => 'a_contacter',
            'se_deplace' => false,
        ], $surcharge));
    }

    public function test_archiver_ajoute_le_motif_au_commentaire_de_la_famille(): void
    {
        $personne = $this->creerPersonne(['gestionnaire']);
        $livraison = $this->livraison();

        $this->actingAs($personne)
            ->postJson(route('livraison.contacts.contacter-manuel', $livraison), ['statut_contact' => 'archive', 'motif' => 'Déménagé hors zone'])
            ->assertOk();

        $famille = $livraison->famille->fresh();
        $this->assertSame('Archivé', $famille->etat_dossier);
        $this->assertSame(
            "- 17/10/2026 14:32 · {$personne->nom_complet} · manuel : Statut Validé → Archivé (campagne #{$livraison->id_campagne}) : Déménagé hors zone",
            $famille->commentaire_dossier,
        );
    }

    public function test_rejeter_ajoute_une_nouvelle_ligne_sous_les_precedentes(): void
    {
        $personne = $this->creerPersonne(['gestionnaire']);
        $livraison = $this->livraison();
        $livraison->famille->update(['commentaire_dossier' => '- ligne existante']);

        $this->actingAs($personne)
            ->postJson(route('livraison.contacts.contacter-manuel', $livraison), ['statut_contact' => 'rejetee', 'motif' => 'Hors critères'])
            ->assertOk();

        $lignes = explode("\n", (string) $livraison->famille->fresh()->commentaire_dossier);
        $this->assertCount(2, $lignes);
        $this->assertSame('- ligne existante', $lignes[0]);
        $this->assertStringStartsWith('- 17/10/2026 14:32 · ', $lignes[1]);
        $this->assertStringEndsWith('Statut Validé → Rejeté (campagne #' . $livraison->id_campagne . ') : Hors critères', $lignes[1]);
    }

    public function test_un_statut_sans_effet_dossier_najoute_rien(): void
    {
        $livraison = $this->livraison();

        $this->actingAs($this->creerPersonne(['gestionnaire']))
            ->postJson(route('livraison.contacts.contacter-manuel', $livraison), ['statut_contact' => 'injoignable'])
            ->assertOk();

        $this->assertNull($livraison->famille->fresh()->commentaire_dossier);
    }

    public function test_reinitialiser_un_contact_laisse_une_trace(): void
    {
        $personne = $this->creerPersonne(['gestionnaire']);
        $livraison = $this->livraison(['statut_contact' => 'confirme']);

        $this->actingAs($personne)
            ->postJson(route('livraison.contacts.reinitialiser', $livraison))
            ->assertOk();

        $this->assertSame(
            "- 17/10/2026 14:32 · {$personne->nom_complet} · manuel : Contact réinitialisé à « À contacter » (campagne #{$livraison->id_campagne}).",
            $livraison->famille->fresh()->commentaire_dossier,
        );
    }

    // ── Synchronisation de confirmation ──────────────────────────────────

    public function test_la_synchro_de_confirmation_ecrit_une_ligne_system_quand_adresse_ou_foyer_changent(): void
    {
        Queue::fake();
        $livraison = $this->livraison(['statut_contact' => 'confirme']);
        $famille = $livraison->famille;

        app(FamilleConfirmationSyncService::class)->synchroniser($livraison, [
            'adresse_confirmee' => '12 rue Neuve',
            'code_postal_confirme' => $famille->code_postal,
            'ville_confirmee' => $famille->ville_texte,
            'nombre_adulte_confirme' => $famille->nombre_adulte + 1,
            'nombre_enfant_confirme' => $famille->nombre_enfant,
        ]);

        $famille->refresh();
        $this->assertSame('12 rue Neuve', $famille->adresse);
        $this->assertSame(
            "- 17/10/2026 14:32 · System · sync_confirmation : Mise à jour depuis la confirmation de la campagne #{$livraison->id_campagne} : adresse et composition du foyer.",
            $famille->commentaire_dossier,
        );
        Queue::assertPushed(ResoudreAdresseFamille::class);
    }

    public function test_la_synchro_de_confirmation_sans_changement_necrit_rien(): void
    {
        Queue::fake();
        $livraison = $this->livraison(['statut_contact' => 'confirme']);
        $famille = $livraison->famille;

        app(FamilleConfirmationSyncService::class)->synchroniser($livraison, [
            'adresse_confirmee' => $famille->adresse,
            'code_postal_confirme' => $famille->code_postal,
            'ville_confirmee' => $famille->ville_texte,
            'nombre_adulte_confirme' => $famille->nombre_adulte,
            'nombre_enfant_confirme' => $famille->nombre_enfant,
        ]);

        $this->assertNull($famille->fresh()->commentaire_dossier);
    }
}
