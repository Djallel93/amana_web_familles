<?php
// database/migrations/2026_08_31_000100_add_poids_moyen_hotel_et_etudiant_to_campagnes.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute poids_moyen_hotel_kg et poids_moyen_etudiant_kg à campagnes —
 * migration ADDITIVE distincte de 2026_08_31_000001_create_campagnes_table.php
 * (déjà livrée dans le Patch 1), plutôt qu'une modification de cette
 * migration existante, par précaution si elle a déjà été appliquée.
 *
 * Découverte en préparant le Patch 2, en portant le calcul de poids depuis
 * routeAssignmentService.js/routeClusteringService.js (amana_livraison) :
 * l'ancien système utilise DEUX taux de poids par personne, pas un seul —
 * poids_moyen_kg (domicile) et poids_moyen_hotel_kg (hôtel) — voir
 * `poidsLiv = parts * (estHotel ? poids_moyen_hotel_kg : poids_moyen_kg)`.
 * Le Patch 1 n'avait modélisé que poids_moyen_kg ; poids_moyen_hotel_kg
 * comble cet oubli.
 *
 * poids_moyen_etudiant_kg n'existe PAS dans l'ancien système (qui ne
 * différenciait le poids que pour l'hôtel, pas pour les étudiants) —
 * ajoutée à la demande explicite du 31/08/2026 pour anticiper une
 * différenciation future, avec le même comportement de repli que
 * poids_moyen_hotel_kg (voir Livraison::calculerPoidsKg()) : si laissée à
 * 0, on retombe sur poids_moyen_kg.
 *
 * Cas famille à la fois étudiant ET hôtel (les deux drapeaux sont
 * indépendants sur Famille) : décision du 31/08/2026 — ne JAMAIS choisir
 * silencieusement un taux, traiter comme une anomalie de données à
 * signaler et exclure de la génération automatique des livraisons (voir
 * LivraisonGenerationService).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('campagnes', function (Blueprint $table) {
            $table->decimal('poids_moyen_hotel_kg', 6, 2)->default(0)->after('poids_moyen_kg')
                ->comment('Poids moyen PAR PERSONNE pour une famille est_hotel — 0 = repli sur poids_moyen_kg, voir Livraison::calculerPoidsKg()');
            $table->decimal('poids_moyen_etudiant_kg', 6, 2)->default(0)->after('poids_moyen_hotel_kg')
                ->comment('Poids moyen PAR PERSONNE pour une famille etudiant — 0 = repli sur poids_moyen_kg, voir Livraison::calculerPoidsKg()');
        });
    }

    public function down(): void
    {
        Schema::table('campagnes', function (Blueprint $table) {
            $table->dropColumn(['poids_moyen_hotel_kg', 'poids_moyen_etudiant_kg']);
        });
    }
};
