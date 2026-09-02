<?php
// app/Services/RouteMutationService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Models\Personne;
use App\Models\Campagne;
use App\Models\EtapeRoute;
use App\Models\Livraison;
use App\Models\RouteLivraison;
use App\Notifications\RouteModifieeNotification;
use App\Support\RouteOptimizationConfig;
use Illuminate\Support\Facades\DB;

/**
 * Mutabilité des tournées après génération — ajout/retrait d'une
 * livraison, redimensionnement (réassignation véhicule/bénévole),
 * scission, tournée entièrement personnalisée. Voir le prompt du
 * 30/08/2026 §3.3 : "Routes must remain mutable after creation... Any
 * such change must notify the affected driver(s) and admin/gestionnaire."
 *
 * Notification : voir RouteModifieeNotification, envoyée depuis chaque
 * méthode qui change la composition/l'affectation d'une tournée.
 *
 * Réutilise TspOptimizationService/GeoCalculationService (mêmes calculs
 * qu'à la génération, voir RouteGenerationService) — jamais de jambe de
 * retour au QG ici non plus, cohérence avec le reste du domaine.
 */
class RouteMutationService
{
    public function __construct(
        private readonly TspOptimizationService $tsp,
        private readonly GeoCalculationService $geo,
    ) {
    }

    /**
     * Ajoute une livraison à une tournée existante — ré-ordonne
     * l'ensemble via TSP plutôt que d'ajouter simplement au bout, pour
     * rester géographiquement cohérent.
     */
    public function ajouterLivraison(RouteLivraison $route, Livraison $livraison): RouteLivraison
    {
        $hq = $this->hqOuEchoue();

        $etapesActuelles = $route->etapes()->with('livraison.famille')->orderBy('ordre')->get();
        $livraisonsArray = $etapesActuelles->map(fn (EtapeRoute $e) => $this->versArray($e->livraison))->all();
        $livraisonsArray[] = $this->versArray($livraison);

        $ordonnees = $this->tsp->optimiser($livraisonsArray, $hq);

        DB::transaction(function () use ($route, $ordonnees, $livraison) {
            $route->etapes()->delete();

            foreach ($ordonnees as $index => $l) {
                EtapeRoute::create([
                    'id_route' => $route->id,
                    'id_livraison' => $l['id_livraison'],
                    'ordre' => $index + 1,
                    'statut' => 'en_attente',
                ]);
            }

            $livraison->update(['statut' => 'assignee']);
        });

        $this->recalculerMetriques($route, $ordonnees, $hq);
        $this->notifierBenevole($route, 'Une livraison a été ajoutée à votre tournée.');

        return $route->fresh();
    }

    /**
     * Retire une livraison d'une tournée — remet la livraison
     * non_assignee, renumérote les arrêts restants.
     */
    public function retirerLivraison(RouteLivraison $route, EtapeRoute $etape): RouteLivraison
    {
        $hq = $this->hqOuEchoue();

        DB::transaction(function () use ($route, $etape) {
            $etape->livraison?->update(['statut' => 'non_assignee']);
            $etape->delete();

            $restantes = $route->etapes()->orderBy('ordre')->get();
            foreach ($restantes as $index => $e) {
                $e->update(['ordre' => $index + 1]);
            }
        });

        $ordonnees = $route->etapes()->with('livraison.famille')->orderBy('ordre')->get()
            ->map(fn (EtapeRoute $e) => $this->versArray($e->livraison))->all();
        $this->recalculerMetriques($route, $ordonnees, $hq);

        $this->notifierBenevole($route, 'Une livraison a été retirée de votre tournée.');

        return $route->fresh();
    }

    /**
     * Réassigne une tournée à un autre bénévole/véhicule — utile pour le
     * redimensionnement ("resize per actual vehicle capacity discovered
     * at load time", voir le prompt §3.3) : ne vérifie PAS que la
     * nouvelle capacité suffit, c'est une action manuelle déclarée par un
     * admin/gestionnaire qui a déjà cette information sous les yeux.
     */
    public function reassigner(RouteLivraison $route, int $idBenevole, int $idVehiculeType): RouteLivraison
    {
        $ancienBenevole = $route->id_benevole;

        $route->update(['id_benevole' => $idBenevole, 'id_vehicule_type' => $idVehiculeType]);

        $route = $route->fresh();
        $this->notifierBenevole($route, 'Cette tournée vous a été assignée.');
        if ($ancienBenevole !== $idBenevole) {
            $this->notifierPersonne($ancienBenevole, 'Cette tournée ne vous est plus assignée.');
        }

        return $route;
    }

