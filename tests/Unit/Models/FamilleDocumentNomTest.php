<?php
// tests/Unit/Models/FamilleDocumentNomTest.php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\FamilleDocument;
use PHPUnit\Framework\TestCase;

/**
 * Nom d'un document à partir de son libellé optionnel (01/10/2026) — pur
 * calcul de chaîne, aucune base de données : PHPUnit\Framework\TestCase
 * suffit (pas Tests\TestCase).
 */
class FamilleDocumentNomTest extends TestCase
{
    public function test_sans_label_le_nom_dorigine_est_conserve(): void
    {
        $this->assertSame('scan0042.PDF', FamilleDocument::nomAvecLabel(null, 'scan0042.PDF'));
        $this->assertSame('scan0042.PDF', FamilleDocument::nomAvecLabel('', 'scan0042.PDF'));
        $this->assertSame('scan0042.PDF', FamilleDocument::nomAvecLabel('   ', 'scan0042.PDF'));
    }

    public function test_le_label_remplace_le_nom_et_garde_lextension_en_minuscules(): void
    {
        $this->assertSame('Passeport Karim.pdf', FamilleDocument::nomAvecLabel('Passeport Karim', 'scan0042.PDF'));
    }

    public function test_extension_deja_presente_dans_le_label_nest_pas_doublee(): void
    {
        $this->assertSame('Passeport.pdf', FamilleDocument::nomAvecLabel('Passeport.pdf', 'scan.pdf'));
        $this->assertSame('Passeport.pdf', FamilleDocument::nomAvecLabel('Passeport.PDF', 'scan.pdf'));
    }

    public function test_caracteres_interdits_dans_un_nom_de_fichier_sont_retires(): void
    {
        $nom = FamilleDocument::nomAvecLabel('../../etc/passwd: "a|b"', 'scan.png');

        foreach (['/', '\\', ':', '"', '|', '*', '?', '<', '>'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $nom);
        }
        $this->assertStringEndsWith('.png', $nom);
    }

    public function test_label_compose_uniquement_de_caracteres_interdits_retombe_sur_le_nom_dorigine(): void
    {
        $this->assertSame('scan.png', FamilleDocument::nomAvecLabel('///', 'scan.png'));
    }

    public function test_label_trop_long_est_tronque_a_100_caracteres(): void
    {
        $nom = FamilleDocument::nomAvecLabel(str_repeat('a', 300), 'scan.png');

        $this->assertSame(100 + strlen('.png'), mb_strlen($nom));
    }

    public function test_label_sans_extension_dorigine_na_pas_de_point_final(): void
    {
        $this->assertSame('Justificatif', FamilleDocument::nomAvecLabel('Justificatif', 'fichier-sans-extension'));
    }

    public function test_plafond_par_section_est_de_cinq(): void
    {
        $this->assertSame(5, FamilleDocument::MAX_PAR_TYPE);
    }
}
