<?php
// database/seeders/BenevoleSeeder.php

declare(strict_types=1);

namespace Database\Seeders;

use Amana\Shared\Models\Secteur;
use Amana\Shared\Models\VehiculeType;
use App\Models\BenevoleProfil;
use App\Models\Personne;
use App\Services\RoleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeder de test — génère des bénévoles fictifs DIRECTEMENT VALIDÉS
 * (statut 'Validé' de bout en bout : Personne, BenevoleProfil, rôle
 * 'benevole' attribué), pour peupler l'écran Personnes et vérifier
 * l'affichage sans repasser par tout le parcours de candidature/
 * confirmation email à chaque fois.
 *
 * Pas de Factory dédiée (contrairement à FamilleFactory) : ce seeder est
 * auto-suffisant, dans le même esprit que OrganismeAideSeeder/
 * SecteurActiviteSeeder — pas assez de variations nécessaires pour
 * justifier une classe Factory séparée.
 *
 * Prérequis (données de référence, pas seedées ici) :
 *  - ref_vehicules doit être peuplé : php artisan db:seed
 *    --class="Amana\Shared\Database\Seeders\VehiculeTypesSeeder"
 *  - secteurs (idéalement) peuplé via GeoSeeder côté amana_shared — sans
 *    ça, les bénévoles sont créés sans couverture géographique (pas
 *    bloquant, juste moins réaliste pour tester l'écran de détail).
 *
 * Volontairement PAS appelé automatiquement par DatabaseSeeder::run() —
 * à lancer explicitement en dev :
 *
 *   php artisan db:seed --class=BenevoleSeeder
 */
class BenevoleSeeder extends Seeder
{
    private const NOMBRE_BENEVOLES = 20;

    public function __construct(
        private readonly RoleService $roleService,
    ) {
    }

    public function run(): void
    {
        $vehicules = VehiculeType::all();

        if ($vehicules->isEmpty()) {
            $this->command->error('❌ ref_vehicules est vide — lancez d\'abord : php artisan db:seed '
                . '--class="Amana\\Shared\\Database\\Seeders\\VehiculeTypesSeeder"');
            return;
        }

        $vehiculesAvecPermis = $vehicules->filter(fn($v) => $v->type !== 'Sans permis')->values();
        $vehiculeSansPermis = $vehicules->firstWhere('type', 'Sans permis');

        $secteurs = Secteur::pluck('id');
        if ($secteurs->isEmpty()) {
            $this->command->warn('⚠️  Table secteurs vide — les bénévoles seront créés sans couverture géographique '
                . '(voir GeoSeeder côté amana_shared si besoin d\'un jeu de données plus réaliste).');
        }

        $famillesApp = $this->roleService->famillesApp();
        if (!$famillesApp) {
            // Ne devrait plus arriver depuis le 27/08/2026 — voir la
            // migration 2026_08_27_000000_register_familles_application.php
            // (amana_web_familles), qui enregistre 'familles' automatiquement.
            $this->command->error('❌ Application "familles" introuvable dans ref_applications — vérifiez que '
                . '`php artisan amana:migrate-shared` PUIS `php artisan migrate` ont bien été exécutés.');
            return;
        }

        $crees = 0;

        for ($i = 0; $i < self::NOMBRE_BENEVOLES; $i++) {
            $prenom = fake()->firstName();
            $nom = fake()->lastName();
            // Suffixe aléatoire (pas seulement l'index de boucle) pour
            // rester unique même en relançant ce seeder plusieurs fois de
            // suite pendant une session de test.
            $email = Str::slug($prenom . '.' . $nom) . '-' . Str::random(4) . '@benevole-test.amana.local';

            $permis = fake()->boolean(75);

            $personne = Personne::create([
                'nom' => $nom,
                'prenom' => $prenom,
                'email' => $email,
                'telephone' => fake()->phoneNumber(),
                'password' => null,
                'statut' => 'Validé',
            ]);
            // email_verified_at n'est volontairement pas mass-assignable
            // (voir Personne::$fillable et le même commentaire dans
            // DatabaseSeeder) — affecté explicitement ici.
            $personne->email_verified_at = now();
            $personne->save();

            // Même cohérence permis ↔ véhicule que le formulaire public
            // (voir BenevoleForm.vue::watch(() => form.permis, ...)) :
            // sans permis → toujours "Sans permis", jamais un vrai véhicule.
            $vehicule = $permis
                ? $vehiculesAvecPermis->random()
                : $vehiculeSansPermis;

            $profil = BenevoleProfil::create([
                'id_personne' => $personne->id,
                'langue_preferee' => fake()->randomElement(['fr', 'fr', 'fr', 'ar', 'en']),
                'permis' => $permis,
                'id_vehicule_type' => $vehicule?->id ?? $vehicules->first()->id,
                'statut' => 'Validé',
            ]);

            if ($secteurs->isNotEmpty()) {
                $profil->secteurs()->sync(
                    $secteurs->random(min(fake()->numberBetween(1, 3), $secteurs->count()))->all(),
                );
            }

            $this->roleService->syncRoleFamilles($personne, 'benevole');

            $crees++;
        }

        $this->command->info("✅ {$crees} bénévoles de test créés et validés (rôle 'benevole' attribué).");
    }
}
