<?php
// app/Support/GeographiePicker.php

declare(strict_types=1);

namespace App\Support;

use Amana\Shared\Models\VehiculeType;
use Amana\Shared\Models\Ville;

/**
 * Données des sélecteurs « véhicule » et « couverture » partagés entre la
 * page de disponibilité du bénévole et la fiche personne admin
 * (resources/views/livraison/partials/vehicule-couverture.blade.php) —
 * une seule source pour que les deux écrans proposent exactement la même
 * liste.
 */
final class GeographiePicker
{
    public const LIBELLE_SANS_PERMIS = 'Sans permis';

    /**
     * Villes avec leurs secteurs, triées par nom. Colonnes sélectionnées
     * explicitement : `boundary` (MULTIPOLYGON binaire) ne doit jamais
     * être chargée ni sérialisée ici.
     *
     * @return array<int, array{id: int, nom: string, secteurs: array<int, array{id: int, nom: string}>}>
     */
    public static function villesAvecSecteurs(): array
    {
        return Ville::query()
            ->with(['secteurs' => fn ($q) => $q->select(['id', 'nom', 'id_ville'])->orderBy('nom')])
            ->orderBy('nom')
            ->get(['id', 'nom'])
            ->map(fn (Ville $ville) => [
                'id' => (int) $ville->id,
                'nom' => (string) $ville->nom,
                'secteurs' => $ville->secteurs
                    ->map(fn ($secteur) => ['id' => (int) $secteur->id, 'nom' => (string) $secteur->nom])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $ville) => $ville['secteurs'] !== [])
            ->values()
            ->all();
    }

    /**
     * Types de véhicule proposables à un bénévole TITULAIRE DU PERMIS :
     * « Sans permis » en est exclu (le choix « sans permis » se fait en
     * décochant la case permis, jamais dans la liste — sinon on obtenait
     * un bénévole « permis coché + véhicule Sans permis »).
     *
     * @return array<int, array{id: int, type: string}>
     */
    public static function vehiculesAvecPermis(): array
    {
        return VehiculeType::query()
            ->where('type', '!=', self::LIBELLE_SANS_PERMIS)
            ->orderBy('id')
            ->get(['id', 'type'])
            ->map(fn (VehiculeType $v) => ['id' => (int) $v->id, 'type' => (string) $v->type])
            ->all();
    }
}
