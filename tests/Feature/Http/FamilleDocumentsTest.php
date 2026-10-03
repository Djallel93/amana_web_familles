<?php
// tests/Feature/Http/FamilleDocumentsTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Famille;
use App\Models\FamilleDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsCommunFixtures;
use Tests\TestCase;

/**
 * Documents d'une fiche famille (01/10/2026) : libellé optionnel qui devient
 * le nom du fichier, modification d'une ligne (renommer / remplacer) et
 * plafond de 5 fichiers par section.
 */
class FamilleDocumentsTest extends TestCase
{
    use SeedsCommunFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->chargerRolesFamilles();
    }

    private function fichier(string $nom = 'scan0042.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($nom, 20, str_ends_with($nom, '.pdf') ? 'application/pdf' : 'image/png');
    }

    public function test_upload_avec_label_nomme_le_fichier_avec_ce_label(): void
    {
        $personne = $this->creerPersonne(['membre']);
        $famille = Famille::factory()->create();

        $reponse = $this->actingAs($personne)->postJson(route('familles.documents.store', $famille->id), [
            'type' => 'identity',
            'fichier' => $this->fichier('scan0042.pdf'),
            'label' => 'Passeport Karim',
        ]);

        $reponse->assertCreated()->assertJsonPath('original_name', 'Passeport Karim.pdf');
        $this->assertDatabaseHas('famille_documents', ['id_famille' => $famille->id, 'original_name' => 'Passeport Karim.pdf']);
    }

    public function test_upload_sans_label_garde_le_nom_dorigine(): void
    {
        $personne = $this->creerPersonne(['membre']);
        $famille = Famille::factory()->create();

        $this->actingAs($personne)->postJson(route('familles.documents.store', $famille->id), [
            'type' => 'caf',
            'fichier' => $this->fichier('scan0042.pdf'),
        ])->assertCreated()->assertJsonPath('original_name', 'scan0042.pdf');
    }

    public function test_upload_refuse_au_dela_de_cinq_fichiers_dans_la_meme_section(): void
    {
        $personne = $this->creerPersonne(['membre']);
        $famille = Famille::factory()->create();

        for ($i = 0; $i < FamilleDocument::MAX_PAR_TYPE; $i++) {
            $this->actingAs($personne)->postJson(route('familles.documents.store', $famille->id), [
                'type' => 'resource',
                'fichier' => $this->fichier("justif-{$i}.pdf"),
            ])->assertCreated();
        }

        $this->actingAs($personne)->postJson(route('familles.documents.store', $famille->id), [
            'type' => 'resource',
            'fichier' => $this->fichier('justif-6.pdf'),
        ])->assertStatus(422);

        $this->assertSame(5, FamilleDocument::where('id_famille', $famille->id)->where('type', 'resource')->count());

        // Une autre section n'est pas affectée par le plafond de celle-ci.
        $this->actingAs($personne)->postJson(route('familles.documents.store', $famille->id), [
            'type' => 'identity',
            'fichier' => $this->fichier('passeport.pdf'),
        ])->assertCreated();
    }

    public function test_update_renomme_via_le_label_sans_toucher_au_fichier(): void
    {
        $personne = $this->creerPersonne(['membre']);
        $famille = Famille::factory()->create();
        $document = $this->actingAs($personne)->postJson(route('familles.documents.store', $famille->id), [
            'type' => 'identity',
            'fichier' => $this->fichier('scan0042.pdf'),
        ])->json();

        $reponse = $this->actingAs($personne)->postJson(route('familles.documents.update', [$famille->id, $document['id']]), [
            'label' => 'Carte nationale',
        ]);

        $reponse->assertOk()->assertJsonPath('original_name', 'Carte nationale.pdf');
        $this->assertSame($document['disk_path'], $reponse->json('disk_path'));
        Storage::disk('local')->assertExists($document['disk_path']);
    }

    public function test_update_avec_nouveau_fichier_remplace_et_supprime_lancien(): void
    {
        $personne = $this->creerPersonne(['membre']);
        $famille = Famille::factory()->create();
        $document = $this->actingAs($personne)->postJson(route('familles.documents.store', $famille->id), [
            'type' => 'identity',
            'fichier' => $this->fichier('ancien.pdf'),
        ])->json();

        $reponse = $this->actingAs($personne)->postJson(route('familles.documents.update', [$famille->id, $document['id']]), [
            'label' => 'Titre de séjour',
            'fichier' => $this->fichier('nouveau.png'),
        ]);

        $reponse->assertOk()->assertJsonPath('original_name', 'Titre de séjour.png');
        Storage::disk('local')->assertMissing($document['disk_path']);
        Storage::disk('local')->assertExists($reponse->json('disk_path'));
    }

    public function test_update_dun_document_dune_autre_famille_renvoie_404(): void
    {
        $personne = $this->creerPersonne(['membre']);
        $famille = Famille::factory()->create();
        $autre = Famille::factory()->create();
        $document = $this->actingAs($personne)->postJson(route('familles.documents.store', $famille->id), [
            'type' => 'identity',
            'fichier' => $this->fichier(),
        ])->json();

        $this->actingAs($personne)->postJson(route('familles.documents.update', [$autre->id, $document['id']]), [
            'label' => 'Piratage',
        ])->assertNotFound();
    }
}
