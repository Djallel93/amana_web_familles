<?php
// database/seeders/SecteurActiviteSeeder.php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SecteurActivite;
use Illuminate\Database\Seeder;

/**
 * Reprend la liste fermée "Dans quel secteur travaillez-vous ?"
 * (CHECKBOX + "Autre") du Google Form historique — identique sur les 3
 * langues (formulaire_famille_fr/en/ar.json, itemId 67130abd). "Autre" n'est
 * volontairement pas une entrée ici : c'est le champ texte libre
 * familles.secteur_activite_autre.
 */
class SecteurActiviteSeeder extends Seeder
{
    public function run(): void
    {
        $secteurs = [
            ['code' => 'marche', 'libelle_fr' => 'Marché', 'libelle_ar' => 'سوق', 'libelle_en' => 'Market', 'ordre' => 1],
            ['code' => 'livraison', 'libelle_fr' => 'Livraison', 'libelle_ar' => 'توصيل', 'libelle_en' => 'Delivery', 'ordre' => 2],
            ['code' => 'batiment', 'libelle_fr' => 'Bâtiment', 'libelle_ar' => 'البناء', 'libelle_en' => 'Construction', 'ordre' => 3],
            ['code' => 'restauration', 'libelle_fr' => 'Restauration', 'libelle_ar' => 'مطاعم', 'libelle_en' => 'Catering / Food Service', 'ordre' => 4],
            ['code' => 'administratif', 'libelle_fr' => 'Administratif', 'libelle_ar' => 'إداري', 'libelle_en' => 'Administration', 'ordre' => 5],
            ['code' => 'nettoyage_entretien', 'libelle_fr' => 'Nettoyage/Entretien', 'libelle_ar' => 'تنظيف / صيانة', 'libelle_en' => 'Cleaning / Maintenance', 'ordre' => 6],
        ];

        foreach ($secteurs as $secteur) {
            SecteurActivite::firstOrCreate(['code' => $secteur['code']], $secteur);
        }
    }
}
