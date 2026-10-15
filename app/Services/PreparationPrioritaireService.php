<?php
// app/Services/PreparationPrioritaireService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Livraison;
use App\Models\RouteLivraison;
use Illuminate\Support\Facades\DB;

/**
 * « Préparation prioritaire » (11/10/2026) : l'équipe chargement (tournée entière) ou le
 * retrait QG (une famille) demande à Packaging de préparer un colis en premier. Le drapeau
 * remonte la famille en tête de Packaging (voir App\Support\OrdrePackaging), reste posé
 * (personne ne peut le retirer) et devient sans objet une fois la famille « prête ».
 *
 * Lève une \RuntimeException au message prêt à afficher quand la demande n'a pas de sens.
 */
class PreparationPrioritaireService
{
    /** Statuts de tournée où il reste des colis à préparer (avant chargement). */
    private const STATUTS_TOURNEE = ['planifiee', 'chargement', 'packaging_annule'];

    /**
     * Marque prioritaires toutes les familles de la tournée qui ne sont ni prêtes ni déjà
     * prioritaires.
     *
     * @return int nombre de familles passées prioritaires
     */
    public function prioriserTournee(RouteLivraison $route): int
    {
        if (!in_array($route->statut, self::STATUTS_TOURNEE, true)) {
            throw new \RuntimeException('Cette tournée est déjà chargée ou en cours : plus rien à préparer en priorité.');
        }

        $livraisons = Livraison::whereIn(
            'id',
            $route->etapes()->whereNotIn('statut', ['livree', 'ignoree'])->pluck('id_livraison'),
        )
            ->where('statut_conditionnement', '!=', 'prete')
            ->where('preparation_prioritaire', false)
            ->get();

        if ($livraisons->isEmpty()) {
            throw new \RuntimeException('Toutes les familles de cette tournée sont déjà prêtes ou en préparation prioritaire.');
        }

        $maintenant = now();
        DB::transaction(function () use ($livraisons, $maintenant) {
            // Même horodatage pour toute la tournée : le FIFO ne départage pas ses familles.
            $livraisons->each(fn(Livraison $l) => $l->update([
                'preparation_prioritaire' => true,
                'prioritaire_at' => $maintenant,
            ]));
        });

        return $livraisons->count();
    }

    /** Marque une famille (retrait QG) prioritaire. */
    public function prioriserLivraison(Livraison $livraison): void
    {
        if ($livraison->statut_conditionnement === 'prete') {
            throw new \RuntimeException('Le colis de cette famille est déjà prêt.');
        }

        if ($livraison->statut === 'livree' || $livraison->statut_retrait_hq === 'delivre') {
            throw new \RuntimeException('Cette famille a déjà été servie.');
        }

        if ($livraison->preparation_prioritaire) {
            throw new \RuntimeException('La préparation de cette famille est déjà prioritaire.');
        }

        $livraison->update([
            'preparation_prioritaire' => true,
            'prioritaire_at' => now(),
        ]);
    }
}
