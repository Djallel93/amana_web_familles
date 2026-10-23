<?php
// app/Http/Controllers/Admin/Livraison/IncidentsController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Livraison;

use App\Http\Controllers\Controller;
use App\Models\Campagne;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use App\Services\IncidentResolutionService;
use App\Support\Creneau;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Section « Incidents » du hub d'une campagne (page dédiée du 03/10/2026
 * remplacée le 06/10/2026 par une section repliable du hub, plus de page) :
 * liste de TOUS les incidents de la campagne avec leur statut (ouvert, fermé/ignoré,
 * résolu), filtre par statut (défaut : ouverts) et tri par date côté client
 * (le volume d'une campagne reste de l'ordre de quelques dizaines), clic sur
 * une ligne pour le détail. Résoudre / ignorer passent par les endpoints
 * existants de LiveBoardController (incidents.resoudre / incidents.ignorer).
 *
 * Le jalon chargement_termine (statut null, pas une alerte) n'est jamais
 * listé.
 */
class IncidentsController extends Controller
{
    public function __construct(private readonly IncidentResolutionService $incidents) {}

    public function liste(Campagne $campagne): JsonResponse
    {
        $incidents = RouteIncident::whereNotNull('statut')
            ->deCampagne($campagne->id)
            ->with(['route.benevole', 'livraison.famille', 'signalePar:id,nom,prenom'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn(RouteIncident $i) => [
                'id' => $i->id,
                'type' => $i->type,
                'type_label' => RouteIncident::LABELS_TYPE[$i->type] ?? $i->type,
                'statut' => $i->statut,
                'description' => $i->description(),
                'guide' => $i->guide(),
                'notes' => $i->notes,
                'id_route' => $i->id_route,
                'id_livraison' => $i->id_livraison,
                'id_campagne_journee' => data_get($i, 'livraison.id_campagne_journee'),
                'id_campagne' => $i->idCampagneEffectif(),
                'chauffeur' => $i->route?->benevole ? trim("{$i->route->benevole->prenom} {$i->route->benevole->nom}") : null,
                // 23/10/2026 : de quoi joindre la personne concernée depuis le détail de l'incident.
                'chauffeur_telephone' => $i->route?->benevole?->telephone_formate,
                'famille_telephone' => $i->livraison?->famille ? array_values(array_filter([$i->livraison->famille->telephone_formate, $i->livraison->famille->telephone_bis_formate])) : [],
                'famille_email' => $i->livraison?->famille?->email,
                'route_statut' => $i->route?->statut,
                'detecte' => in_array($i->type, RouteIncident::TYPES_DETECTES, true),
                'famille' => $i->livraison?->famille ? trim("{$i->livraison->famille->prenom} {$i->livraison->famille->nom}") : null,
                'signale_par' => $i->signalePar ? trim("{$i->signalePar->prenom} {$i->signalePar->nom}") : null,
                'created_at' => $i->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json($incidents);
    }

    /**
     * Rouvre un incident résolu ou fermé (09/10/2026) — refusé, avec un message,
     * quand la campagne est terminée. La réponse peut porter un `avertissement`
     * (ex. re-clustering d'un bénévole absent non défait).
     */
    public function rouvrir(RouteIncident $incident): JsonResponse
    {
        try {
            $resultat = $this->incidents->rouvrir($incident);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, ...$resultat]);
    }

    /**
     * Données du formulaire « Résoudre » d'une livraison ignorée : tournées auxquelles on peut
     * encore ajouter la famille (même campagne, pas encore chargées).
     */
    public function options(RouteIncident $incident): JsonResponse
    {
        // 18/10/2026 : « chauffeur indisponible » — chauffeurs candidats au lieu des tournées d'accueil.
        if ($incident->type === 'chauffeur_indisponible') {
            return response()->json($this->incidents->optionsChauffeurIndisponible($incident));
        }

        // 23/10/2026 : types à aide dédiée (capacité, familles sans tournée).
        if (in_array($incident->type, ['capacite', 'livraisons_non_couvertes'], true)) {
            return response()->json($this->incidents->optionsAutres($incident));
        }

        $idCampagne = $incident->idCampagneEffectif();

        $tournees = $idCampagne === null ? collect() : RouteLivraison::where('id_campagne', $idCampagne)
            ->whereIn('statut', ['planifiee', 'chargement', 'packaging_annule'])
            ->with('benevole:id,nom,prenom')
            ->withCount('etapes')
            ->orderBy('id')
            ->get()
            ->map(fn(RouteLivraison $r) => [
                'id' => $r->id,
                'libelle' => "Tournée #{$r->id} — " . (trim((string) data_get($r, 'benevole.prenom') . ' ' . (string) data_get($r, 'benevole.nom')) ?: 'sans chauffeur')
                    . ($r->creneau ? ' · ' . Creneau::libelle($r->creneau) : ' · sans créneau')
                    . " · {$r->etapes_count} arrêt(s)",
            ])
            ->values();

        return response()->json([
            'id_livraison' => $incident->id_livraison,
            'id_campagne_journee' => data_get($incident, 'livraison.id_campagne_journee'),
            'id_campagne' => $idCampagne,
            'tournees' => $tournees,
        ]);
    }

    /**
     * Résout « livraison ignorée » ou « retrait QG non livré » avec une suite à donner
     * à la famille (09/10/2026) — voir IncidentResolutionService. Le message de refus
     * d'une action impossible (tournée chargée, chauffeur non confirmé…) est renvoyé tel quel.
     */
    public function resoudreAvecSuite(Request $request, RouteIncident $incident): JsonResponse
    {
        $donnees = $request->validate([
            'action' => 'required|string|in:reinitialiser,tournee,retrait_qg,chauffeur,reessayer,domicile,reassigner,repartir,retirer,diviser,prevenue,annuler_imposition,regeocoder,a_recontacter,renvoyer',
            'ids_etapes' => 'nullable|array',
            'ids_etapes.*' => 'integer',
            'id_route' => 'nullable|integer',
            'id_benevole' => 'nullable|integer',
            'ids_benevoles' => 'nullable|array',
            'ids_benevoles.*' => 'integer',
        ]);

        $message = null;
        try {
            if (in_array($incident->type, ['capacite', 'se_deplace_remplace', 'livraisons_non_couvertes', 'famille_non_geocodee', 'famille_injoignable', 'email_echec'], true)
               ) {
                $message = $this->incidents->resoudreAutre($incident, $donnees['action'], $donnees);
            } elseif ($incident->type === 'chauffeur_indisponible') {
                $this->incidents->resoudreChauffeurIndisponible($incident, $donnees['action'], $donnees);
            } elseif ($incident->type === 'retrait_hq_non_livre') {
                $this->incidents->resoudreRetraitNonLivre($incident, $donnees['action']);
            } else {
                $this->incidents->resoudreLivraisonIgnoree($incident, $donnees['action'], $donnees);
            }
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => $message]);
    }
}
