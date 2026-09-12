<?php
// app/Http/Resources/FamilleDetailResource.php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Réponse JSON du panneau de détail famille — extrait le 10/09/2026
 * (Section E3 du refactor) de FamillesController::show()/update(), qui
 * renvoyaient jusqu'ici `response()->json($famille)`/
 * `response()->json($famille->fresh([...]))` : un dump brut du modèle,
 * sans aucun contrôle explicite des champs exposés. Utilisé identiquement
 * par les deux méthodes (mêmes relations chargées, même forme de réponse).
 *
 * Champs limités à ceux que resources/js/components/familles/DetailPanel.vue
 * lit effectivement (vérifié champ par champ avant d'écrire cette classe).
 * Volontairement absents malgré leur présence sur le modèle : id_organisation,
 * google_resource_name, locked_by/locked_at/etat_dossier_avant_verrouillage
 * (bascule de verrouillage interne, locked_by identifiant un AUTRE membre du
 * staff — aucun intérêt pour ce panneau), created_at/updated_at. Aucun de
 * ces champs n'était lu par le frontend ; les exposer n'apportait rien et
 * `locked_by` en particulier n'a pas vocation à circuler jusqu'au client.
 *
 * Corrige au passage un bug latent : show() protégeait déjà
 * `quartier`/`quartier.secteur.ville` contre la colonne géométrique
 * `boundary` (UTF-8 invalide, faisait planter `response()->json()`) via
 * `makeHidden('boundary')`, mais update() ne le faisait PAS — cette classe
 * ne lit jamais `boundary` du tout, donc le bug ne peut plus se reproduire
 * sur AUCUN des deux points d'entrée.
 */
class FamilleDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'email' => $this->email,
            'telephone' => $this->telephone,
            'telephone_bis' => $this->telephone_bis,
            'zakat_el_fitr' => $this->zakat_el_fitr,
            'sadaqa' => $this->sadaqa,
            'nombre_adulte' => $this->nombre_adulte,
            'nombre_enfant' => $this->nombre_enfant,
            'adresse' => $this->adresse,
            'code_postal' => $this->code_postal,
            'ville_texte' => $this->ville_texte,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'se_deplace' => $this->se_deplace,
            'est_hotel' => $this->est_hotel,
            'etudiant' => $this->etudiant,
            'criticite' => $this->criticite,
            'langue' => $this->langue,
            'etat_dossier' => $this->etat_dossier,
            'commentaire_dossier' => $this->commentaire_dossier,
            'probleme_traitement' => $this->probleme_traitement,
            'circonstances' => $this->circonstances,
            'ressentit' => $this->ressentit,
            'specificites' => $this->specificites,
            'type_hebergement' => $this->type_hebergement,
            'hosted_by' => $this->hosted_by,
            'type_piece_identite' => $this->type_piece_identite,
            'type_activite' => $this->type_activite,
            'work_days' => $this->work_days,
            'secteur_activite_autre' => $this->secteur_activite_autre,
            'organisme_aide_autre' => $this->organisme_aide_autre,

            // Seuls id/nom lus par DetailPanel.vue — jamais `boundary`
            // (voir docblock de classe).
            'quartier' => $this->whenLoaded('quartier', fn () => $this->quartier ? [
                'id' => $this->quartier->id,
                'nom' => $this->quartier->nom,
            ] : null),

            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($document) => [
                'id' => $document->id,
                'type' => $document->type,
                'original_name' => $document->original_name,
                'mime_type' => $document->mime_type,
                'uploaded_at' => $document->uploaded_at,
                // disk_path volontairement absent — chemin serveur, aucun
                // intérêt pour le client (le téléchargement passe par
                // familles.documents.download, pas par ce chemin direct).
            ])),

            'secteurs_activite' => $this->whenLoaded('secteursActivite', fn () => $this->secteursActivite->map(fn ($secteur) => [
                'id' => $secteur->id,
                'code' => $secteur->code,
                'libelle_fr' => $secteur->libelle_fr,
                'libelle_ar' => $secteur->libelle_ar,
                'libelle_en' => $secteur->libelle_en,
            ])),

            'organismes_aide' => $this->whenLoaded('organismesAide', fn () => $this->organismesAide->map(fn ($organisme) => [
                'id' => $organisme->id,
                'code' => $organisme->code,
                'libelle_fr' => $organisme->libelle_fr,
                'libelle_ar' => $organisme->libelle_ar,
                'libelle_en' => $organisme->libelle_en,
            ])),
        ];
    }
}
