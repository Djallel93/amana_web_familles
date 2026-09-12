<?php
// database/migrations/2026_08_31_000001_create_campagnes_domain_tables.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : domaine Livraison — Campagnes — squash du 10/09/2026
 * (Section D du refactor) de 7 migrations d'origine :
 *   - 2026_08_31_000001_create_campagnes_table.php
 *   - 2026_08_31_000100_add_poids_moyen_hotel_et_etudiant_to_campagnes.php
 *   - 2026_08_31_000003_create_campagne_journees_table.php (physiquement
 *     nommée ..._000003 mais réellement écrite le 03/09/2026, voir le
 *     commentaire de chemin qui y était resté périmé depuis un renommage
 *     antérieur — corrigé par la disparition même de ce fichier ici)
 *   - 2026_08_31_000002_create_campagne_arrivees_table.php
 *   - 2026_08_31_000003_create_donations_table.php
 *   - 2026_09_05_000001_create_campagne_poids_moyen_historiques_table.php
 *   - 2026_08_31_000012_create_campagne_stats_snapshots_table.php
 *
 * ORDRE CORRIGÉ par rapport aux migrations d'origine : campagne_journees
 * est créée ICI, avant campagne_arrivees/donations, plutôt qu'après (elle
 * avait été écrite le 03/09/2026, chronologiquement après ces deux tables
 * créées le 31/08/2026, d'où leur colonne id_campagne_journee d'origine
 * SANS contrainte FK — la table cible n'existait pas encore au moment où
 * ces migrations s'exécutaient). Cet ordre étant entièrement reconstruit
 * ici, campagne_arrivees.id_campagne_journee et donations.id_campagne_journee
 * reçoivent désormais une vraie contrainte FK vers campagne_journees.
 */
