<?php
// app/Support/FamilleFilters.php

declare(strict_types=1);

namespace App\Support;

use Amana\Shared\Models\Quartier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Filtres géographiques/critères applicables à une requête Famille —
 * extrait de FamillesController::baseQuery() le 05/09/2026 (prompt §1.6/§2.6 :
 * "same filter panel as Dossier Familles") pour que l'écran de sélection
 * des familles éligibles d'une campagne et l'écran Suivi des contacts
 * filtrent EXACTEMENT comme Dossier Familles, sans dupliquer/dériver la
 * logique à côté (et risquer une divergence silencieuse plus tard).
 *
 * Ne couvre QUE les filtres — pas le scope de visibilité par organisation
 * (Famille::scopeVisiblePar(), réservé aux comptes gestionnaire_externe) :
 * FamillesController continue de l'appliquer lui-même autour de cet
 * appel, décision volontaire pour ne pas introduire silencieusement cette
 * restriction d'accès sur des écrans (éligibilité campagne, contacts) qui
 * ne l'avaient jamais eue jusqu'ici — sujet à trancher séparément si
 * besoin.
 *
 * Ne couvre pas non plus le filtre etat_dossier (FamillesController
 * l'applique via appliquerFiltreStatut(), avec un défaut différent selon
 * l'écran) : l'appelant reste responsable de restreindre par état de
 * dossier selon son propre besoin (ex: eligibles() ne veut QUE 'Validé').
 */
class FamilleFilters
{
    public static function appliquer(Builder $query, Request $request): void
    {
        if ($request->filled('id_quartier')) {
            $query->where('id_quartier', $request->input('id_quartier'));
        }
        // id_secteur / id_ville : voir FamillesController::baseQuery() pour
        // le raisonnement complet (Quartier/Secteur/Ville vivent sur la
        // connexion 'commun', deux requêtes mono-connexion plutôt qu'un
        // whereHas() cross-DB).
        if ($request->filled('id_secteur')) {
            $query->whereIn('id_quartier', Quartier::where('id_secteur', $request->input('id_secteur'))->pluck('id'));
        }
        if ($request->filled('id_ville')) {
            $query->whereIn('id_quartier', Quartier::whereHas('secteur', fn ($q) => $q->where('id_ville', $request->input('id_ville')))->pluck('id'));
        }
        if ($request->boolean('zakat_el_fitr')) {
            $query->where('zakat_el_fitr', true);
        }
        if ($request->boolean('sadaqa')) {
            $query->where('sadaqa', true);
        }
        if ($request->boolean('se_deplace')) {
            $query->where('se_deplace', true);
        }
        if ($request->boolean('est_hotel')) {
            $query->where('est_hotel', true);
        }
        if ($request->boolean('etudiant')) {
            $query->where('etudiant', true);
        }
        if ($request->filled('criticite')) {
            $valeurs = array_values(array_intersect(
                array_map('intval', (array) $request->input('criticite')),
                range(0, 5),
            ));
            if (!empty($valeurs)) {
                $query->whereIn('criticite', $valeurs);
            }
        }
        if ($request->filled('recherche')) {
            $query->recherche($request->input('recherche'));
        }
        if ($request->filled('id_selection')) {
            $query->where('id', (int) $request->input('id_selection'));
        } else {
            if ($request->filled('nom')) {
                $query->rechercheNom($request->input('nom'));
            }
            if ($request->filled('telephone')) {
                $query->rechercheTelephone($request->input('telephone'));
            }
        }
        if ($request->filled('id_organisation_origine')) {
            $query->where('id_organisation', $request->input('id_organisation_origine'));
        }
        if ($request->filled('id_organisation_rattachee')) {
            $query->whereHas('organisations', fn ($q) => $q->where('organisations.id', $request->input('id_organisation_rattachee')));
        }
    }
}
