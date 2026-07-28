<?php
// database/seeders/FamilleSeeder.php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Famille;
use Illuminate\Database\Seeder;

/**
 * Seeder de test — 10 dossiers familles fictifs (locale fr_FR) pour
 * vérifier visuellement le tableau et le panneau de détail avant l'import
 * réel des 130 familles existantes (décision 6.8 — toujours différé).
 *
 * Volontairement PAS appelé automatiquement par DatabaseSeeder::run() (pas
 * de risque de semer des données fictives en production par erreur) —
 * à lancer explicitement en dev :
 *
 *   php artisan db:seed --class=FamilleSeeder
 */
class FamilleSeeder extends Seeder
{
    public function run(): void
    {
        // 8 dossiers avec une criticité aléatoire...
        Famille::factory()->count(8)->create();

        // ...et 2 dossiers volontairement critiques (criticité 4-5), pour
        // vérifier le style rouge du tableau sans compter sur le hasard.
        Famille::factory()->critique()->count(2)->create();
    }
}
