<?php
// tests/Feature/Services/FamilleUpsertServiceTest.php

declare(strict_types=1);

namespace Tests\Feature\Services;

use Amana\Shared\Helpers\AuditHelper;
use App\Models\Famille;
use App\Models\FamilleOrganisationDemande;
use App\Models\HotelAddress;
use App\Models\Organisation;
use App\Models\OrganismeAide;
use App\Models\SecteurActivite;
use App\Services\FamilleOrganisationDemandeService;
use App\Services\FamilleUpsertService;
use Tests\TestCase;

class FamilleUpsertServiceTest extends TestCase
{
    private FamilleUpsertService $upsert;

    protected function setUp(): void
    {
        parent::setUp();

        // AuditHelper::applicationId() caches across tests in the same
        // process (see its own docblock) — every upsert()/creerOuMettreAJour()
        // call here goes through audit(), so this must be cleared per test.
        AuditHelper::clearCache();

        $this->upsert = new FamilleUpsertService(new FamilleOrganisationDemandeService());
    }

    /**
     * Minimal valid $donnees for Famille::create() — nom/prenom/telephone/
     * adresse are the only NOT NULL columns without a DB default (see
     * database/migrations/2026_07_12_000004_create_familles_domain_tables.php).
     */
    private function donnees(array $overrides = []): array
    {
        return array_merge([
            'nom' => 'Dupont',
            'prenom' => 'Fatima',
            'telephone' => '0600000000',
            'adresse' => '1 rue de la Paix',
        ], $overrides);
    }

    // ── trouverDoublon() ─────────────────────────────────────────────────

    public function test_trouver_doublon_priorise_lemail_meme_quand_telephone_et_nom_correspondent_a_un_autre_dossier(): void
    {
        $parEmail = Famille::factory()->create(['email' => 'fatima@example.fr', 'telephone' => '0611111111', 'nom' => 'Autre']);
        $parTelNom = Famille::factory()->create(['email' => null, 'telephone' => '0622222222', 'nom' => 'Dupont']);

        $trouve = $this->upsert->trouverDoublon($this->donnees([
            'email' => 'fatima@example.fr',
            'telephone' => '0622222222', // matches the OTHER record by tel+nom
            'nom' => 'Dupont',
        ]));

        $this->assertTrue($trouve->is($parEmail), 'Email match must win over a telephone+nom match on a different record');
        $this->assertFalse($trouve->is($parTelNom));
    }

    public function test_trouver_doublon_email_est_insensible_a_la_casse(): void
    {
        $famille = Famille::factory()->create(['email' => 'Fatima@Example.fr']);

        $trouve = $this->upsert->trouverDoublon($this->donnees(['email' => 'fatima@example.fr']));

        $this->assertTrue($trouve->is($famille));
    }

    public function test_trouver_doublon_par_telephone_et_nom_est_insensible_a_la_casse_du_nom(): void
    {
        $famille = Famille::factory()->create(['email' => null, 'telephone' => '0699999999', 'nom' => 'DUPONT']);

        $trouve = $this->upsert->trouverDoublon($this->donnees(['email' => null, 'telephone' => '0699999999', 'nom' => 'dupont']));

        $this->assertTrue($trouve->is($famille));
    }

    public function test_trouver_doublon_retourne_null_sans_correspondance(): void
    {
        $this->assertNull($this->upsert->trouverDoublon($this->donnees(['email' => 'personne@nulle-part.fr'])));
    }

    // ── upsert() — création ──────────────────────────────────────────────

    public function test_upsert_cree_une_nouvelle_famille_quand_aucun_doublon(): void
    {
        $resultat = $this->upsert->upsert($this->donnees(), defauts: ['etat_dossier' => 'Recu']);

        $this->assertTrue($resultat['cree']);
        $this->assertNull($resultat['avant']);
        $this->assertFalse($resultat['rattachement_en_attente']);
        $this->assertDatabaseHas('familles', ['id' => $resultat['famille']->id, 'nom' => 'Dupont']);
    }

    public function test_upsert_rattache_la_nouvelle_famille_a_lorganisation_principale_par_defaut(): void
    {
        $resultat = $this->upsert->upsert($this->donnees());

        $principale = Organisation::principale();
        $this->assertNotNull($principale, 'The migration is expected to seed exactly one est_principale org');
        $this->assertTrue($resultat['famille']->estRattacheeA($principale->id));
        $this->assertSame($principale->id, $resultat['famille']->id_organisation);
    }

