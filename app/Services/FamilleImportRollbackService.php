<?php
// app/Services/FamilleImportRollbackService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Famille;
use App\Models\FamilleImport;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Annule un import : supprime les familles CRÉÉES par cet import, et
 * restaure les familles qu'il a seulement MISES À JOUR (doublon détecté) à
 * leurs valeurs précédentes — à partir du snapshot `donnees_avant` capturé
 * au moment de l'import (voir FamilleUpsertService::upsert()), plutôt que
 * de rejouer audit_logs (plus simple, pas de risque de retrouver la
 * mauvaise entrée en cas d'imports/éditions concurrents sur la même
 * famille).
 *
 * Ignore les lignes en erreur/ignorées (rien n'a été écrit pour elles) et
 * les familles déjà supprimées entre-temps (ex : par une action manuelle).
 * Idempotent au niveau import : refuse d'annuler un import déjà annulé
 * (famille_imports.rolled_back_at) — voir ImportsController::rollback().
 */
class FamilleImportRollbackService
{
    /**
     * @return int Nombre de familles restaurées/supprimées.
     */
    public function annuler(FamilleImport $import): int
    {
        if ($import->rolled_back_at !== null) {
            throw new RuntimeException('Cet import a déjà été annulé.');
        }

        $nombreAnnulees = 0;

        DB::transaction(function () use ($import, &$nombreAnnulees) {
            $lignes = $import->rows()
                ->where('status', 'success')
                ->whereNotNull('id_famille')
                ->get();

            foreach ($lignes as $ligne) {
                $famille = Famille::find($ligne->id_famille);

                if (!$famille) {
                    continue; // Déjà supprimée entre-temps — rien à annuler.
                }

                if ($ligne->cree) {
                    $avant = $famille->toArray();
                    $famille->delete();
                    audit('delete', 'familles', $ligne->id_famille, $avant, null);
                } elseif ($ligne->donnees_avant) {
                    $avant = $famille->toArray();
                    $famille->fill($ligne->donnees_avant);
                    $famille->save();
                    audit('update', 'familles', $famille->id, $avant, $famille->toArray());
                }

                $nombreAnnulees++;
            }

            $import->rolled_back_at = now();
            $import->save();

            audit('delete', 'imports', $import->id, null, ['nombre_lignes_annulees' => $nombreAnnulees]);
        });

        return $nombreAnnulees;
    }
}
