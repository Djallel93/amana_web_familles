<?php
// app/Support/StatutChargement.php

declare(strict_types=1);

namespace App\Support;

use App\Models\Livraison;
use App\Models\RouteLivraison;

/**
 * Statut AFFICHÉ sur l'écran Chargement (06/10/2026) — dérivé du
 * conditionnement des familles, jamais stocké (aucune colonne) :
 *
 *   - restante        : aucun colis prêt ;
 *   - en_preparation  : au moins un colis prêt, pas tous ;
 *   - prete           : tous les colis prêts (la tournée est « prête à
 *                       charger », statut routes = 'chargement') ;
 *   - chargee         : l'équipe chargement a confirmé le chargement ;
 *   - packaging_annule: un conditionnement a été annulé après coup (la
 *                       tournée repart en préparation — compté avec les
 *                       restantes dans les stats) ;
 *   - en_livraison    : le chauffeur est parti (route 'en_cours'), tous les arrêts
 *                       ne sont pas encore réglés (17/10/2026) ;
 *   - livree          : tournée terminée (route 'livraisons_terminees' ou 'terminee')
 *                       OU tous ses arrêts réglés — livrés ou ignorés (17/10/2026).
 *                       Avant, ces tournées disparaissaient de l'écran.
 *
 * Pour une famille, le conditionnement se lit directement sur
 * livraisons.statut_conditionnement (en_attente / en_cours / prete, voir
 * PackagingController::marquerColisPret()).
 */
final class StatutChargement
{
    public const RESTANTE = 'restante';

    public const EN_PREPARATION = 'en_preparation';

    public const PRETE = 'prete';

    public const CHARGEE = 'chargee';

    public const PACKAGING_ANNULE = 'packaging_annule';

    public const EN_LIVRAISON = 'en_livraison';

    public const LIVREE = 'livree';

    public const LIBELLES = [
        self::RESTANTE => 'Restante',
        self::EN_PREPARATION => 'En préparation',
        self::PRETE => 'Prête',
        self::CHARGEE => 'Chargée',
        self::PACKAGING_ANNULE => 'Packaging annulé',
        self::EN_LIVRAISON => 'En livraison',
        self::LIVREE => 'Livrée',
    ];

    /** Classes Tailwind de la pastille — alignées sur les couleurs de Packaging/Suivi. */
    public const STYLES = [
        self::RESTANTE => 'bg-stone-100 text-ink-muted',
        self::EN_PREPARATION => 'bg-amber-100 text-amber-700',
        self::PRETE => 'bg-emerald-100 text-emerald-700',
        self::CHARGEE => 'bg-indigo-100 text-indigo-700',
        self::PACKAGING_ANNULE => 'bg-orange-100 text-orange-700',
        self::EN_LIVRAISON => 'bg-sky-100 text-sky-700',
        self::LIVREE => 'bg-teal-100 text-teal-700',
    ];

    /** Filtres de l'écran (valeur de ?filtre_chargement) → statut affiché. */
    public const FILTRES = [
        'restantes' => self::RESTANTE,
        'en_preparation' => self::EN_PREPARATION,
        'pretes' => self::PRETE,
        'chargees' => self::CHARGEE,
        'en_livraison' => self::EN_LIVRAISON,
        'terminees' => self::LIVREE,
    ];

    /** Statuts de route déjà partie : la tournée n'est plus à charger, l'écran la montre en lecture seule. */
    public const STATUTS_ROUTE_PARTIE = ['en_cours', 'livraisons_terminees', 'terminee'];

    public static function pourFamille(Livraison $livraison): string
    {
        return match ($livraison->statut_conditionnement) {
            'prete' => self::PRETE,
            'en_cours' => self::EN_PREPARATION,
            default => self::RESTANTE,
        };
    }

    /**
     * Rang de tri sur l'écran : les tournées encore à charger d'abord, puis la chargée, celles en
     * livraison, enfin les terminées (17/10/2026).
     */
    public static function rangTri(string $etat): int
    {
        return match ($etat) {
            self::CHARGEE => 1,
            self::EN_LIVRAISON => 2,
            self::LIVREE => 3,
            default => 0,
        };
    }

    /** Suppose route.etapes.livraison chargées. */
    public static function pourRoute(RouteLivraison $route): string
    {
        // Route déjà partie (17/10/2026) : l'état de conditionnement n'a plus de sens. Terminée quand
        // le serveur l'a dit OU que tous les arrêts sont réglés — une livraison ignorée compte comme
        // terminée, comme une livrée.
        if (in_array($route->statut, ['livraisons_terminees', 'terminee'], true)) {
            return self::LIVREE;
        }

        if ($route->statut === 'en_cours') {
            return $route->etapes->isNotEmpty()
                && $route->etapes->pluck('statut')->every(fn($statut) => in_array($statut, ['livree', 'ignoree'], true))
                    ? self::LIVREE
                    : self::EN_LIVRAISON;
        }

        if ($route->statut === 'charge') {
            return self::CHARGEE;
        }

        if ($route->statut === 'packaging_annule') {
            return self::PACKAGING_ANNULE;
        }

        if ($route->statut === 'chargement') {
            return self::PRETE;
        }

        // pluck() sur la relation déjà chargée (pas de requête en plus) : lecture
        // de `livraison` sans passer par une propriété de relation typée `Model`.
        $familles = $route->etapes->pluck('livraison')->filter(fn($livraison) => $livraison instanceof Livraison)->values();

        if ($familles->isEmpty()) {
            return self::RESTANTE;
        }

        if ($familles->every(fn(Livraison $l) => $l->statut_conditionnement === 'prete')) {
            return self::PRETE;
        }

        return $familles->contains(fn(Livraison $l) => in_array($l->statut_conditionnement, ['en_cours', 'prete'], true))
            ? self::EN_PREPARATION
            : self::RESTANTE;
    }
}
