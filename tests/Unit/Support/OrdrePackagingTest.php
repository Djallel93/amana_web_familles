<?php
// tests/Unit/Support/OrdrePackagingTest.php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Famille;
use App\Models\Livraison;
use App\Models\LivraisonCreneau;
use App\Support\Creneau;
use App\Support\OrdrePackaging;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Ordre de la file Packaging (11/10/2026) : préparation prioritaire, poids du créneau
 * (moins de créneaux d'abord, puis le plus tôt), criticité décroissante, FIFO.
 */
class OrdrePackagingTest extends TestCase
{
    /**
     * @param list<string> $creneaux
     */
    private function livraison(int $id, array $creneaux, int $criticite = 0, bool $prioritaire = false, ?string $confirmeLe = '2026-10-01 10:00:00'): Livraison
    {
        $livraison = new Livraison();
        $livraison->forceFill([
            'id' => $id,
            'preparation_prioritaire' => $prioritaire,
            'confirme_at' => $confirmeLe === null ? null : Carbon::parse($confirmeLe),
        ]);
        $livraison->setRelation('creneaux', new Collection(array_map(
            fn(string $c) => (new LivraisonCreneau())->forceFill(['creneau' => $c]),
            $creneaux,
        )));
        $livraison->setRelation('famille', (new Famille())->forceFill(['criticite' => $criticite]));

        return $livraison;
    }

    /** @param list<Livraison> $livraisons @return list<int> */
    private function ordre(array $livraisons): array
    {
        return collect($livraisons)
            ->sort(fn(Livraison $a, Livraison $b) => OrdrePackaging::cle($a) <=> OrdrePackaging::cle($b))
            ->pluck('id')
            ->values()
            ->all();
    }

    public function test_la_preparation_prioritaire_passe_avant_tout_le_reste(): void
    {
        $urgente = $this->livraison(1, [Creneau::MATIN_1], criticite: 9);
        $prioritaire = $this->livraison(2, [Creneau::SOIR, Creneau::MIDI, Creneau::MATIN_2], criticite: 0, prioritaire: true, confirmeLe: '2026-10-05 10:00:00');

        $this->assertSame([2, 1], $this->ordre([$urgente, $prioritaire]));
    }

    public function test_moins_de_creneaux_confirmes_passe_avant(): void
    {
        $deux = $this->livraison(1, [Creneau::MATIN_1, Creneau::MATIN_2], criticite: 9);
        $un = $this->livraison(2, [Creneau::SOIR], criticite: 0);
        $trois = $this->livraison(3, [Creneau::MATIN_1, Creneau::MIDI, Creneau::SOIR], criticite: 9);

        $this->assertSame([2, 1, 3], $this->ordre([$trois, $deux, $un]));
    }

    public function test_a_nombre_de_creneaux_egal_le_creneau_le_plus_tot_passe_avant(): void
    {
        $apresMidi = $this->livraison(1, [Creneau::APRES_MIDI_1], criticite: 9);
        $matin = $this->livraison(2, [Creneau::MATIN_2], criticite: 0);

        $this->assertSame([2, 1], $this->ordre([$apresMidi, $matin]));
    }

    public function test_a_poids_egal_la_criticite_la_plus_haute_passe_avant(): void
    {
        $faible = $this->livraison(1, [Creneau::MIDI], criticite: 1);
        $forte = $this->livraison(2, [Creneau::MIDI], criticite: 5);

        $this->assertSame([2, 1], $this->ordre([$faible, $forte]));
    }

    public function test_a_criticite_egale_la_premiere_confirmee_passe_avant(): void
    {
        $tardive = $this->livraison(1, [Creneau::MIDI], criticite: 3, confirmeLe: '2026-10-03 09:00:00');
        $precoce = $this->livraison(2, [Creneau::MIDI], criticite: 3, confirmeLe: '2026-10-02 18:00:00');

        $this->assertSame([2, 1], $this->ordre([$tardive, $precoce]));
    }

    public function test_sans_horodatage_ni_creneau_la_famille_passe_en_dernier_puis_par_id(): void
    {
        $sansRien = $this->livraison(1, [], criticite: 9, confirmeLe: null);
        $normale = $this->livraison(2, [Creneau::SOIR], criticite: 0);
        $sansDateB = $this->livraison(4, [Creneau::SOIR], criticite: 0, confirmeLe: null);
        $sansDateA = $this->livraison(3, [Creneau::SOIR], criticite: 0, confirmeLe: null);

        $this->assertSame([2, 3, 4, 1], $this->ordre([$sansRien, $sansDateB, $normale, $sansDateA]));
    }

    public function test_plusieurs_prioritaires_suivent_ensuite_le_meme_ordre(): void
    {
        $a = $this->livraison(1, [Creneau::MATIN_1, Creneau::SOIR], prioritaire: true);
        $b = $this->livraison(2, [Creneau::SOIR], prioritaire: true);

        $this->assertSame([2, 1], $this->ordre([$a, $b]));
    }
}
