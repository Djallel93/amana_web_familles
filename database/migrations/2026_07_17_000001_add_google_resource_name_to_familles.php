<?php
// database/migrations/2026_07_17_000001_add_google_resource_name_to_familles.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute familles.google_resource_name — identifiant "people/c..." renvoyé
 * par People API à la création du contact Google, réutilisé ensuite pour
 * les mises à jour (updateContact) au lieu d'une recherche floue par
 * nom/téléphone. Remplace l'ancienne intégration par webhook Make.com
 * (voir SynchroniserContactGoogle, ex-EnvoyerWebhookContact).
 *
 * Nullable : reste vide tant que le dossier n'a pas encore été validé une
 * première fois (aucun contact Google créé), ou si la synchronisation
 * People API n'est pas encore autorisée (cf. GoogleContactsService).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('familles', function (Blueprint $table) {
            $table->string('google_resource_name', 100)->nullable()->after('id_quartier')
                ->comment('resourceName Google People API (ex: people/c1234567890), défini après la 1ère synchronisation');
        });
    }

    public function down(): void
    {
        Schema::table('familles', function (Blueprint $table) {
            $table->dropColumn('google_resource_name');
        });
    }
};
