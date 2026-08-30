<?php
// database/seeders/HotelAddressSeeder.php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\HotelAddress;
use Illuminate\Database\Seeder;

/**
 * Liste des adresses d'hébergement d'urgence (hôtels/appart-hôtels)
 * connues du staff, fournie le 30/08/2026 — voir migration
 * create_hotel_addresses_table pour l'usage (force `est_hotel` à true).
 *
 * Reproduite telle que fournie, sans correction : plusieurs entrées pour
 * un même établissement sont volontaires (adresse préfixée du nom de
 * l'établissement ET adresse brute pour le même lieu, ou plusieurs
 * adresses réelles pour un même hôtel). Les deux lignes "Rue de la
 * Johardière" avec code postal 44300 ont probablement une coquille (Saint-
 * Herblain est en 44800, comme les deux lignes équivalentes juste en
 * dessous) — laissées telles quelles à la demande du 30/08/2026, à
 * corriger manuellement depuis l'écran Paramètres si besoin.
 */
class HotelAddressSeeder extends Seeder
{
    public function run(): void
    {
        $adresses = [
            "Appart'City Classic Nantes Carquefou - Appart Hôtel - salles de réunion, 44470 Carquefou",
            '1A Rue Albert Einstein, 44340 Bouguenais',
            '1 Rue Albert Einstein, 44340 Bouguenais',
            'Appart Hôtel - Séjours & Affaires Nantes La Beaujoire, 44300 Nantes',
            'Appart Hôtel - Residhome Nantes Berges de la Loire, 44000 Nantes',
            "Appart'city 13 rue de la johardière st Herblain",
            'hôtel cerise 12 rue de la johardière St Herblain',
            '23/25 Rue du Chemin Rouge, 44300 Nantes',
            '2 Rue des Citrines, 44300 Nantes',
            '345 Rte de Sainte-Luce, 44303 Nantes',
            '5 Rue du Patis Rondin, 44200 Nantes',
            '13 Rue de la Johardière, 44800 Saint-Herblain',
            '12 Rue de la Johardière, 44800 Saint-Herblain',
            'Zenitude Hôtel-Résidences Nantes Métropole, 44300 Nantes',
        ];

        foreach ($adresses as $adresse) {
            HotelAddress::firstOrCreate([
                'adresse_normalisee' => HotelAddress::normaliser($adresse),
            ], [
                'adresse' => $adresse,
            ]);
        }
    }
}
