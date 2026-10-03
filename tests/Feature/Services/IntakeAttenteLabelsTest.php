<?php
// tests/Feature/Services/IntakeAttenteLabelsTest.php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\IntakeAttenteService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Formulaire public (01/10/2026) : chaque justificatif peut porter un
 * libellé optionnel (tableau PARALLÈLE au tableau de fichiers, même index)
 * qui devient son nom d'origine enregistré dans documents_meta.
 */
class IntakeAttenteLabelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_les_libelles_nomment_les_fichiers_selon_leur_index(): void
    {
        $resultat = app(IntakeAttenteService::class)->creerDemande(
            ['nom' => 'Alaoui', 'email' => 'labels@example.fr'],
            [],
            [],
            'fr',
            [
                'identite' => [
                    UploadedFile::fake()->create('scan001.pdf', 10, 'application/pdf'),
                    UploadedFile::fake()->create('scan002.png', 10, 'image/png'),
                    UploadedFile::fake()->create('scan003.pdf', 10, 'application/pdf'),
                ],
                'aide' => [
                    UploadedFile::fake()->create('caf.pdf', 10, 'application/pdf'),
                ],
            ],
            [
                // Index 0 libellé, index 1 vide (nom d'origine conservé),
                // index 2 libellé — l'alignement par index est le contrat.
                'identite' => ['Passeport Karim', '', 'Carte nationale'],
                'aide' => [null],
            ],
        );

        $meta = $resultat['demande']->fresh()->documents_meta;

        $this->assertSame('Passeport Karim.pdf', $meta['identite'][0]['original_name']);
        $this->assertSame('scan002.png', $meta['identite'][1]['original_name']);
        $this->assertSame('Carte nationale.pdf', $meta['identite'][2]['original_name']);
        $this->assertSame('caf.pdf', $meta['aide'][0]['original_name']);
    }

    public function test_sans_libelles_les_noms_dorigine_sont_conserves(): void
    {
        $resultat = app(IntakeAttenteService::class)->creerDemande(
            ['nom' => 'Benali', 'email' => 'sans-labels@example.fr'],
            [],
            [],
            'fr',
            ['identite' => [UploadedFile::fake()->create('scan001.pdf', 10, 'application/pdf')]],
        );

        $this->assertSame(
            'scan001.pdf',
            $resultat['demande']->fresh()->documents_meta['identite'][0]['original_name'],
        );
    }
}
