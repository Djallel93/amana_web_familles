<?php
// database/seeders/LivraisonDemoSeeder.php

declare(strict_types=1);

namespace Database\Seeders;

use Amana\Shared\Database\Seeders\VehiculeTypesSeeder;
use Amana\Shared\Models\VehiculeType;
use App\Models\BenevoleProfil;
use App\Models\Campagne;
use App\Models\Famille;
use App\Models\Livraison;
use App\Services\BenevoleDisponibiliteService;
use App\Services\LivraisonGenerationService;
use App\Support\Creneau;
use Illuminate\Database\Seeder;

/**
 * Seeder de démo — domaine livraison/campagne : une campagne
 * collecte_alimentaire avec familles éligibles + livraisons générées, et
 * un pool de bénévoles/chauffeurs à la disponibilité CONFIRMÉE, pour
 * démontrer l'écran campagne-detail (sélection/génération de livraisons,
 * suivi des contacts) sans repasser par tout le flux de candidature/
 * notification bénévole à chaque fois.
 *
 * Compose plutôt que duplique — réutilise :
 *  - Famille::factory() (FamilleFactory), avec etat_dossier forcé à
 *    'Validé' — seule condition d'éligibilité de
 *    LivraisonGenerationService::eligibles() ;
 *  - LivraisonGenerationService::genererPour(), le même service que
 *    CampagnesController::genererLivraisons() (Admin), pour générer les
 *    lignes Livraison (snapshot nombre_personnes/poids_kg inclus) ;
 *  - BenevoleSeeder pour les bénévoles/profils/rôle 'benevole' ;
 *  - BenevoleDisponibiliteService::confirmer(), le même service que
 *    l'écran public de confirmation bénévole, pour la disponibilité
 *    confirmée par campagne (vehicule_confirme + créneaux).
 *
 * Prérequis : ref_vehicules peuplé (VehiculeTypesSeeder, côté
 * amana_shared) — appelé automatiquement ici s'il est vide, même
 * garde-fou que BenevoleSeeder (qui échoue sinon, lui, sans le peupler).
 *
 * Volontairement PAS appelé automatiquement par DatabaseSeeder::run() —
 * même convention que FamilleSeeder/BenevoleSeeder/HotelAddressSeeder
 * (données fictives, jamais semées en production par erreur) :
 *
 *   php artisan db:seed --class=LivraisonDemoSeeder
 */
class LivraisonDemoSeeder extends Seeder
{
    private const NOMBRE_FAMILLES_ELIGIBLES = 12;
    private const NOMBRE_FAMILLES_NON_ELIGIBLES = 4;
    private const NOMBRE_CHAUFFEURS_CONFIRMES = 10;

    /**
     * Statuts de contact appliqués en rotation aux livraisons générées,
     * pour un rendu de démo réaliste (sinon toutes resteraient
     * 'a_contacter', l'état initial jamais choisi manuellement — voir
     * Livraison::STATUTS_CONTACT_POSTABLES). Pondéré vers 'confirme'
     * (majorité) pour laisser aussi de la matière à une démo du
     * clustering/assignation en aval.
     */
    private const STATUTS_CONTACT_DEMO = ['confirme', 'confirme', 'confirme', 'contacte', 'contacte', 'injoignable'];

    public function __construct(
        private readonly LivraisonGenerationService $generationService,
        private readonly BenevoleDisponibiliteService $disponibiliteService,
    ) {
    }

    public function run(): void
    {
        if (VehiculeType::count() === 0) {
            $this->call([VehiculeTypesSeeder::class]);
        }

        $campagne = $this->creerCampagne();
        $journee = $campagne->ajouterJournee(now()->addDays(7), 'Livraison');
        $campagne->ajouterJournee(now()->addDays(8), 'Livraison (jour 2)');

        $livraisons = $this->genererLivraisons($campagne, $journee);
        $this->varierStatutsContact($livraisons);

        $this->command->info("✅ Campagne #{$campagne->id} ({$campagne->type}) créée avec "
            . "{$livraisons->count()} livraisons générées sur 2 journées.");

        $this->confirmerChauffeurs($campagne);
    }

