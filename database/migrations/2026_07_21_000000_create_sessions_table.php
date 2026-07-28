<?php
// database/migrations/2026_07_21_000000_create_sessions_table.php
//
// Familles n'avait pas sa propre table sessions — elle comptait sur le fait
// de se connecter à la MÊME base physique que amana_web_planning (couplage
// que cette migration vers amana/shared élimine justement). Avec sa propre
// base amana_familles, cette table doit exister ici. Structure identique au
// stub Laravel 11/13 officiel — voir la même migration côté amana_web_planning.
//
// IMPORTANT — à propos du commentaire "SSO" trouvé dans l'ancien
// config/auth.php et .env.production.template de cette app : partager
// ref_personnes/ref_roles (même identifiants, mêmes rôles) permet de se
// CONNECTER avec le même compte sur les deux apps, mais chaque app garde
// sa PROPRE session/cookie (tables sessions séparées, comme ci-dessous) —
// ce n'est donc pas du single-sign-on au sens strict (une session ouverte
// sur une app ne connecte pas automatiquement l'autre). Un vrai SSO
// nécessiterait un store de session partagé + un domaine de cookie commun
// (ex. .amana-nantes.fr) — non traité par cette migration, à cadrer
// séparément si souhaité.

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
