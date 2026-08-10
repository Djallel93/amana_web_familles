<?php
// database/seeders/OrganismeAideSeeder.php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\OrganismeAide;
use Illuminate\Database\Seeder;

/**
 * Reprend la liste fermée "Percevez-vous actuellement des aides d'autres
 * organismes ?" (CHECKBOX + "Autre") du Google Form historique — identique
 * sur les 3 langues (formulaire_famille_fr/en/ar.json, itemId 5ace5ea4).
 * "Association" reste générique dans le formulaire d'origine (pas
 * d'organisation nommée) ; "Secours Populaire" est resté en français même
 * dans la version arabe du formulaire d'origine, reproduit tel quel ici.
 * "Autre" n'est pas une entrée : c'est familles.organisme_aide_autre.
 */
class OrganismeAideSeeder extends Seeder
{
    public function run(): void
    {
        $organismes = [
            ['code' => 'association', 'libelle_fr' => 'Association', 'libelle_ar' => 'جمعية', 'libelle_en' => 'Association', 'ordre' => 1],
            ['code' => 'restos_du_coeur', 'libelle_fr' => 'Les Restos du Cœur', 'libelle_ar' => 'Les Restos du Cœur', 'libelle_en' => 'Les Restos du Cœur', 'ordre' => 2],
            ['code' => 'croix_rouge', 'libelle_fr' => 'Croix rouge', 'libelle_ar' => 'الصليب الأحمر', 'libelle_en' => 'Red Cross', 'ordre' => 3],
            ['code' => 'secours_populaire', 'libelle_fr' => 'Secours Populaire', 'libelle_ar' => 'الإسعاف الشعبي (Secours Populaire)', 'libelle_en' => 'Secours Populaire', 'ordre' => 4],
        ];

        foreach ($organismes as $organisme) {
            OrganismeAide::firstOrCreate(['code' => $organisme['code']], $organisme);
        }
    }
}
