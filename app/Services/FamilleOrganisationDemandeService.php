<?php
// app/Services/FamilleOrganisationDemandeService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Famille;
use App\Models\FamilleOrganisationDemande;
use App\Models\Organisation;

/**
 * Cycle de vie d'une demande de rattachement d'une organisation à un
 * dossier déjà rattaché à une (ou plusieurs) AUTRE(s) organisation(s) —
 * voir FamilleUpsertService::upsert() pour le point de déclenchement et
 * la migration create_famille_organisation_demandes_table pour le
 * raisonnement complet.
 */
class FamilleOrganisationDemandeService
{
    /**
     * Crée une demande en_attente, ou met à jour la ligne en_attente déjà
     * existante pour ce couple (famille, organisation) — même esprit que
     * IntakeAttenteService::trouverAttenteExistante() : une resoumission
     * avant traitement remplace la précédente plutôt que d'empiler deux
     * demandes actives.
     */
    public function creerOuMettreAJour(
        Famille $famille,
        int $idOrganisation,
        string $source,
        ?int $submittedBy,
        array $donneesSoumises,
    ): FamilleOrganisationDemande {
        return FamilleOrganisationDemande::updateOrCreate(
            ['id_famille' => $famille->id, 'id_organisation' => $idOrganisation, 'statut' => 'en_attente'],
            ['source' => $source, 'submitted_by' => $submittedBy, 'donnees_soumises' => $donneesSoumises],
        );
    }

    /**
     * Valide la demande : rattache réellement l'organisation au dossier
     * (famille_organisation) sans toucher aux autres champs du dossier —
     * la fusion des champs soumis par l'organisation B reste une décision
     * humaine (voir donnees_soumises, affiché à l'écran de revue), pas un
     * écrasement automatique.
     */
    public function valider(FamilleOrganisationDemande $demande, int $idPersonneTraitant): void
    {
        $demande->famille->organisations()->syncWithoutDetaching([$demande->id_organisation]);

        $demande->update([
            'statut' => 'validee',
            'traite_par' => $idPersonneTraitant,
            'traite_le' => now(),
        ]);

        audit('update', 'famille_organisation_demandes', $demande->id, ['statut' => 'en_attente'], ['statut' => 'validee']);
    }

    public function rejeter(FamilleOrganisationDemande $demande, int $idPersonneTraitant): void
    {
        $demande->update([
            'statut' => 'rejetee',
            'traite_par' => $idPersonneTraitant,
            'traite_le' => now(),
        ]);

        audit('update', 'famille_organisation_demandes', $demande->id, ['statut' => 'en_attente'], ['statut' => 'rejetee']);
    }
}
