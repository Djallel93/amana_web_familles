<?php
// database/migrations/2026_08_31_000001_create_campagnes_table.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table campagnes.
 *
 * Première table du domaine livraison (migration du 3ème et dernier
 * projet Google Apps Script, amana_livraison, voir le prompt du
 * 30/08/2026 pour le contexte complet). Vit dans amana_familles (connexion
 * par défaut), comme familles — pas une base séparée.
 *
 * `type` distingue les 3 natures de campagne (voir App\Models\Campagne
 * pour le détail métier de chacune) : zakat_el_fitr (annuelle, toutes les
 * familles éligibles, un seul jour), collecte_alimentaire (ponctuelle, N
 * familles sélectionnées par l'admin), don_ponctuel (ad hoc, au fil de
 * l'année).
 *
 * `nombre_menages` et `poids_collecte_kg` ne sont volontairement PAS des
 * colonnes stockées : ce sont des accesseurs calculés à la volée
 * (App\Models\Campagne::getNombreMenagesAttribute()/
 * getPoidsCollecteKgAttribute()), respectivement somme de
 * campagne_arrivees.nombre_donateur et somme de donations.poids_kg pour
 * cette campagne — décision du 31/08/2026 : à l'échelle de l'association
 * (une poignée de campagnes par an, quelques centaines de lignes de log
 * chacune), une valeur toujours exacte par construction l'emporte sur
 * l'économie d'une requête SUM()/COUNT() à chaque lecture. Voir
 * campagne_arrivees/donations ci-après pour les tables sources.
 *
 * `poids_moyen_kg` reste, elle, une vraie colonne : c'est un paramètre
 * ajustable manuellement par l'admin/gestionnaire en cours de campagne
 * (poids moyen par colis, réévalué à la main selon le rythme des dons
 * entrants vs. le nombre de familles restant à livrer), pas une valeur
 * dérivée d'un journal.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('campagnes', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['zakat_el_fitr', 'collecte_alimentaire', 'don_ponctuel']);
            $table->enum('statut', ['preparation', 'collecte', 'en_cours', 'terminee'])
                ->default('preparation');
            $table->date('date_livraison');
            $table->decimal('poids_moyen_kg', 6, 2)->default(0)
                ->comment('Poids moyen par colis, ajustable manuellement en cours de campagne — voir admin/gestionnaire');
            $table->timestamp('benevoles_notifies_le')->nullable()
                ->comment('Horodatage du dernier envoi de CampagneDisponibiliteNotification (voir BenevoleDisponibiliteService::notifierCampagne) — ajouté le 03/09/2026 uniquement pour que la checklist de progression (CampagneProgressBar.vue) sache si cette étape a déjà eu lieu, sans avoir à deviner depuis les réponses des bénévoles (qui peuvent tarder ou ne jamais venir).');

            // HQ par campagne (ajouté le 05/09/2026, prompt §1.2) : le
            // réglage global (route_hq_latitude/route_hq_longitude, voir
            // SettingsController::CLES_HQ) reste la valeur par défaut —
            // recopiée dans ces 3 colonnes à la CRÉATION de chaque
            // campagne (voir CampagnesController::store()), pas relue
            // dynamiquement ensuite : décision explicite du 05/09/2026
            // ("always prefill with it") pour qu'une campagne déjà créée
            // ne bouge jamais silencieusement si le réglage global change
            // plus tard — chaque campagne a sa propre valeur indépendante
            // dès sa création, éditable ensuite au cas par cas (ex:
            // collecte tenue dans un autre local qu'à l'accoutumée).
            $table->string('hq_adresse')->nullable()
                ->comment('Libellé adresse du HQ propre à cette campagne, pour affichage/log uniquement (pas de pendant global — settings ne stocke que lat/lng)');
            $table->decimal('hq_latitude', 10, 7)->nullable();
            $table->decimal('hq_longitude', 10, 7)->nullable();

            // Commentaire libre (ajouté le 05/09/2026, prompt §1.3) —
            // dernière valeur seulement, pas d'historique (décision
            // explicite) : simple colonne texte, éditable à tout moment
            // depuis la page détail de la campagne.
            $table->text('commentaire')->nullable();

            $table->timestamps();

            $table->index(['type', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campagnes');
    }
};