    public function test_upsert_rattache_la_nouvelle_famille_a_lorganisation_fournie(): void
    {
        $organisation = Organisation::create(['code' => 'PARTENAIRE', 'nom' => 'Partenaire', 'actif' => true]);

        $resultat = $this->upsert->upsert($this->donnees(['id_organisation' => $organisation->id]));

        $this->assertTrue($resultat['famille']->estRattacheeA($organisation->id));
        $this->assertSame($organisation->id, $resultat['famille']->id_organisation);
    }

    // ── upsert() — mise à jour d'un doublon ──────────────────────────────

    public function test_upsert_met_a_jour_le_doublon_trouve_et_renvoie_letat_avant(): void
    {
        $existante = Famille::factory()->create(['email' => 'fatima@example.fr', 'nom' => 'Ancien']);

        $resultat = $this->upsert->upsert($this->donnees(['email' => 'fatima@example.fr', 'nom' => 'Nouveau']));

        $this->assertFalse($resultat['cree']);
        $this->assertSame($existante->id, $resultat['famille']->id);
        $this->assertSame('Nouveau', $resultat['famille']->fresh()->nom);
        $this->assertSame('Ancien', $resultat['avant']['nom']);
    }

    /**
     * Phase 2 brief: "etat_dossier n'est écrasé QUE si explicitement
     * fourni dans $donnees".
     */
    public function test_etat_dossier_nest_pas_ecrase_quand_absent_de_donnees(): void
    {
        $existante = Famille::factory()->create(['email' => 'fatima@example.fr', 'etat_dossier' => 'Validé']);

        $donnees = $this->donnees(['email' => 'fatima@example.fr']);
        unset($donnees['etat_dossier']); // explicitly not present

        $this->upsert->upsert($donnees);

        $this->assertSame('Validé', $existante->fresh()->etat_dossier);
    }

    public function test_etat_dossier_est_ecrase_quand_explicitement_fourni(): void
    {
        $existante = Famille::factory()->create(['email' => 'fatima@example.fr', 'etat_dossier' => 'Validé']);

        $this->upsert->upsert($this->donnees(['email' => 'fatima@example.fr', 'etat_dossier' => 'Rejeté']));

        $this->assertSame('Rejeté', $existante->fresh()->etat_dossier);
    }

    // ── upsert() — branche rattachement (28/08/2026) ────────────────────

    public function test_organisation_deja_rattachee_declenche_la_fusion_normale_pas_une_demande(): void
    {
        $organisation = Organisation::create(['code' => 'PARTENAIRE', 'nom' => 'Partenaire', 'actif' => true]);
        $existante = Famille::factory()->create(['email' => 'fatima@example.fr', 'nom' => 'Ancien']);
        $existante->organisations()->attach($organisation->id, ['rattachee_le' => now()]);

        $resultat = $this->upsert->upsert($this->donnees([
            'email' => 'fatima@example.fr',
            'nom' => 'Nouveau',
            'id_organisation' => $organisation->id,
        ]));

        $this->assertFalse($resultat['rattachement_en_attente']);
        $this->assertSame('Nouveau', $resultat['famille']->fresh()->nom);
        $this->assertSame(0, FamilleOrganisationDemande::count());
    }

    public function test_organisation_non_rattachee_cree_une_demande_et_ne_touche_pas_au_dossier(): void
    {
        $organisation = Organisation::create(['code' => 'AUTRE', 'nom' => 'Autre organisation', 'actif' => true]);
        $existante = Famille::factory()->create(['email' => 'fatima@example.fr', 'nom' => 'Inchange']);

        $resultat = $this->upsert->upsert($this->donnees([
            'email' => 'fatima@example.fr',
            'nom' => 'Ne devrait pas etre applique',
            'id_organisation' => $organisation->id,
        ]));

        $this->assertTrue($resultat['rattachement_en_attente']);
        $this->assertFalse($resultat['cree']);
        $this->assertNull($resultat['avant']);
        $this->assertSame($existante->id, $resultat['famille']->id);
        // The dossier itself must be completely untouched.
        $this->assertSame('Inchange', $existante->fresh()->nom);
        $this->assertFalse($existante->fresh()->estRattacheeA($organisation->id));

        $this->assertDatabaseHas('famille_organisation_demandes', [
            'id_famille' => $existante->id,
            'id_organisation' => $organisation->id,
            'statut' => 'en_attente',
        ]);
    }

