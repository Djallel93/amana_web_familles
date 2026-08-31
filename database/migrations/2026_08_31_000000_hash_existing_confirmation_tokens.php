<?php
// database/migrations/2026_08_31_000013_hash_existing_confirmation_tokens.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration de données : rehache en place les jetons stockés en clair dans
 * les 3 flux existants (familles_verifications, intake_demandes_attente,
 * benevole_demandes_attente) — voir App\Support\TokenHasher et l'audit du
 * 31/08/2026 déclenché par la conception de livraison.contact_tokens
 * (seule table du domaine livraison à avoir été conçue avec un jeton
 * haché dès le départ — voir 2026_08_31_000006_create_contact_tokens_table.php).
 *
 * SHA2(token, 256) côté MySQL produit exactement le même résultat que
 * hash('sha256', $token) côté PHP (mêmes octets en entrée — Str::random()
 * ne produit que de l'ASCII — même algorithme, même sortie hex
 * minuscule) : les jetons déjà envoyés par email avant cette migration
 * restent utilisables après (comparaison par hash toujours valide).
 *
 * IntakeDemandeAttente uniquement : le jeton servait AUSSI de nom de
 * dossier de stockage temporaire (storage/app/private/intake-attente/
 * {token}/...) — désormais remplacé par l'id de la ligne (voir
 * IntakeDemandeAttente::cheminStockageTemporaire() et
 * IntakeAttenteService). Décision du 31/08/2026 (contexte encore en
 * développement, aucune perte de donnée réelle à risque) : cette
 * migration NE déplace PAS les fichiers déjà présents sous d'éventuels
 * dossiers nommés par l'ancien jeton — une demande en attente non
 * confirmée avant ce déploiement verrait sa confirmation échouer à
 * retrouver ses fichiers (expire de toute façon sous 48h, voir
 * IntakeAttenteService::DUREE_VALIDITE_HEURES, et est nettoyée par la
 * commande de nettoyage quotidienne existante).
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['familles_verifications', 'intake_demandes_attente', 'benevole_demandes_attente'] as $table) {
            DB::statement("UPDATE {$table} SET token = SHA2(token, 256) WHERE CHAR_LENGTH(token) != 64");
        }
    }

    /**
     * Irréversible par construction (hachage à sens unique) — pas de
     * down() utile ; laissé vide plutôt que de lever une exception, même
     * convention que les autres migrations de données de cette app qui
     * n'ont pas de retour en arrière significatif.
     */
    public function down(): void
    {
    }
};