return new class extends Migration {
    public function up(): void
    {
        // Première table du domaine livraison (migration du 3ème et
        // dernier projet Google Apps Script, amana_livraison). Vit dans
        // amana_familles (connexion par défaut), comme familles — pas une
        // base séparée.
        //
        // `type` distingue les 3 natures de campagne (voir App\Models\
        // Campagne pour le détail métier de chacune) : zakat_el_fitr
        // (annuelle, toutes les familles éligibles, un seul jour),
        // collecte_alimentaire (ponctuelle, N familles sélectionnées par
        // l'admin), don_ponctuel (ad hoc, au fil de l'année).
        //
        // `nombre_menages` et `poids_collecte_kg` ne sont volontairement
        // PAS des colonnes stockées : ce sont des accesseurs calculés à la
        // volée (App\Models\Campagne::getNombreMenagesAttribute()/
        // getPoidsCollecteKgAttribute()), respectivement somme de
        // campagne_arrivees.nombre_donateur et somme de donations.poids_kg
        // pour cette campagne — décision du 31/08/2026 : à l'échelle de
        // l'association (une poignée de campagnes par an, quelques
        // centaines de lignes de log chacune), une valeur toujours exacte
        // par construction l'emporte sur l'économie d'une requête
        // SUM()/COUNT() à chaque lecture. Voir campagne_arrivees/donations
        // ci-après pour les tables sources.
        //
        // `poids_moyen_kg`/`poids_moyen_hotel_kg`/`poids_moyen_etudiant_kg`
        // restent, elles, de vraies colonnes : ce sont des paramètres
        // ajustables manuellement par l'admin/gestionnaire en cours de
        // campagne, pas des valeurs dérivées d'un journal. Deux taux
        // additionnels (hôtel/étudiant) découverts en portant le calcul de
        // poids depuis l'ancien système : `poidsLiv = parts * (estHotel ?
        // poids_moyen_hotel_kg : poids_moyen_kg)` — poids_moyen_etudiant_kg
        // n'existait pas dans l'ancien système (ajoutée pour anticiper une
        // différenciation future), même comportement de repli que
        // poids_moyen_hotel_kg (0 = repli sur poids_moyen_kg, voir
        // Livraison::calculerPoidsKg()). Cas famille à la fois étudiant ET
        // hôtel (les deux drapeaux sont indépendants sur Famille) : ne
        // JAMAIS choisir silencieusement un taux, traiter comme une
        // anomalie de données à signaler et exclure de la génération
        // automatique des livraisons (voir LivraisonGenerationService).
        Schema::create('campagnes', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['zakat_el_fitr', 'collecte_alimentaire', 'don_ponctuel']);
            $table->enum('statut', ['preparation', 'collecte', 'en_cours', 'terminee'])
                ->default('preparation');
            $table->date('date_livraison');
            $table->decimal('poids_moyen_kg', 6, 2)->default(0)
                ->comment('Poids moyen par colis, ajustable manuellement en cours de campagne — voir admin/gestionnaire');
            $table->decimal('poids_moyen_hotel_kg', 6, 2)->default(0)
                ->comment('Poids moyen PAR PERSONNE pour une famille est_hotel — 0 = repli sur poids_moyen_kg, voir Livraison::calculerPoidsKg()');
            $table->decimal('poids_moyen_etudiant_kg', 6, 2)->default(0)
                ->comment('Poids moyen PAR PERSONNE pour une famille etudiant — 0 = repli sur poids_moyen_kg, voir Livraison::calculerPoidsKg()');
            $table->timestamp('benevoles_notifies_le')->nullable()
                ->comment('Horodatage du dernier envoi de CampagneDisponibiliteNotification (voir BenevoleDisponibiliteService::notifierCampagne) — pour que la checklist de progression (CampagneProgressBar.vue) sache si cette étape a déjà eu lieu, sans avoir à deviner depuis les réponses des bénévoles (qui peuvent tarder ou ne jamais venir).');

            // HQ par campagne : le réglage global (route_hq_latitude/
            // route_hq_longitude, voir SettingsController::CLES_HQ) reste
            // la valeur par défaut — recopiée dans ces 3 colonnes à la
            // CRÉATION de chaque campagne (voir CampagnesController::store()),
            // pas relue dynamiquement ensuite : décision explicite
            // ("always prefill with it") pour qu'une campagne déjà créée
            // ne bouge jamais silencieusement si le réglage global change
            // plus tard — chaque campagne a sa propre valeur indépendante
            // dès sa création, éditable ensuite au cas par cas (ex:
            // collecte tenue dans un autre local qu'à l'accoutumée).
            $table->string('hq_adresse')->nullable()
                ->comment('Libellé adresse du HQ propre à cette campagne, pour affichage/log uniquement (pas de pendant global — settings ne stocke que lat/lng)');
            $table->decimal('hq_latitude', 10, 7)->nullable();
            $table->decimal('hq_longitude', 10, 7)->nullable();

            // NULL tant que le HQ de cette campagne n'est encore que la
            // copie silencieuse du réglage global faite à la création
            // (voir plus haut) et n'a jamais été revu par un humain pour
            // CETTE campagne précisément — posé à now() uniquement par
            // CampagnesController::update() (bouton "Confirmer"), jamais
            // par store(). Sert uniquement à CampagneDetail.vue pour
            // colorer la section HQ & commentaire (orange : hérité non
            // confirmé, rouge : aucun HQ du tout, ni saisi ni réglage
            // global, voir aussi hq_latitude/hq_longitude).
            $table->timestamp('hq_confirmee_le')->nullable()
                ->comment('Horodatage de la dernière confirmation explicite du HQ par un admin/gestionnaire sur CETTE campagne — NULL si le HQ vient encore uniquement du réglage global recopié à la création');

            // Cap par campagne — même logique que hq_* ci-dessus : le
            // réglage global (route_max_livraisons_par_route, voir
            // RouteOptimizationConfig) reste la valeur par défaut,
            // recopiée dans cette colonne à la CRÉATION de chaque campagne
            // (voir CampagnesController::store()), pas relue dynamiquement
            // ensuite — chaque campagne garde sa propre valeur
            // indépendante, éditable au cas par cas (ex: gros véhicules
            // disponibles ce jour-là), sans jamais bouger le réglage
            // global ni les campagnes déjà créées.
            $table->unsignedInteger('livraisons_max_par_tournee')->nullable()
                ->comment('Cap propre à cette campagne — préremplie depuis route_max_livraisons_par_route à la création, voir RouteOptimizationConfig::maxLivraisonsParRoutePourCampagne()');

            // Commentaire libre — dernière valeur seulement, pas
            // d'historique (décision explicite) : simple colonne texte,
            // éditable à tout moment depuis la page détail de la
            // campagne.
            $table->text('commentaire')->nullable();

            $table->timestamps();

            $table->index(['type', 'statut']);
        });

        // Une campagne peut s'étaler sur plusieurs journées de
        // collecte/livraison (ex: collecte alimentaire collectée le jour
        // J, livrée à J+1 ou J+2 ; zakat el-fitr où un jour de collecte
        // supplémentaire peut être décidé en cours de campagne, l'après-
        // midi même, s'il reste un jour de ramadan). UNE campagne,
        // PLUSIEURS journées — plutôt qu'une nouvelle campagne par jour —
        // pour garder un seul jeu de familles/contacts/stats par opération
        // (voir Campagne::getNombreMenagesAttribute() etc., qui
        // resteraient éclatés entre campagnes sinon).
        //
        // `campagnes.date_livraison` reste en base (voir plus haut),
        // réinterprétée comme date de RÉFÉRENCE (première journée,
        // utilisée pour le tri/affichage existant) plutôt que la seule
        // date de l'opération — voir Campagne::premiereJournee()/
        // syncDateReference(). Ne pas la supprimer évite de casser tout le
        // code existant qui la lit encore (tri des listes, exports, etc.)
        // ; les écrans qui doivent raisonner "quel jour" passent par
        // journees() désormais.
        //
        // `label` optionnel : sert à distinguer les journées d'une même
        // opération (ex: "Collecte", "Livraison", ou "Jour ajouté" pour le
        // cas zakat el-fitr ci-dessus) — affiché dans les onglets du
        // tableau de bord livraison, purement informatif, aucune logique
        // n'en dépend.
        //
        // Créée ICI, avant campagne_arrivees/donations (voir docblock de
        // fichier) : les deux peuvent donc pointer vers elle avec une
        // vraie contrainte FK plutôt que la colonne libre sans contrainte
        // des migrations d'origine.
        Schema::create('campagne_journees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->date('date');
            $table->string('label', 100)->nullable();
            $table->unsignedTinyInteger('ordre')
                ->comment('Ordre d\'affichage/chronologique au sein de la campagne — pas forcément égal au tri par date si une journée est ajoutée après coup avec une date antérieure à une correction ultérieure');
            $table->timestamps();

            $table->unique(['id_campagne', 'date']);
            $table->index(['id_campagne', 'ordre']);
        });

        // Journal de comptage des DONATEURS au poste "parking" (pas des
        // familles bénéficiaires). Chaque ligne représente une tape "+1"
        // faite par la personne qui tient le poste au fil des arrivées, au
        // moment où elles se produisent — pas un chiffre absolu ressaisi a
        // posteriori.
        //
        // `nombre_donateur` (et non `nombre_foyers` ou un simple +1
        // implicite) : pour collecte_alimentaire/don_ponctuel c'est
        // presque toujours 1 (un donateur = un foyer), mais pour
        // zakat_el_fitr une seule personne peut apporter la zakat el-fitr
        // de son propre foyer ET de voisins/proches qu'elle représente —
        // le poste doit donc pouvoir enregistrer combien de donateurs
        // cette arrivée représente, pas juste "encore une voiture".
        // campagnes.nombre_menages = somme de cette colonne pour la
        // campagne.
        //
        // Aucune identité de donateur n'est enregistrée (décision
        // explicite : "nous n'enregistrons pas qui a donné quoi") —
        // logge_par identifie uniquement le membre du staff qui tient le
        // poste, jamais le donateur. Aucun lien vers familles/livraisons :
        // ce journal est entièrement du côté "entrée" (dons), complètement
        // indépendant du côté "sortie" (livraisons aux familles
        // bénéficiaires).
        //
        // id_campagne_journee nullable : la réception reste facultative
        // par campagne.
        Schema::create('campagne_arrivees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->foreignId('id_campagne_journee')->nullable()
                ->constrained('campagne_journees')->nullOnDelete();
            $table->unsignedSmallInteger('nombre_donateur')->default(1)
                ->comment('Nombre de donateurs représentés par cette arrivée — 1 en général, >1 pour zakat_el_fitr (une personne couvrant plusieurs foyers)');
            $table->timestamp('horodatage')->useCurrent();
            // ref_personnes.id est un increments() (unsigned INT), pas un
            // id() Laravel standard — pas de contrainte FK : commun est
            // une base séparée.
            $table->unsignedInteger('logge_par')
                ->comment('ref_personnes.id du membre du staff tenant le poste — pas de FK, commun est une base séparée');

            $table->index('id_campagne');
            $table->index('id_campagne_journee');
        });

        // Journal de pesée au poste "entrée QG", après transport de la
        // nourriture depuis le parking (voir campagne_arrivees pour le
        // comptage des donateurs, poste distinct et non lié à celui-ci).
        // Une ligne = un passage à la pesée, poids total unique — PAS de
        // ventilation par catégorie (riz/farine/bonbons/hygiène...) : le
        // tri physique se fait sans relevé numérique par catégorie.
        //
        // campagnes.poids_collecte_kg = somme de poids_kg pour la
        // campagne — calculé à la volée, pas stocké. Même raisonnement
        // que campagne_arrivees pour logge_par : identifie uniquement le
        // membre du staff qui pèse, jamais le donateur.
        //
        // Une campagne pouvant s'étaler sur plusieurs journées, chaque
        // pesée doit être rattachée à CELLE pour laquelle elle a été
        // saisie (sélecteur en haut de l'écran pesée), pas seulement à la
        // campagne. Nullable : la pesée reste une étape facultative par
        // campagne.
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->foreignId('id_campagne_journee')->nullable()
                ->constrained('campagne_journees')->nullOnDelete();
            $table->decimal('poids_kg', 7, 2);
            $table->timestamp('horodatage')->useCurrent();
            $table->unsignedInteger('logge_par')
                ->comment('ref_personnes.id du membre du staff tenant le poste — pas de FK, commun est une base séparée');

            $table->index('id_campagne');
            $table->index(['id_campagne_journee']);
        });

        // Journal des modifications de poids_moyen_kg/poids_moyen_hotel_kg/
        // poids_moyen_etudiant_kg sur une campagne. Ces colonnes restent de
        // vrais paramètres ajustables à la main en cours de campagne (voir
        // plus haut), mais Livraison::calculerPoidsKg() ne s'exécutant
        // qu'UNE FOIS à la génération (poids_kg est un instantané, jamais
        // recalculé automatiquement), rien ne permettait jusqu'ici de
        // savoir QUAND/DE COMBIEN un taux a changé en cours de route —
        // nécessaire pour comprendre pourquoi deux lots de familles
        // générés à des moments différents de la même campagne peuvent
        // porter des poids différents.
        //
        // Une ligne par modification effective (voir
        // CampagnesController::mettreAJourPoidsMoyen() : rien n'est écrit
        // si la valeur soumise est identique à l'existante). Pas de lien
        // vers les livraisons concernées : ce journal documente
        // l'évolution du PARAMÈTRE, pas quelles livraisons ont été
        // générées sous quel taux (cette information est de toute façon
        // déjà portée par livraisons.poids_kg lui-même, figé à la
        // génération).
        Schema::create('campagne_poids_moyen_historiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->enum('type', ['normal', 'hotel', 'etudiant'])
                ->comment('À quelle colonne ce changement se rapporte : poids_moyen_kg (normal), poids_moyen_hotel_kg ou poids_moyen_etudiant_kg');
            $table->decimal('ancienne_valeur', 6, 2);
            $table->decimal('nouvelle_valeur', 6, 2);
            $table->timestamp('horodatage')->useCurrent();
            $table->unsignedInteger('logge_par')
                ->comment('ref_personnes.id de la personne ayant modifié le taux — pas de FK, commun est une base séparée');

            $table->index(['id_campagne', 'horodatage']);
        });

        // NOTE : cette table n'était pas nommée explicitement dans le
        // prompt d'origine du 30/08/2026, mais en découlait directement
        // (§3.5 : "snapshotted/stored as their own record ... not just
        // computed live and lost"). Forme retenue : un blob JSON `donnees`
        // horodaté plutôt que des colonnes figées — des colonnes dédiées
        // pourront être extraites plus tard si un besoin de requêtage SQL
        // direct sur une métrique précise apparaît.
        //
        // Une campagne peut avoir plusieurs snapshots dans le temps
        // (conclusion + captures périodiques en cours de campagne) — pas
        // de contrainte d'unicité par campagne.
        Schema::create('campagne_stats_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_campagne')->constrained('campagnes')->cascadeOnDelete();
            $table->timestamp('snapshot_at')->useCurrent();
            $table->json('donnees')
                ->comment('Métriques calculées au moment du snapshot — forme provisoire, voir docblock de migration');
            $table->timestamps();

            $table->index('id_campagne');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campagne_stats_snapshots');
        Schema::dropIfExists('campagne_poids_moyen_historiques');
        Schema::dropIfExists('donations');
        Schema::dropIfExists('campagne_arrivees');
        Schema::dropIfExists('campagne_journees');
        Schema::dropIfExists('campagnes');
    }
};
