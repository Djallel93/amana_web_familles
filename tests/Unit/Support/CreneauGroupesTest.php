<?php
// tests/Unit/Support/CreneauGroupesTest.php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Creneau;
use PHPUnit\Framework\TestCase;

/**
 * Groupes « Matin » / « Après-midi » des cases de créneaux (01/10/2026) —
 * miroir de CRENEAUX_MATIN / CRENEAUX_APRES_MIDI dans
 * resources/js/components/livraison/shared/types.ts.
 */
class CreneauGroupesTest extends TestCase
{
    public function test_les_deux_groupes_couvrent_tous_les_creneaux_sans_chevauchement(): void
    {
        $this->assertSame([], array_intersect(Creneau::MATIN, Creneau::APRES_MIDI));
        $this->assertEqualsCanonicalizing(Creneau::TOUS, [...Creneau::MATIN, ...Creneau::APRES_MIDI]);
    }

    public function test_le_creneau_de_midi_est_range_cote_matin(): void
    {
        $this->assertContains('12-14', Creneau::MATIN);
        $this->assertSame(['14-16', '16-18', '18-19'], Creneau::APRES_MIDI);
    }
}
