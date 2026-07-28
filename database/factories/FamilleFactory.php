<?php
// database/factories/FamilleFactory.php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Famille;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory de test pour Famille — génère des dossiers fictifs plausibles
 * (locale fr_FR, cf. APP_FAKER_LOCALE dans .env.example) pour vérifier
 * visuellement le tableau et le panneau de détail avant l'import réel des
 * 130 familles existantes (décision 6.8 — toujours différé).
 *
 * id_quartier volontairement toujours null : les tables villes/secteurs/
 * quartiers restent vides à ce stade (décision 6.7), donc aucune donnée de
 * test ne doit s'appuyer dessus. ville_texte simule la saisie brute d'une
 * famille, comme le ferait le vrai formulaire d'intake avant résolution.
 */
class FamilleFactory extends Factory
{
    protected $model = Famille::class;

    public function definition(): array
    {
        $nombreAdulte = fake()->numberBetween(1, 2);
        $nombreEnfant = fake()->numberBetween(0, 6);

        return [
            'nom' => fake()->lastName(),
            'prenom' => fake()->firstName(),
            'email' => fake()->boolean(70) ? fake()->safeEmail() : null,
            'telephone' => '06' . fake()->numerify('########'),
            'telephone_bis' => fake()->boolean(25) ? '07' . fake()->numerify('########') : null,

            'zakat_el_fitr' => fake()->boolean(75),
            'sadaqa' => fake()->boolean(40),

            'nombre_adulte' => $nombreAdulte,
            'nombre_enfant' => $nombreEnfant,

            'adresse' => fake()->streetAddress(),
            'code_postal' => fake()->randomElement(['44000', '44100', '44200', '44300', '44600']),
            'ville_texte' => fake()->randomElement(['Nantes', 'Saint-Nazaire', 'Rezé', 'Couëron', 'Saint-Herblain']),
            'id_quartier' => null,
            'se_deplace' => fake()->boolean(60),

            'circonstances' => fake()->boolean(60) ? fake()->realText(120) : null,
            'ressentit' => fake()->boolean(40) ? fake()->realText(80) : null,
            'specificites' => fake()->boolean(30) ? fake()->realText(60) : null,
            'criticite' => fake()->numberBetween(0, 5),
            'langue' => fake()->randomElement(['fr', 'ar', 'en']),
            'etat_dossier' => fake()->randomElement(Famille::ETATS),
            'commentaire_dossier' => fake()->boolean(30) ? fake()->realText(100) : null,

            'hosted' => fake()->boolean(15),
            'hosted_by' => null,
            'working' => fake()->boolean(35),
            'work_days' => null,
            'work_sector' => null,
            'other_aid' => fake()->boolean(20),
        ];
    }

    /**
     * État pratique pour forcer une criticité élevée — utile pour vérifier
     * visuellement le style rouge du tableau (criticité ≥ 4).
     */
    public function critique(): static
    {
        return $this->state(fn() => ['criticite' => fake()->numberBetween(4, 5)]);
    }
}
