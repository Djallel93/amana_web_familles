<?php
// app/Http/Resources/LivraisonQueueResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ligne de la file de suivi des contacts — extrait le 12/09/2026
 * (Section E3 du refactor, suite) de ContactTrackingController::queue(),
 * qui renvoyait jusqu'ici `response()->json($query->paginate(...))` sur
 * `livraisons.*` en entier (locked_by/locked_at, note_besoins_speciaux,
 * id_benevole_impose... aucun de ces champs internes n'est lu par
 * ContactsQueue.vue — voir grep sur le template) avec `famille`,
 * `personneAssignee` et `campagne` chargés sans restriction de colonnes.
 *
 * `campagne` est absente d'ici : chargée par queue() jusqu'ici mais jamais
 * lue sur la ligne dans ContactsQueue.vue (le sélecteur de campagne de
 * l'écran vient d'un prop de page séparé, pas de ce champ) — l'eager load
 * correspondant a été retiré de queue() en même temps que ce resource
 * plutôt que d'être sérialisé sans consommateur.
 *
 * `creneaux` : whenLoaded() bien que queue() ne charge pas cette relation
 * aujourd'hui (l'interface TS Livraison la déclare optionnelle) — même
 * précaution que RouteLivraisonResource pour rester correcte si un futur
 * appelant de ce resource la charge.
 *
 * @property string|null $motif_statut_contact    Motif d'un archivage/rejet (09/10/2026)
 * @property string      $statut_conditionnement  en_attente|en_cours|prete (09/10/2026)
 */
class LivraisonQueueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_campagne' => $this->id_campagne,
            'id_campagne_journee' => $this->id_campagne_journee,
            'statut' => $this->statut,
            'statut_contact' => $this->statut_contact,
            // Motif d'un archivage/rejet (09/10/2026), affiché dans le détail de la ligne.
            'motif_statut_contact' => $this->motif_statut_contact,
            // Statut de conditionnement : l'UI s'en sert pour griser « Retirer »/« Réinitialiser »
            // (refusés par le serveur une fois le packaging commencé).
            'statut_conditionnement' => $this->statut_conditionnement,
            'id_personne_assignee' => $this->id_personne_assignee,
            'personne_assignee' => $this->whenLoaded('personneAssignee', fn() => $this->personneAssignee ? new PersonneResumeResource($this->personneAssignee) : null),
            // Chauffeur imposé (06/10/2026, « Prendre en charge »).
            'id_benevole_impose' => $this->resource->getAttribute('id_benevole_impose'),
            'benevole_impose' => $this->whenLoaded('benevoleImpose', fn() => $this->resource->getRelation('benevoleImpose') ? new PersonneResumeResource($this->resource->getRelation('benevoleImpose')) : null),
            'famille' => $this->whenLoaded('famille', fn() => new FamilleResumeResource($this->famille)),
            'adresse_confirmee' => $this->adresse_confirmee,
            'code_postal_confirme' => $this->code_postal_confirme,
            'ville_confirmee' => $this->ville_confirmee,
            'nombre_adulte_confirme' => $this->nombre_adulte_confirme,
            'nombre_enfant_confirme' => $this->nombre_enfant_confirme,
            // se_deplace (25/09/2026, prompt de cette date) : nécessaire à
            // l'affichage/toggle par ligne + au filtre sur cet écran (voir
            // ContactsQueue.vue), propriété pure de la campagne désormais.
            'se_deplace' => $this->se_deplace,
            'creneaux' => $this->whenLoaded('creneaux', fn() => $this->creneaux->map(fn($c) => ['creneau' => $c->creneau])),
        ];
    }
}
