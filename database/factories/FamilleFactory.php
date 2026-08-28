<?php
// database/factories/FamilleFactory.php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Famille;
use App\Models\OrganismeAide;
use App\Models\SecteurActivite;
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
 *
 * Corrigée le 28/08/2026 : hosted/working/work_sector/other_aid n'existent
 * plus dans la table familles depuis la refonte du formulaire (voir
 * create_familles_table) — remplacés par type_hebergement/type_activite
 * (enums) et par les relations secteursActivite()/organismesAide()
 * (pivots), jamais mis à jour ici jusqu'à ce que FamilleSeeder échoue.
 */
class FamilleFactory extends Factory
{
    protected $model = Famille::class;

    public function definition(): array
    {
        $nombreAdulte = fake()->numberBetween(1, 2);
        $nombreEnfant = fake()->numberBetween(0, 6);

        // Même logique conditionnelle que IntakeController::store() :
        // hosted_by n'a de sens que si hébergé par une organisation,
        // work_days que pour un temps partiel.
        $typeHebergement = fake()->boolean(15) ? fake()->randomElement(['organisation', 'proche']) : 'non';
        $typeActivite = fake()->boolean(35) ? fake()->randomElement(['temps_plein', 'temps_partiel']) : 'non';

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
            'est_hotel' => fake()->boolean(10),
            'etudiant' => fake()->boolean(20),

            'circonstances' => fake()->boolean(60) ? fake()->realText(120) : null,
            'ressentit' => fake()->boolean(40) ? fake()->realText(80) : null,
            'specificites' => fake()->boolean(30) ? fake()->realText(60) : null,
            'criticite' => fake()->numberBetween(0, 5),
            'langue' => fake()->randomElement(['fr', 'ar', 'en']),
            'etat_dossier' => fake()->randomElement(Famille::ETATS),
            'commentaire_dossier' => fake()->boolean(30) ? fake()->realText(100) : null,

            'type_piece_identite' => fake()->randomElement(Famille::TYPES_PIECE_IDENTITE),

            'type_hebergement' => $typeHebergement,
            'hosted_by' => $typeHebergement === 'organisation' ? fake()->company() : null,

            'type_activite' => $typeActivite,
            'work_days' => $typeActivite === 'temps_partiel' ? fake()->numberBetween(1, 4) : null,
            'secteur_activite_autre' => fake()->boolean(10) ? fake()->jobTitle() : null,

            'organisme_aide_autre' => fake()->boolean(10) ? fake()->company() : null,
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

    /**
     * Attache 0-2 secteurs d'activité / organismes d'aide réels si les
     * référentiels sont peuplés (SecteurActiviteSeeder/OrganismeAideSeeder)
     * — remplace les anciennes colonnes booléennes work_sector/other_aid,
     * qui n'existaient plus dans la table (voir docblock de classe).
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Famille $famille) {
            $secteurs = SecteurActivite::inRandomOrder()->limit(fake()->numberBetween(0, 2))->pluck('id');
            if ($secteurs->isNotEmpty()) {
                $famille->secteursActivite()->sync($secteurs);
            }

            $organismes = OrganismeAide::inRandomOrder()->limit(fake()->numberBetween(0, 2))->pluck('id');
            if ($organismes->isNotEmpty()) {
                $famille->organismesAide()->sync($organismes);
            }
        });
    }
}
