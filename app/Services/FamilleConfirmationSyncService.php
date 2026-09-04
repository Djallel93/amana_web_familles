<?php
// app/Services/FamilleConfirmationSyncService.php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ResoudreAdresseFamille;
use App\Models\Livraison;

/**
 * Synchronise l'adresse et la composition du foyer confirmées pendant une
 * campagne (formulaire public OU saisie téléphonique gestionnaire — les
 * deux passent par ici, voir ContactConfirmationController::store() et
 * ContactTrackingController::contacterManuel()) vers la famille elle-même
 * — décision du 31/08/2026 : familles reste la SEULE source de vérité
 * pour l'adresse/coordonnées et la composition du foyer, pas de version
 * dupliquée au niveau livraison qui pourrait diverger silencieusement
 * (voir 2026_08_31_000200_revise_livraisons_confirmation_fields.php).
 *
 * Ne touche PAS note_besoins_speciaux, qui reste délibérément découplé de
 * familles.specificites (voir le prompt du 30/08/2026 §2 : "does not
 * write back to the family record") — cette classe ne gère que
 * adresse/code_postal/ville_texte/nombre_adulte/nombre_enfant.
 */
class FamilleConfirmationSyncService
{
    /**
     * @param array{adresse_confirmee: string, code_postal_confirme: ?string, ville_confirmee: ?string, nombre_adulte_confirme: int, nombre_enfant_confirme: int} $donnees
     */
    public function synchroniser(Livraison $livraison, array $donnees): void
    {
        $famille = $livraison->famille;

        $adresseAChange = $donnees['adresse_confirmee'] !== $famille->adresse
            || $donnees['code_postal_confirme'] !== $famille->code_postal
            || $donnees['ville_confirmee'] !== $famille->ville_texte;

        if ($adresseAChange) {
            $famille->update([
                'adresse' => $donnees['adresse_confirmee'],
                'code_postal' => $donnees['code_postal_confirme'],
                'ville_texte' => $donnees['ville_confirmee'],
            ]);

            // Même déclenchement que FamillesController/IntakeConfirmationController
            // après toute modification d'adresse.
            ResoudreAdresseFamille::dispatch($famille->id);
        }

        if ($donnees['nombre_adulte_confirme'] !== $famille->nombre_adulte
            || $donnees['nombre_enfant_confirme'] !== $famille->nombre_enfant) {
            $famille->update([
                'nombre_adulte' => $donnees['nombre_adulte_confirme'],
                'nombre_enfant' => $donnees['nombre_enfant_confirme'],
            ]);
        }
    }

    /**
     * Applique l'effet dossier déclaré par Livraison::STATUTS_CONTACT_EFFETS
     * pour un statut donné — voir le prompt du 03/09/2026 §2.5. Ne gère PAS
     * le cas 'sync' (statut 'confirme') : celui-ci passe par synchroniser()
     * ci-dessus, appelé séparément par l'appelant avec les données
     * confirmées (adresse/foyer), pas par cette méthode — les deux sont
     * volontairement découplées pour que 'confirme' avec des informations
     * inchangées ne déclenche aucune écriture famille superflue.
     */
    public function appliquerEffetStatut(Livraison $livraison, string $statut): void
    {
        $effet = Livraison::effetStatutContact($statut);

        if ($effet['etat_dossier'] === null || $effet['etat_dossier'] === 'sync') {
            return;
        }

        $livraison->famille->update(['etat_dossier' => $effet['etat_dossier']]);
    }
}
