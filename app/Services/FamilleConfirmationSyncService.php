<?php
// app/Services/FamilleConfirmationSyncService.php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ResoudreAdresseFamille;
use App\Models\Famille;
use App\Models\Livraison;

/**
 * Synchronise l'adresse et la composition du foyer confirmées pendant une
 * campagne (formulaire public OU saisie téléphonique gestionnaire — les
 * deux passent par ici, voir ContactConfirmationController::store() et
 * ContactTrackingController::contacterManuel()) vers la famille elle-même
 * — décision du 31/08/2026 : familles reste la SEULE source de vérité
 * pour l'adresse/coordonnées et la composition du foyer, pas de version
 * dupliquée au niveau livraison qui pourrait diverger silencieusement
 * (voir 2026_08_31_000004_create_livraison_operations_tables.php).
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
        $foyerAChange = $donnees['nombre_adulte_confirme'] !== $famille->nombre_adulte
            || $donnees['nombre_enfant_confirme'] !== $famille->nombre_enfant;

        // Trace dans le commentaire du dossier (17/10/2026) : une confirmation de campagne qui
        // corrige l'adresse ou le foyer modifie le dossier sans que personne ne le saisisse dans
        // le panneau — la ligne dit d'où vient le changement (source « sync_confirmation »).
        if ($adresseAChange || $foyerAChange) {
            $modifs = array_filter([
                $adresseAChange ? 'adresse' : null,
                $foyerAChange ? 'composition du foyer' : null,
            ]);
            $this->tracerDansLeDossier(
                $livraison,
                'Mise à jour depuis la confirmation de la campagne #' . $livraison->id_campagne . ' : ' . implode(' et ', $modifs) . '.',
            );
        }

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

        if ($foyerAChange) {
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
    public function appliquerEffetStatut(Livraison $livraison, string $statut, ?string $motif = null): void
    {
        $effet = Livraison::effetStatutContact($statut);

        if ($effet['etat_dossier'] === null || $effet['etat_dossier'] === 'sync') {
            return;
        }

        /** @var Famille $famille */
        $famille = $livraison->famille;
        $avant = $famille->etat_dossier;
        $famille->etat_dossier = $effet['etat_dossier'];

        // Le motif saisi dans la popup (archiver / rejeter) est ajouté au commentaire du dossier
        // (17/10/2026), en plus de livraisons.motif_statut_contact : une seule ligne, datée et signée.
        $texte = "Statut {$avant} → {$effet['etat_dossier']} (campagne #{$livraison->id_campagne})";
        $famille->ajouterCommentaire($motif !== null && trim($motif) !== '' ? "{$texte} : " . trim($motif) : $texte, 'manuel', enregistrer: false);
        $famille->save();
    }

    /**
     * Ajoute une ligne « sync_confirmation » au commentaire du dossier (17/10/2026). Variable typée
     * ici plutôt que dans synchroniser() : la relation Livraison::famille() est vue comme un Model
     * générique par l'analyse statique (voir phpstan-baseline.neon).
     */
    private function tracerDansLeDossier(Livraison $livraison, string $texte): void
    {
        /** @var Famille $famille */
        $famille = $livraison->famille;
        $famille->ajouterCommentaire($texte, 'sync_confirmation');
    }
}
