<?php
// app/Http/Resources/FamilleListItemResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ligne du tableau "Dossiers familles" / "Nouvelles demandes" — Section
 * E4 du refactor (12/09/2026), chunk 4. Remplace le dump de modèles
 * Eloquent que Blade lisait directement (familles/partials/
 * tableau.blade.php) une fois FamillesController::index()/nouvelles()
 * passés à Inertia::render() — reprend exactement la forme attendue par
 * FamillesTable.vue (interface FamilleLigne, voir ce composant).
 *
 * Toutes les colonnes de Famille::COLONNES_TABLEAU sont incluses, y
 * compris celles masquées par défaut : le sélecteur de colonnes est un
 * bascule CSS/Vue côté client (v-show), pas une sélection de champs par
 * appel — chaque colonne peut être rendue visible sans nouvel aller-retour
 * serveur, donc chaque valeur doit déjà être présente dans le payload
 * initial. Contrairement aux resources de la Section E3 (qui suppriment
 * les champs jamais lus), rien n'est trimmé ici pour cette raison.
 *
 * `quartier` : uniquement id/nom (pas secteur/ville, même si
 * FamillesController::baseQuery() charge quartier.secteur.ville) —
 * FamillesTable.vue ne lit que .quartier.nom (vérifié par grep sur
 * l'ancien tableau.blade.php ET sur la Vue portée), l'eager load a été
 * réduit à `quartier` seul dans baseQuery() en même temps que ce
 * resource plutôt que d'être sérialisé sans consommateur.
 *
 * `verrou` (Scénario 5 du chantier "polling live", 19/09/2026) : seul
 * ajout hors Famille::COLONNES_TABLEAU — le marqueur "🔒 <nom>" du
 * tableau, voir le commentaire sur le champ.
 */
class FamilleListItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'nombre_foyer' => $this->nombre_foyer,
            'probleme_traitement' => $this->probleme_traitement,
            'etat_dossier' => $this->etat_dossier,
            'email' => $this->email,
            'telephone_formate' => $this->telephone_formate,
            'telephone_bis_formate' => $this->telephone_bis_formate,
            'adresse_complete' => $this->adresse_complete,
            'quartier' => $this->whenLoaded('quartier', fn () => $this->quartier ? [
                'id' => $this->quartier->id,
                'nom' => $this->quartier->nom,
            ] : null),
            'ville' => $this->ville,
            'organisation_origine' => $this->whenLoaded('organisationOrigine', fn () => $this->organisationOrigine ? [
                'id' => $this->organisationOrigine->id,
                'nom' => $this->organisationOrigine->nom,
            ] : null),
            'organisations' => $this->whenLoaded('organisations', fn () => $this->organisations->map(fn ($organisation) => [
                'id' => $organisation->id,
                'nom' => $organisation->nom,
            ])),
            'nombre_adulte' => $this->nombre_adulte,
            'nombre_enfant' => $this->nombre_enfant,
            'criticite' => $this->criticite,
            'zakat_el_fitr' => $this->zakat_el_fitr,
            'sadaqa' => $this->sadaqa,
            'se_deplace' => $this->se_deplace,
            'est_hotel' => $this->est_hotel,
            'etudiant' => $this->etudiant,
            'langue' => $this->langue,
            'type_piece_identite' => $this->type_piece_identite,
            'circonstances' => $this->circonstances,
            'ressentit' => $this->ressentit,
            'specificites' => $this->specificites,
            'commentaire_dossier' => $this->commentaire_dossier,
            // Verrou d'édition EN COURS (Scénario 5 du chantier "polling
            // live") : null si personne n'édite ce dossier ou si le verrou
            // est périmé (voir Famille::verrouFrais()). Absent du payload
            // si la relation `verrouilleur` n'a pas été chargée (seuls
            // index()/nouvelles() la chargent). `par_moi` : permet au
            // tableau d'afficher "vous" plutôt que son propre nom.
            // when(relationLoaded(...)) plutôt que whenLoaded('verrouilleur',
            // closure) : ce dernier renvoie null SANS appeler la closure quand
            // la relation chargée est null — un verrou frais dont le
            // détenteur n'existe plus (personne supprimée) disparaîtrait du
            // tableau alors que show() refuse toujours l'ouverture.
            'verrou' => $this->when($this->resource->relationLoaded('verrouilleur'), fn () => $this->verrouFrais() ? [
                'par' => $this->verrouilleur?->nom_complet,
                'par_moi' => (int) $this->locked_by === (int) $request->user()?->id,
                'depuis' => $this->locked_at->toISOString(),
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
