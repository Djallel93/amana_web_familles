<?php
// app/Services/FamilleUpsertService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Famille;

/**
 * Logique de dédup + création/mise à jour partagée entre le formulaire
 * public d'intake (IntakeController) et l'import en masse
 * (FamilleImportService) — évite de dupliquer la même règle de
 * rapprochement à deux endroits (décision 6.9 : "même pipeline de
 * traitement", même esprit appliqué ici au niveau service).
 *
 * Reprend la logique de dédup de findDuplicateFamily() (amana_familles) :
 * priorité 1 email, priorité 2 téléphone + nom (insensible à la casse).
 */
class FamilleUpsertService
{
    public function trouverDoublon(array $donnees): ?Famille
    {
        if (!empty($donnees['email'])) {
            $famille = Famille::whereRaw('LOWER(email) = ?', [strtolower($donnees['email'])])->first();
            if ($famille) {
                return $famille;
            }
        }

        if (!empty($donnees['telephone']) && !empty($donnees['nom'])) {
            return Famille::where('telephone', $donnees['telephone'])
                ->whereRaw('LOWER(nom) = ?', [strtolower($donnees['nom'])])
                ->first();
        }

        return null;
    }

    /**
     * Crée ou met à jour une famille à partir d'un tableau de champs déjà
     * validés. En cas de doublon, etat_dossier n'est écrasé QUE si
     * explicitement fourni dans $donnees (préserve le travail de triage du
     * staff sinon) — sur une création, $defauts s'applique.
     *
     * @return array{famille: Famille, cree: bool}
     */
    public function upsert(array $donnees, array $defauts = []): array
    {
        $existante = $this->trouverDoublon($donnees);

        if ($existante) {
            $avant = $existante->toArray();
            $existante->fill($donnees);
            $existante->save();

            audit('update', 'familles', $existante->id, $avant, $existante->toArray());

            return ['famille' => $existante, 'cree' => false];
        }

        $famille = Famille::create(array_merge($defauts, $donnees));

        audit('create', 'familles', $famille->id, null, $famille->toArray());

        return ['famille' => $famille, 'cree' => true];
    }
}
