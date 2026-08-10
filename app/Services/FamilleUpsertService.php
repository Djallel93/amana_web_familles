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
     * `avant` (snapshot pré-mise à jour, null sur une création) est renvoyé
     * pour permettre à l'appelant de stocker un point de restauration —
     * voir FamilleImportRow::donnees_avant / FamilleImportRollbackService.
     *
     * `secteursActivite` / `organismesAide` (tableaux d'IDs, optionnels) sont
     * synchronisés séparément des colonnes de familles — ce sont des
     * relations belongsToMany, pas des colonnes fillable de Famille.
     *
     * @param int[]|null $secteursActivite
     * @param int[]|null $organismesAide
     * @return array{famille: Famille, cree: bool, avant: ?array}
     */
    public function upsert(
        array $donnees,
        array $defauts = [],
        ?array $secteursActivite = null,
        ?array $organismesAide = null,
    ): array {
        $existante = $this->trouverDoublon($donnees);

        if ($existante) {
            $avant = $existante->toArray();
            $existante->fill($donnees);
            $existante->save();
            $this->syncListes($existante, $secteursActivite, $organismesAide);

            audit('update', 'familles', $existante->id, $avant, $existante->toArray());

            return ['famille' => $existante, 'cree' => false, 'avant' => $avant];
        }

        $famille = Famille::create(array_merge($defauts, $donnees));
        $this->syncListes($famille, $secteursActivite, $organismesAide);

        audit('create', 'familles', $famille->id, null, $famille->toArray());

        return ['famille' => $famille, 'cree' => true, 'avant' => null];
    }

    /**
     * @param int[]|null $secteursActivite
     * @param int[]|null $organismesAide
     */
    private function syncListes(Famille $famille, ?array $secteursActivite, ?array $organismesAide): void
    {
        // null = "non fourni, ne pas toucher" (ex : mise à jour partielle
        // depuis l'import) ; [] = "vider explicitement" — distinction
        // volontaire, contrairement à un simple `$secteursActivite ?: []`.
        if ($secteursActivite !== null) {
            $famille->secteursActivite()->sync($secteursActivite);
        }

        if ($organismesAide !== null) {
            $famille->organismesAide()->sync($organismesAide);
        }
    }
}