    /**
     * Scinde une tournée en deux au milieu de sa liste d'arrêts déjà
     * ordonnée par TSP — décision du 31/08/2026 : une tournée TSP-ordonnée
     * est déjà géographiquement cohérente par construction, un split au
     * milieu produit donc deux moitiés cohérentes sans avoir besoin de
     * relancer une recherche de diamètre minimum (ClusterSplitService,
     * qui répond à un besoin différent : choisir QUOI garder pour un
     * véhicule à capacité donnée — ici il n'y a pas de contrainte de
     * capacité à respecter, juste "couper en deux").
     *
     * La seconde moitié devient une nouvelle tournée, avec le MÊME
     * bénévole que l'originale par défaut (à réaffecter ensuite via
     * reassigner() si besoin) — la première conserve tout le reste.
     */
    public function diviser(RouteLivraison $route): RouteLivraison
    {
        $hq = $this->hqOuEchoue();

        $etapes = $route->etapes()->with('livraison.famille')->orderBy('ordre')->get();

        if ($etapes->count() < 2) {
            throw new \RuntimeException('Cette tournée a trop peu d\'arrêts pour être scindée.');
        }

        $milieu = intdiv($etapes->count(), 2);
        $secondeMoitie = $etapes->slice($milieu)->values();

        $nouvelleRoute = DB::transaction(function () use ($route, $secondeMoitie) {
            $nouvelleRoute = RouteLivraison::create([
                'id_campagne' => $route->id_campagne,
                'id_benevole' => $route->id_benevole,
                'id_vehicule_type' => $route->id_vehicule_type,
                'creneau' => $route->creneau,
                'statut' => 'planifiee',
            ]);

            foreach ($secondeMoitie as $index => $etape) {
                EtapeRoute::create([
                    'id_route' => $nouvelleRoute->id,
                    'id_livraison' => $etape->id_livraison,
                    'ordre' => $index + 1,
                    'statut' => 'en_attente',
                ]);
                $etape->delete();
            }

            return $nouvelleRoute;
        });

        $premiereMoitieArray = $route->etapes()->with('livraison.famille')->orderBy('ordre')->get()
            ->map(fn (EtapeRoute $e) => $this->versArray($e->livraison))->all();
        $secondeMoitieArray = $secondeMoitie->map(fn (EtapeRoute $e) => $this->versArray($e->livraison))->all();

        $this->recalculerMetriques($route, $premiereMoitieArray, $hq);
        $this->recalculerMetriques($nouvelleRoute, $secondeMoitieArray, $hq);

        $this->notifierBenevole($route->fresh(), 'Votre tournée a été scindée en deux — vous avez maintenant deux tournées distinctes.');

        return $nouvelleRoute->fresh();
    }

    /**
     * Tournée entièrement personnalisée — l'admin choisit directement un
     * bénévole et un ensemble de livraisons non assignées (voir le prompt
     * §3.3 : "build a fully custom route for a driver who wants specific
     * secteurs"). Pas de clustering/assignation (déjà fait manuellement
     * par le choix de l'admin) — seulement le TSP pour l'ordre des arrêts.
     *
     * @param int[] $idsLivraisons
     */
    public function construirePersonnalisee(
        Campagne $campagne,
        int $idBenevole,
        int $idVehiculeType,
        array $idsLivraisons,
        ?string $creneau,
    ): RouteLivraison {
        $hq = $this->hqOuEchoue();

        $livraisons = Livraison::whereIn('id', $idsLivraisons)
            ->where('statut', 'non_assignee')
            ->with('famille:id,latitude,longitude,id_quartier')
            ->get();

        if ($livraisons->isEmpty()) {
            throw new \RuntimeException('Aucune des livraisons sélectionnées n\'est disponible.');
        }

        $livraisonsArray = $livraisons->map(fn (Livraison $l) => $this->versArray($l))->all();
        $ordonnees = $this->tsp->optimiser($livraisonsArray, $hq);

        $route = DB::transaction(function () use ($campagne, $idBenevole, $idVehiculeType, $creneau, $ordonnees) {
            $route = RouteLivraison::create([
                'id_campagne' => $campagne->id,
                'id_benevole' => $idBenevole,
                'id_vehicule_type' => $idVehiculeType,
                'creneau' => $creneau,
                'statut' => 'planifiee',
            ]);

            foreach ($ordonnees as $index => $l) {
                EtapeRoute::create([
                    'id_route' => $route->id,
                    'id_livraison' => $l['id_livraison'],
                    'ordre' => $index + 1,
                    'statut' => 'en_attente',
                ]);
            }

            Livraison::whereIn('id', array_column($ordonnees, 'id_livraison'))->update(['statut' => 'assignee']);

            return $route;
        });

        $this->recalculerMetriques($route, $ordonnees, $hq);
        $route = $route->fresh();
        $this->notifierBenevole($route, 'Une nouvelle tournée personnalisée vous a été assignée.');

        return $route;
    }

    private function hqOuEchoue(): array
    {
        $hq = RouteOptimizationConfig::coordonneesHq();
        if ($hq === null) {
            throw new \RuntimeException('Coordonnées QG non configurées — voir Paramètres.');
        }

        return $hq;
    }

    private function recalculerMetriques(RouteLivraison $route, array $livraisonsOrdonnees, array $hq): void
    {
        $route->update([
            'distance_totale_km' => $this->geo->distanceTotaleRoute($livraisonsOrdonnees, $hq),
            'poids_total_kg' => array_sum(array_column($livraisonsOrdonnees, 'poids_kg')),
            'lien_maps' => $this->geo->construireLienMaps($livraisonsOrdonnees, $hq),
        ]);
    }

    private function versArray(Livraison $livraison): array
    {
        return [
            'id_livraison' => $livraison->id,
            'latitude' => (float) $livraison->famille->latitude,
            'longitude' => (float) $livraison->famille->longitude,
            'nombre_personnes' => $livraison->nombre_personnes,
            'poids_kg' => (float) $livraison->poids_kg,
            'id_quartier' => $livraison->famille->id_quartier,
        ];
    }

    private function notifierBenevole(RouteLivraison $route, string $message): void
    {
        $this->notifierPersonne($route->id_benevole, $message);
    }

    private function notifierPersonne(int $idPersonne, string $message): void
    {
        $personne = Personne::find($idPersonne);
        $personne?->notify(new RouteModifieeNotification($message));
    }
}
