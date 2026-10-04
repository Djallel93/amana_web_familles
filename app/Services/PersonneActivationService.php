<?php
// app/Services/PersonneActivationService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Campagne;
use App\Models\CampagneEquipeMembre;
use App\Models\Livraison;
use App\Models\PersonneDesactivee;
use App\Models\RouteLivraison;
use Illuminate\Support\Carbon;

/**
 * Désactivation / réactivation d'une personne POUR Familles (03/10/2026) —
 * remplace l'ancien « Révoquer l'accès » (suppression des rôles) de
 * /admin/personnes. Voir App\Models\PersonneDesactivee pour le modèle de
 * données et App\Http\Middleware\EnsurePersonneActive pour la coupure
 * d'accès.
 *
 * Verrou : on ne désactive pas quelqu'un qui est encore engagé sur une
 * campagne NON TERMINÉE (statut ≠ 'terminee'), car sa désactivation
 * laisserait la campagne sans cette personne en cours de route. Engagé =
 * membre d'une équipe de la campagne, chauffeur d'une tournée pas encore
 * terminée/annulée, ou chauffeur imposé d'une livraison pas encore
 * livrée/ignorée. L'admin doit d'abord la retirer de ces campagnes (ou
 * terminer la campagne) — le message liste TOUTES les campagnes
 * concernées, pas seulement la première.
 */
class PersonneActivationService
{
    private const LABELS_TYPE = [
        'zakat_el_fitr' => 'Zakat el-Fitr',
        'collecte_alimentaire' => 'Collecte alimentaire',
        'don_ponctuel' => 'Don ponctuel',
    ];

    private const LABELS_ROLE_EQUIPE = [
        'equipe_reception' => 'Réception',
        'equipe_pesee' => 'Pesée',
        'equipe_packaging' => 'Packaging',
        'equipe_chargement' => 'Chargement',
    ];

    /**
     * Campagnes non terminées auxquelles la personne est encore rattachée,
     * avec la raison de chaque rattachement.
     *
     * @return array<int, array{id: int, label: string, raisons: string[]}> Trié par id de campagne
     */
    public function campagnesActivesImpactees(int $idPersonne): array
    {
        /** @var array<int, string[]> $raisonsParCampagne */
        $raisonsParCampagne = [];

        CampagneEquipeMembre::where('id_personne', $idPersonne)->get(['id_campagne', 'role'])
            ->each(function ($membre) use (&$raisonsParCampagne) {
                $role = self::LABELS_ROLE_EQUIPE[$membre->role] ?? $membre->role;
                $raisonsParCampagne[(int) $membre->id_campagne][] = "Équipe {$role}";
            });

        RouteLivraison::where('id_benevole', $idPersonne)
            ->whereNotIn('statut', ['terminee', 'annulee'])
            ->get(['id', 'id_campagne', 'statut'])
            ->each(function ($route) use (&$raisonsParCampagne) {
                $raisonsParCampagne[(int) $route->id_campagne][] = "Tournée #{$route->id} ({$route->statut})";
            });

        $imposees = Livraison::where('id_benevole_impose', $idPersonne)
            ->whereIn('statut', ['non_assignee', 'assignee', 'en_cours'])
            ->selectRaw('id_campagne, COUNT(*) as total')
            ->groupBy('id_campagne')
            ->get();
        foreach ($imposees as $ligne) {
            $n = (int) $ligne->total;
            $raisonsParCampagne[(int) $ligne->id_campagne][] = $n . ' livraison' . ($n > 1 ? 's' : '') . ' imposée' . ($n > 1 ? 's' : '');
        }

        if ($raisonsParCampagne === []) {
            return [];
        }

        return Campagne::whereIn('id', array_keys($raisonsParCampagne))
            ->where('statut', '!=', 'terminee')
            ->orderBy('id')
            ->get()
            ->map(fn (Campagne $c) => [
                'id' => $c->id,
                'label' => $this->libelleCampagne($c),
                'raisons' => array_values(array_unique($raisonsParCampagne[$c->id])),
            ])
            ->all();
    }

    public function libelleCampagne(Campagne $campagne): string
    {
        $type = self::LABELS_TYPE[$campagne->type] ?? $campagne->type;
        $date = $campagne->date_livraison instanceof Carbon ? $campagne->date_livraison->format('d/m/Y') : null;

        return $date ? "{$type} — {$date}" : $type;
    }

    /**
     * @return array<int, array{id: int, label: string, raisons: string[]}> Vide si la désactivation a été faite ; sinon les campagnes bloquantes (rien n'est modifié).
     */
    public function desactiver(int $idPersonne, int $idAdmin): array
    {
        $bloquantes = $this->campagnesActivesImpactees($idPersonne);
        if ($bloquantes !== []) {
            return $bloquantes;
        }

        PersonneDesactivee::firstOrCreate(
            ['id_personne' => $idPersonne],
            ['desactivee_par' => $idAdmin, 'desactivee_at' => now()],
        );

        return [];
    }

    public function reactiver(int $idPersonne): void
    {
        PersonneDesactivee::where('id_personne', $idPersonne)->delete();
    }
}