    /**
     * Campagne 'collecte_alimentaire' (ponctuelle, familles sélectionnées
     * par l'admin — voir Campagne, docblock de classe), statut 'collecte'
     * pour refléter une opération en cours de préparation/confirmation
     * plutôt qu'un état 'terminee' qui figerait la démo.
     */
    private function creerCampagne(): Campagne
    {
        return Campagne::create([
            'type' => 'collecte_alimentaire',
            'statut' => 'collecte',
            'date_livraison' => now()->addDays(7)->toDateString(),
            'poids_moyen_kg' => 8.5,
            'poids_moyen_hotel_kg' => 5,
            'poids_moyen_etudiant_kg' => 4,
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Livraison>
     */
    private function genererLivraisons(Campagne $campagne, \App\Models\CampagneJournee $journee): \Illuminate\Support\Collection
    {
        // Dossiers 'Validé' — 2 volontairement critiques (criticité 4-5),
        // même logique que FamilleSeeder, pour vérifier le tri par
        // criticité décroissante de LivraisonGenerationService::eligibles()
        // sur l'écran de sélection.
        $famillesEligibles = Famille::factory()
            ->count(self::NOMBRE_FAMILLES_ELIGIBLES - 2)
            ->create(['etat_dossier' => 'Validé']);

        $famillesEligibles = $famillesEligibles->merge(
            Famille::factory()->critique()->count(2)->create(['etat_dossier' => 'Validé']),
        );

        // Quelques dossiers non éligibles (autres statuts que 'Validé') en
        // plus — pour que l'écran de sélection de campagne montre un vrai
        // filtre à l'oeuvre plutôt qu'une liste où tout est toujours
        // sélectionnable.
        Famille::factory()->count(self::NOMBRE_FAMILLES_NON_ELIGIBLES)->create();

        // Réutilise le même service que
        // Admin\Livraison\CampagnesController::genererLivraisons(). Comme
        // FamilleFactory tire etudiant/est_hotel indépendamment (voir sa
        // définition), une famille de démo peut rarement cocher les deux
        // et finir dans $resultat['conflits'] plutôt que d'être livrée —
        // ce n'est pas une erreur, juste signalé pour ne pas laisser
        // penser que toutes les familles éligibles ont forcément une
        // livraison générée.
        $resultat = $this->generationService->genererPour(
            $campagne,
            $famillesEligibles->pluck('id')->all(),
            $journee,
        );

        if ($resultat['conflits']->isNotEmpty()) {
            $this->command->warn("⚠️  {$resultat['conflits']->count()} famille(s) etudiant+hôtel "
                . 'exclue(s) de la génération (conflit, voir LivraisonGenerationService).');
        }

        return $resultat['livraisons'];
    }

    /**
     * @param \Illuminate\Support\Collection<int, Livraison> $livraisons
     */
    private function varierStatutsContact(\Illuminate\Support\Collection $livraisons): void
    {
        $nombreStatuts = count(self::STATUTS_CONTACT_DEMO);

        foreach ($livraisons->values() as $index => $livraison) {
            $statut = self::STATUTS_CONTACT_DEMO[$index % $nombreStatuts];
            $livraison->statut_contact = $statut;

            // 'confirme' = famille confirmée (formulaire public ou saisie
            // téléphonique) — snapshot adresse/foyer confirmés, même
            // granularité que familles (voir migration
            // revise_livraisons_confirmation_fields.php), et un créneau
            // confirmé (nécessaire au clustering/assignation en aval).
            if ($statut === 'confirme') {
                $livraison->adresse_confirmee = $livraison->famille->adresse;
                $livraison->code_postal_confirme = $livraison->famille->code_postal;
                $livraison->ville_confirmee = $livraison->famille->ville_texte;
                $livraison->nombre_adulte_confirme = $livraison->famille->nombre_adulte;
                $livraison->nombre_enfant_confirme = $livraison->famille->nombre_enfant;

                $livraison->creneaux()->create([
                    'creneau' => fake()->randomElement(Creneau::TOUS),
                ]);
            }

            $livraison->save();
        }
    }

    /**
     * Bénévoles/chauffeurs (permis + véhicule) avec disponibilité
     * CONFIRMÉE pour CHAQUE journée de cette campagne — réutilise
     * BenevoleSeeder pour la génération des personnes/profils (déjà
     * 'Validé' de bout en bout), puis
     * BenevoleDisponibiliteService::confirmer() pour la disponibilité
     * elle-même, même service que l'écran public de confirmation.
     *
     * Rescopée par journée le 05/09/2026 (confirmer() prend désormais une
     * CampagneJournee, pas une Campagne) : la démo confirme le même pool
     * de chauffeurs sur les 2 journées créées par run() — utile pour
     * exercer le clustering/génération de routes sur chacune séparément.
     */
    private function confirmerChauffeurs(Campagne $campagne): void
    {
        $this->call([BenevoleSeeder::class]);

        $chauffeurs = BenevoleProfil::valide()
            ->where('permis', true)
            ->orderByDesc('id')
            ->limit(self::NOMBRE_CHAUFFEURS_CONFIRMES)
            ->get();

        if ($chauffeurs->isEmpty()) {
            $this->command->warn('⚠️  Aucun bénévole avec permis trouvé après BenevoleSeeder — '
                . 'aucune disponibilité chauffeur confirmée pour cette campagne (essayez de relancer).');
            return;
        }

        foreach ($campagne->journees as $journee) {
            foreach ($chauffeurs as $profil) {
                $creneaux = fake()->randomElements(Creneau::TOUS, fake()->numberBetween(2, 4));

                $this->disponibiliteService->confirmer(
                    $profil->id_personne,
                    $journee,
                    [
                        'vehicule_confirme' => true,
                        'coverage_confirmee' => fake()->boolean(70),
                        'coverage_notes' => fake()->boolean(20) ? fake()->realText(60) : null,
                    ],
                    $creneaux,
                );
            }
        }

        $this->command->info("✅ {$chauffeurs->count()} bénévoles chauffeurs avec disponibilité "
            . "confirmée (véhicule confirmé) sur {$campagne->journees->count()} journée(s) de la campagne #{$campagne->id}.");
    }
}
