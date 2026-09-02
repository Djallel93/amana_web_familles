<?php
// database/migrations/2026_08_31_000400_make_routes_creneau_nullable.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rend routes.creneau nullable — migration ADDITIVE distincte de
 * 2026_08_31_000009_create_routes_table.php (déjà livrée dans le Patch
 * 1), plutôt qu'une modification de cette migration existante.
 *
 * Découvert en écrivant RouteGenerationService (Patch 3) : une tournée
 * composée UNIQUEMENT de livraisons imposées (id_benevole_impose, voir
 * livraisons) n'a pas de créneau unique qui lui corresponde — ces
 * livraisons sont explicitement exemptées de toute correspondance de
 * créneau (voir le prompt du 30/08/2026 §2 : "Pinned deliveries are
 * exempt from timeslot/créneau matching entirely"). Une tournée générée
 * pour un bénévole avec uniquement des livraisons imposées reste donc
 * sans créneau (null) plutôt que de lui en inventer un arbitrairement.
 * Utilise une requête SQL brute (DB::statement) plutôt que
 * Blueprint::change() : cette dernière nécessite le paquet
 * doctrine/dbal, absent de composer.json et jamais utilisé ailleurs dans
 * ce projet — pas introduit pour une seule migration alors qu'une requête
 * MySQL directe suffit (même approche que
 * 2026_08_31_000000_hash_existing_confirmation_tokens.php).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE routes MODIFY creneau VARCHAR(5) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE routes MODIFY creneau VARCHAR(5) NOT NULL');
    }
};
