<?php
// database/migrations/2026_08_08_000000_add_rollback_columns_to_famille_imports.php
//
// Support du rollback d'import et de la synchronisation Google Contacts
// depuis l'écran de détail d'un import :
//   - famille_imports.rolled_back_at : marque l'import comme annulé
//     (idempotence — un import ne peut être annulé qu'une fois).
//   - famille_import_rows.id_famille : relie chaque ligne réussie à la
//     famille créée/mise à jour, indispensable pour cibler la synchro
//     Google Contacts et le rollback ligne par ligne (absent jusqu'ici —
//     seul le payload brut était conservé).
//   - famille_import_rows.cree : true = création, false = mise à jour d'un
//     doublon existant (voir FamilleUpsertService::upsert()) — détermine
//     l'action de rollback (suppression vs restauration).
//   - famille_import_rows.donnees_avant : snapshot de la famille avant mise
//     à jour (cree=false uniquement), pour restaurer sans dépendre de
//     audit_logs (plus simple, pas de risque de retrouver la mauvaise
//     entrée en cas d'imports/éditions concurrents sur la même famille).

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('famille_imports', function (Blueprint $table) {
            $table->timestamp('rolled_back_at')->nullable()->after('status');
        });

        Schema::table('famille_import_rows', function (Blueprint $table) {
            $table->foreignId('id_famille')->nullable()->after('row_number')
                ->constrained('familles')->nullOnDelete();
            $table->boolean('cree')->nullable()->after('id_famille')
                ->comment("true = création, false = mise à jour d'un doublon existant — null si status != success");
            $table->json('donnees_avant')->nullable()->after('cree')
                ->comment('Snapshot Famille avant mise à jour (cree=false) — permet le rollback');
        });
    }

    public function down(): void
    {
        Schema::table('famille_import_rows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_famille');
            $table->dropColumn(['cree', 'donnees_avant']);
        });

        Schema::table('famille_imports', function (Blueprint $table) {
            $table->dropColumn('rolled_back_at');
        });
    }
};
