<?php
// app/Support/Creneau.php

declare(strict_types=1);

namespace App\Support;

/**
 * Créneaux horaires fixes du domaine livraison — 8h→19h par blocs de 2h,
 * dernier bloc de 1h (19h ne divise pas exactement par tranches de 2h
 * depuis 8h, confirmé acceptable, voir prompt du 30/08/2026 §2).
 *
 * Liste FIXE, non paramétrable par campagne (contrairement à
 * secteurs_activite/organismes_aide par exemple, qui sont des listes
 * fermées éditables par l'admin) — source unique en PHP, utilisée à la
 * fois pour la validation (livraison_creneaux.creneau /
 * benevole_disponibilite_creneaux.creneau, colonnes string plutôt qu'enum
 * MySQL, voir ces migrations) et pour peupler les <select> des vues.
 *
 * Le clustering (Patch 3) doit traiter ces créneaux dans l'ordre
 * chronologique de TOUS — voir §3.3 du prompt : "Run créneaux in
 * chronological order".
 */
final class Creneau
{
    public const MATIN_1 = '08-10';
    public const MATIN_2 = '10-12';
    public const MIDI = '12-14';
    public const APRES_MIDI_1 = '14-16';
    public const APRES_MIDI_2 = '16-18';
    public const SOIR = '18-19';

    /**
     * Ordre chronologique — c'est aussi l'ordre dans lequel le clustering
     * (Patch 3) doit traiter les créneaux, un par un.
     */
    public const TOUS = [
        self::MATIN_1,
        self::MATIN_2,
        self::MIDI,
        self::APRES_MIDI_1,
        self::APRES_MIDI_2,
        self::SOIR,
    ];

    public const LIBELLES = [
        self::MATIN_1 => '8h - 10h',
        self::MATIN_2 => '10h - 12h',
        self::MIDI => '12h - 14h',
        self::APRES_MIDI_1 => '14h - 16h',
        self::APRES_MIDI_2 => '16h - 18h',
        self::SOIR => '18h - 19h',
    ];

    public static function estValide(string $creneau): bool
    {
        return in_array($creneau, self::TOUS, true);
    }

    public static function libelle(string $creneau): string
    {
        return self::LIBELLES[$creneau] ?? $creneau;
    }
}