    // ── forcerEstHotelSiAdresseConnue() (30/08/2026) ────────────────────

    public function test_est_hotel_force_a_true_quand_ladresse_correspond_au_referentiel(): void
    {
        HotelAddress::create(['adresse' => "Hôtel Kyriad, 12 Rue de l'Océan, 44300 Nantes"]);

        $resultat = $this->upsert->upsert($this->donnees([
            'email' => 'nouvelle@example.fr',
            'adresse' => "12 Rue de l'Ocean",
            'code_postal' => '44300',
            'ville_texte' => 'Nantes',
            'est_hotel' => false, // even an explicit false from the caller...
        ]));

        // ...is overridden to true once the address matches.
        $this->assertTrue($resultat['famille']->fresh()->est_hotel);
    }

    public function test_est_hotel_reste_false_sans_correspondance_au_referentiel(): void
    {
        HotelAddress::create(['adresse' => "Hôtel Kyriad, 12 Rue de l'Océan, 44300 Nantes"]);

        $resultat = $this->upsert->upsert($this->donnees([
            'email' => 'nouvelle2@example.fr',
            'adresse' => '5 Impasse des Tilleuls',
            'code_postal' => '44100',
            'ville_texte' => 'Nantes',
        ]));

        $this->assertFalse($resultat['famille']->fresh()->est_hotel);
    }

    public function test_est_hotel_nest_pas_reevalue_quand_donnees_ne_contient_pas_adresse(): void
    {
        $existante = Famille::factory()->create(['email' => 'fatima@example.fr', 'est_hotel' => false, 'adresse' => 'Non concerne']);
        HotelAddress::create(['adresse' => 'Non concerne']); // would match if re-evaluated

        $donnees = $this->donnees(['email' => 'fatima@example.fr']);
        unset($donnees['adresse']); // partial update that doesn't touch the address

        $this->upsert->upsert($donnees);

        $this->assertFalse($existante->fresh()->est_hotel);
    }

    // ── syncListes() — null = ne pas toucher, [] = vider explicitement ──

    public function test_secteurs_et_organismes_sont_synchronises_quand_fournis(): void
    {
        $secteur = SecteurActivite::create([
            'code' => 'restauration',
            // libelle_ar/libelle_en sont NOT NULL sans défaut (formulaire public trilingue).
            'libelle_fr' => 'Restauration', 'libelle_ar' => 'مطاعم', 'libelle_en' => 'Catering',
            'actif' => true, 'ordre' => 1,
        ]);
        $organisme = OrganismeAide::create([
            'code' => 'resto_coeur',
            'libelle_fr' => 'Restos du Coeur', 'libelle_ar' => 'مطاعم القلب', 'libelle_en' => 'Restos du Coeur',
            'actif' => true, 'ordre' => 1,
        ]);

        $resultat = $this->upsert->upsert(
            $this->donnees(['email' => 'nouvelle3@example.fr']),
            secteursActivite: [$secteur->id],
            organismesAide: [$organisme->id],
        );

        $this->assertTrue($resultat['famille']->secteursActivite()->where('secteurs_activite.id', $secteur->id)->exists());
        $this->assertTrue($resultat['famille']->organismesAide()->where('organismes_aide.id', $organisme->id)->exists());
    }

    public function test_secteurs_ne_sont_pas_synchronises_quand_rattachement_en_attente(): void
    {
        // Famille::create() directly (not the factory) — FamilleFactory's
        // afterCreating() hook randomly attaches existing secteurs/organismes,
        // which would make a "not attached" assertion flaky once a
        // SecteurActivite row exists in this test.
        $organisation = Organisation::create(['code' => 'AUTRE', 'nom' => 'Autre', 'actif' => true]);
        $existante = Famille::create($this->donnees(['email' => 'fatima@example.fr']));
        $secteur = SecteurActivite::create([
            'code' => 'restauration',
            // libelle_ar/libelle_en sont NOT NULL sans défaut (formulaire public trilingue).
            'libelle_fr' => 'Restauration', 'libelle_ar' => 'مطاعم', 'libelle_en' => 'Catering',
            'actif' => true, 'ordre' => 1,
        ]);

        $this->upsert->upsert(
            $this->donnees(['email' => 'fatima@example.fr', 'id_organisation' => $organisation->id]),
            secteursActivite: [$secteur->id],
        );

        $this->assertFalse($existante->fresh()->secteursActivite()->where('secteurs_activite.id', $secteur->id)->exists());
    }
}
