<?php
// tests/Unit/Support/CreneauRestantsTest.php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Creneau;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Créneaux proposés par le mode automatique de l'assistant « Génération des
 * routes » (09/10/2026) : le créneau en cours et ceux à venir seulement.
 */
class CreneauRestantsTest extends TestCase
{
    private function a(string $horodatage): DateTimeImmutable
    {
        return new DateTimeImmutable($horodatage);
    }

    public function test_une_journee_future_garde_tous_les_creneaux(): void
    {
        $this->assertSame(Creneau::TOUS, Creneau::restantsPour('2026-11-10', $this->a('2026-10-09 15:00')));
    }

    public function test_une_journee_passee_na_plus_aucun_creneau(): void
    {
        $this->assertSame([], Creneau::restantsPour('2026-10-08', $this->a('2026-10-09 07:00')));
    }

    public function test_le_jour_meme_garde_le_creneau_en_cours_et_les_suivants(): void
    {
        $this->assertSame(
            [Creneau::MATIN_2, Creneau::MIDI, Creneau::APRES_MIDI_1, Creneau::APRES_MIDI_2, Creneau::SOIR],
            Creneau::restantsPour('2026-10-09', $this->a('2026-10-09 10:30')),
        );
        $this->assertSame(
            [Creneau::APRES_MIDI_2, Creneau::SOIR],
            Creneau::restantsPour('2026-10-09', $this->a('2026-10-09 16:00')),
        );
    }

    public function test_un_creneau_qui_commence_pile_est_encore_le_creneau_en_cours(): void
    {
        $this->assertSame(
            [Creneau::MATIN_2, Creneau::MIDI, Creneau::APRES_MIDI_1, Creneau::APRES_MIDI_2, Creneau::SOIR],
            Creneau::restantsPour('2026-10-09', $this->a('2026-10-09 10:00')),
        );
    }

    public function test_avant_8h_tous_les_creneaux_du_jour_restent_proposes(): void
    {
        $this->assertSame(Creneau::TOUS, Creneau::restantsPour('2026-10-09', $this->a('2026-10-09 06:45')));
    }

    public function test_a_partir_de_19h_il_ne_reste_rien_le_jour_meme(): void
    {
        $this->assertSame([], Creneau::restantsPour('2026-10-09', $this->a('2026-10-09 19:00')));
        $this->assertSame([Creneau::SOIR], Creneau::restantsPour('2026-10-09', $this->a('2026-10-09 18:59')));
    }

    public function test_la_date_peut_etre_un_objet_date_ou_un_horodatage_iso(): void
    {
        $maintenant = $this->a('2026-10-09 12:00');

        $this->assertSame(
            Creneau::restantsPour('2026-10-09', $maintenant),
            Creneau::restantsPour('2026-10-09T00:00:00.000000Z', $maintenant),
        );
        $this->assertSame(
            Creneau::restantsPour('2026-10-09', $maintenant),
            Creneau::restantsPour(new DateTimeImmutable('2026-10-09'), $maintenant),
        );
    }
}
