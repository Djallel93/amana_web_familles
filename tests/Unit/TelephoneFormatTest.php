<?php
// tests/Unit/TelephoneFormatTest.php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Famille;
use Tests\TestCase;

/**
 * 17/10/2026 — l'affichage « +33 1 23 45 67 89 » est défini une seule fois (Amana\Shared\Support\PhoneFr,
 * amana_shared >= 3.2) ; Famille::formaterTelephoneFr() y délègue.
 */
class TelephoneFormatTest extends TestCase
{
    public function test_toutes_les_saisies_francaises_sont_normalisees(): void
    {
        foreach (['01 23 45 67 89', '0123456789', '+33 1 23 45 67 89', '+33123456789', '0033 1 23 45 67 89', '01.23.45.67.89'] as $saisie) {
            $this->assertSame('+33 1 23 45 67 89', Famille::formaterTelephoneFr($saisie), $saisie);
        }
    }

    public function test_un_numero_non_francais_ou_incomplet_est_affiche_tel_quel(): void
    {
        $this->assertSame('+44 20 7946 0958', Famille::formaterTelephoneFr('+44 20 7946 0958'));
        $this->assertSame('06 12', Famille::formaterTelephoneFr('06 12'));
        $this->assertNull(Famille::formaterTelephoneFr(null));
        $this->assertNull(Famille::formaterTelephoneFr(''));
    }

    public function test_les_accesseurs_de_famille_utilisent_le_meme_format(): void
    {
        $famille = new Famille(['telephone' => '0612345678', 'telephone_bis' => null]);

        $this->assertSame('+33 6 12 34 56 78', $famille->telephone_formate);
        $this->assertNull($famille->telephone_bis_formate);
    }
}
