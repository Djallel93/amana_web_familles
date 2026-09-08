<?php
// database/seeders/LivraisonDemoSeeder.php

declare(strict_types=1);

namespace Database\Seeders;

use Amana\Shared\Database\Seeders\VehiculeTypesSeeder;
use Amana\Shared\Models\Secteur;
use Amana\Shared\Models\Setting;
use Amana\Shared\Models\VehiculeType;
use App\Models\BenevoleProfil;
use App\Models\Famille;
use App\Models\Personne;
use App\Services\RoleService;
use App\Support\RouteOptimizationConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeder de démo — domaine livraison/campagne, revu le 07/09/2026 pour une
 * démo "campagne réelle" : NE crée ni n'enrichit plus aucune Campagne/
 * CampagneJournee/Livraison/RouteLivraison/Donation/CampagneArrivee — voir
 * l'échange du même jour. La campagne elle-même est créée et pilotée EN
 * DIRECT par l'admin pendant la démo (création, sélection des familles
 * éligibles, génération des livraisons, notification/confirmation
 * familles et bénévoles, pesée, réception, packaging, chargement,
 * livraison — tout ça se démontre depuis les écrans, pas pré-généré ici).
 *
 * Ce seeder prépare uniquement le "casting" nécessaire pour que cette
 * démo live tourne sans accroc :
 *
 *  - 8 familles à adresses RÉELLES nantaises (lat/lng/id_quartier fixés en
 *    dur — pas de dispatch de App\Jobs\ResoudreAdresseFamille, dont le
 *    géocodage Google Maps est indisponible/non-déterministe en seed),
 *    réparties sur les 5 secteurs de Nantes (Nord/Est/Centre/Ouest/Sud —
 *    voir Amana\Shared\Database\Seeders\GeoSeeder), majorité
 *    etat_dossier='Validé' (6/8 — éligibles à une campagne, voir
 *    LivraisonGenerationService::eligibles()), une famille hôtel, une
 *    étudiante ;
 *
 *  - des chauffeurs (bénévoles Validé, permis + véhicule + secteurs())
 *    couvrant CHACUN des 5 secteurs nantais, plus 2 volontairement EXCLUS
 *    (secteur assigné mais sans permis) pour tester un trou de couverture
 *    en direct pendant la démo ;
 *
 *  - le réglage global HQ (route_hq_latitude/longitude), requis par
 *    RouteGenerationService avant tout clustering, s'il n'est pas déjà
 *    configuré.
 *
 * Coordonnées des familles : centroïdes des quartiers réels ciblés,
 * vérifiés le 07/09/2026 par test point-dans-polygone contre les vrais
 * WKT (amana_shared/src/Database/Seeders/data/geo_quartiers.json) —
 * garantis cohérents avec la résolution point-in-polygon de
 * App\Jobs\ResoudreAdresseFamille::resoudreQuartier(), pas une
 * estimation. Les adresses textuelles sont réelles (mairies de quartier,
 * lieux connus, référentiel HotelAddressSeeder pour la famille hôtel)
 * mais la précision lat/lng est celle du centroïde du quartier, pas un
 * géocodage adresse-par-adresse (aucun accès à un service de géocodage
 * en environnement de seed).
 *
 * Prérequis : ref_vehicules peuplé (VehiculeTypesSeeder, appelé
 * automatiquement ici s'il est vide, même garde-fou que BenevoleSeeder) ;
 * secteurs/quartiers de Nantes peuplés (GeoSeeder côté amana_shared) —
 * sans ça, ce seeder échoue avec un message clair plutôt que de créer des
 * familles/chauffeurs sans géographie cohérente.
 *
 * Volontairement PAS appelé automatiquement par DatabaseSeeder::run() —
 * même convention que FamilleSeeder/BenevoleSeeder/HotelAddressSeeder :
 *
 *   php artisan db:seed --class=LivraisonDemoSeeder
 */
class LivraisonDemoSeeder extends Seeder
{
    private const HQ_LATITUDE_DEFAUT = 47.245919;
    private const HQ_LONGITUDE_DEFAUT = -1.604111;

    private const NOMBRE_CHAUFFEURS_PAR_SECTEUR = 2;

    /**
     * Nombre de secteurs volontairement laissés SANS chauffeur confirmé
     * (permis) — un chauffeur "exclu" par secteur touché, sur les
     * premiers secteurs nantais rencontrés (ordre de la requête, pas de
     * signification métier) — voir creerChauffeurs().
     */
    private const NOMBRE_SECTEURS_EXCLUS = 2;

    /**
     * 8 familles réelles nantaises — une par quartier officiel ciblé,
     * réparties sur les 5 secteurs (voir docblock de classe pour la
     * méthode de calcul lat/lng/id_quartier — IDs stables, voir GeoSeeder).
     * etat_dossier majoritairement 'Validé' (6/8) — les 2 autres pour la
     * variété visuelle de l'écran Dossier Familles, pas pour tester une
     * éligibilité de campagne (hors de portée de ce seeder désormais).
     */
    private const FAMILLES = [
        [
            'nom' => 'Lefort', 'prenom' => 'Amina',
            'adresse' => '12 Rue de Strasbourg', 'code_postal' => '44000', 'ville_texte' => 'Nantes',
            'latitude' => 47.213665, 'longitude' => -1.556368, 'id_quartier' => 11, // Centre Ville
            'etat_dossier' => 'Validé', 'criticite' => 2,
            'nombre_adulte' => 1, 'nombre_enfant' => 2,
        ],
        [
            'nom' => 'Benali', 'prenom' => 'Rachid',
            'adresse' => '5 Quai Ernest Renaud', 'code_postal' => '44100', 'ville_texte' => 'Nantes',
            'latitude' => 47.198145, 'longitude' => -1.602389, 'id_quartier' => 3, // Bellevue - Chantenay - Sainte-Anne
            'etat_dossier' => 'Validé', 'criticite' => 5,
            'nombre_adulte' => 2, 'nombre_enfant' => 4,
        ],
        [
            'nom' => 'Diarra', 'prenom' => 'Fatoumata',
            'adresse' => '18 Boulevard Jean Moulin', 'code_postal' => '44100', 'ville_texte' => 'Nantes',
            'latitude' => 47.216259, 'longitude' => -1.589383, 'id_quartier' => 14, // Dervallières - Zola
            'etat_dossier' => 'Validé', 'criticite' => 1,
            'nombre_adulte' => 1, 'nombre_enfant' => 1,
        ],
        [
            'nom' => 'Ferreira', 'prenom' => 'Julia',
            'adresse' => '69 Rue de la Bottière', 'code_postal' => '44300', 'ville_texte' => 'Nantes',
            'latitude' => 47.237048, 'longitude' => -1.506594, 'id_quartier' => 15, // Doulon - Bottière
            'etat_dossier' => 'Validé', 'criticite' => 2,
            'nombre_adulte' => 1, 'nombre_enfant' => 0,
            'etudiant' => true,
        ],
        [
            'nom' => 'Morel', 'prenom' => 'Sophie',
            'adresse' => '24 Rue du Général Buat', 'code_postal' => '44000', 'ville_texte' => 'Nantes',
            'latitude' => 47.228153, 'longitude' => -1.563487, 'id_quartier' => 19, // Hauts-Pavés - Saint-Félix
            'etat_dossier' => 'En attente', 'criticite' => 0,
            'nombre_adulte' => 2, 'nombre_enfant' => 0,
        ],
        [
            'nom' => 'Karadeniz', 'prenom' => 'Emre',
            'adresse' => '2 Boulevard Léon Bureau', 'code_postal' => '44200', 'ville_texte' => 'Nantes',
            'latitude' => 47.205250, 'longitude' => -1.546574, 'id_quartier' => 20, // Île de Nantes
            'etat_dossier' => 'Validé', 'criticite' => 3,
            'nombre_adulte' => 1, 'nombre_enfant' => 3,
        ],
        [
            'nom' => 'Haddad', 'prenom' => 'Nadia',
            // Adresse reprise telle quelle du référentiel HotelAddressSeeder
            // (voir database/seeders/HotelAddressSeeder.php) — cohérente
            // avec FamilleUpsertService::upsert(), qui force est_hotel via
            // ce même référentiel.
            'adresse' => 'Appart Hôtel - Residhome Nantes Berges de la Loire', 'code_postal' => '44000', 'ville_texte' => 'Nantes',
            'latitude' => 47.222834, 'longitude' => -1.535682, 'id_quartier' => 32, // Malakoff - Saint-Donatien
            'etat_dossier' => 'Validé', 'criticite' => 3,
            'nombre_adulte' => 1, 'nombre_enfant' => 2,
            'est_hotel' => true,
        ],
        [
            'nom' => 'Traore', 'prenom' => 'Ibrahim',
            'adresse' => '41 Route de la Chapelle-sur-Erdre', 'code_postal' => '44300', 'ville_texte' => 'Nantes',
            'latitude' => 47.257482, 'longitude' => -1.565424, 'id_quartier' => 36, // Nantes Nord
            'etat_dossier' => 'Rejeté', 'criticite' => 0,
            'nombre_adulte' => 1, 'nombre_enfant' => 1,
        ],
    ];

    public function __construct(
        private readonly RoleService $roleService,
    ) {
    }

    public function run(): void
    {
        if (VehiculeType::count() === 0) {
            $this->call([VehiculeTypesSeeder::class]);
        }

        $secteursNantes = $this->secteursNantes();
        if ($secteursNantes === null) {
            return;
        }

        $this->assurerHq();
        $this->creerFamilles();
        $this->creerChauffeurs($secteursNantes);
    }

    /**
     * @return array<string, int>|null Nom du secteur => id (Nord/Est/Centre/Ouest/Sud), ou null si absents.
     */
    private function secteursNantes(): ?array
    {
        $secteurs = Secteur::whereHas('ville', fn ($q) => $q->where('nom', 'Nantes'))->pluck('id', 'nom');

        if ($secteurs->count() < 5) {
            $this->command->error('❌ Secteurs de Nantes introuvables (5 attendus : Nord/Est/Centre/Ouest/Sud) — '
                . 'lancez d\'abord : php artisan db:seed --class="Amana\\Shared\\Database\\Seeders\\GeoSeeder"');
            return null;
        }

        return $secteurs->all();
    }

    /**
     * Renseigne le réglage global HQ (route_hq_latitude/longitude) s'il
     * n'est pas déjà configuré — requis par
     * RouteGenerationService::genererPourCampagne() avant tout clustering
     * (voir App\Support\RouteOptimizationConfig::coordonneesHq()). Ne
     * touche à rien s'il est déjà renseigné (ne jamais écraser un réglage
     * saisi par l'admin).
     */
    private function assurerHq(): void
    {
        if (RouteOptimizationConfig::coordonneesHq() !== null) {
            return;
        }

        Setting::set('route_hq_latitude', 'familles', (string) self::HQ_LATITUDE_DEFAUT);
        Setting::set('route_hq_longitude', 'familles', (string) self::HQ_LONGITUDE_DEFAUT);

        $this->command->info('✅ HQ par défaut renseigné (aucune valeur existante) : '
            . self::HQ_LATITUDE_DEFAUT . ', ' . self::HQ_LONGITUDE_DEFAUT);
    }

    /**
     * firstOrCreate() par nom+prenom+adresse plutôt qu'un create() sec —
     * ces 8 familles sont des données FIXES (adresses réelles en dur),
     * pas générées aléatoirement comme FamilleFactory : relancer ce
     * seeder ne doit pas les dupliquer.
     */
    private function creerFamilles(): void
    {
        $crees = 0;

        foreach (self::FAMILLES as $donnees) {
            $famille = Famille::firstOrCreate(
                ['nom' => $donnees['nom'], 'prenom' => $donnees['prenom'], 'adresse' => $donnees['adresse']],
                [
                    'email' => fake()->boolean(60) ? fake()->safeEmail() : null,
                    'telephone' => '06' . fake()->numerify('########'),
                    'zakat_el_fitr' => true,
                    'sadaqa' => fake()->boolean(30),
                    'nombre_adulte' => $donnees['nombre_adulte'],
                    'nombre_enfant' => $donnees['nombre_enfant'],
                    'code_postal' => $donnees['code_postal'],
                    'ville_texte' => $donnees['ville_texte'],
                    'id_quartier' => $donnees['id_quartier'],
                    'latitude' => $donnees['latitude'],
                    'longitude' => $donnees['longitude'],
                    'se_deplace' => fake()->boolean(50),
                    'est_hotel' => $donnees['est_hotel'] ?? false,
                    'etudiant' => $donnees['etudiant'] ?? false,
                    'criticite' => $donnees['criticite'],
                    'langue' => 'fr',
                    'etat_dossier' => $donnees['etat_dossier'],
                    'type_piece_identite' => 'nationalite',
                    'type_hebergement' => ($donnees['est_hotel'] ?? false) ? 'organisation' : 'non',
                    'type_activite' => 'non',
                ],
            );

            if ($famille->wasRecentlyCreated) {
                $crees++;
            }
        }

        $this->command->info("✅ {$crees} nouvelle(s) famille(s) de démo créée(s) (adresses réelles "
            . 'nantaises, réparties sur les 5 secteurs — ' . (count(self::FAMILLES) - $crees)
            . ' déjà existante(s), inchangée(s)).');
    }

    /**
     * Chauffeurs (bénévoles Validé, permis + véhicule) couvrant CHACUN des
     * 5 secteurs nantais (BenevoleProfil::secteurs()) — garantit qu'au
     * moment de la démo live, chaque zone où vit une famille de démo a au
     * moins un chauffeur déclaré disponible pour ce secteur. +1 chauffeur
     * volontairement EXCLU par secteur touché (secteur assigné mais SANS
     * permis, donc jamais de vrai véhicule — voir BenevoleForm.vue) sur
     * les NOMBRE_SECTEURS_EXCLUS premiers secteurs, pour tester en direct
     * un trou de couverture.
     *
     * NE confirme AUCUNE disponibilité de campagne ici
     * (BenevoleDisponibilite est scopée à une CampagneJournee qui
     * n'existe pas encore — voir docblock de classe) : ces chauffeurs
     * sont de simples profils Validé, prêts à répondre à la notification
     * envoyée en direct pendant la démo
     * (CampagnesController::notifierBenevoles()) puis à confirmer leur
     * disponibilité eux-mêmes (ou via saisie staff).
     *
     * Non idempotent (comme BenevoleSeeder) : relancer ce seeder ajoute
     * un nouveau lot de chauffeurs plutôt que de les dédupliquer — les
     * personnes générées sont fictives (email -test.amana.local),
     * contrairement aux 8 familles à adresse fixe.
     *
     * @param array<string, int> $secteursNantes Nom du secteur => id.
     */
    private function creerChauffeurs(array $secteursNantes): void
    {
        $vehiculesAvecPermis = VehiculeType::where('type', '!=', 'Sans permis')->get();
        $vehiculeSansPermis = VehiculeType::where('type', 'Sans permis')->first();

        if ($vehiculesAvecPermis->isEmpty()) {
            $this->command->error('❌ Aucun véhicule "avec permis" dans ref_vehicules — lancez d\'abord '
                . 'VehiculeTypesSeeder.');
            return;
        }

        if (!$this->roleService->famillesApp()) {
            // Ne devrait plus arriver depuis le 27/08/2026 — voir
            // BenevoleSeeder, même garde-fou.
            $this->command->error('❌ Application "familles" introuvable dans ref_applications.');
            return;
        }

        $confirmes = 0;
        foreach ($secteursNantes as $idSecteur) {
            for ($i = 0; $i < self::NOMBRE_CHAUFFEURS_PAR_SECTEUR; $i++) {
                $this->creerChauffeur($idSecteur, permis: true, vehicule: $vehiculesAvecPermis->random());
                $confirmes++;
            }
        }

        $exclus = 0;
        foreach (array_slice($secteursNantes, 0, self::NOMBRE_SECTEURS_EXCLUS) as $idSecteur) {
            $this->creerChauffeur($idSecteur, permis: false, vehicule: $vehiculeSansPermis ?? $vehiculesAvecPermis->first());
            $exclus++;
        }

        $this->command->info("✅ {$confirmes} chauffeurs confirmés (permis + véhicule) répartis sur les "
            . count($secteursNantes) . " secteurs nantais, + {$exclus} volontairement exclus (sans permis) "
            . 'pour tester un trou de couverture en direct.');
    }

    private function creerChauffeur(int $idSecteur, bool $permis, ?VehiculeType $vehicule): void
    {
        $prenom = fake()->firstName();
        $nom = fake()->lastName();
        // Même convention que BenevoleSeeder : suffixe aléatoire pour
        // rester unique même en relançant ce seeder plusieurs fois.
        $email = Str::slug($prenom . '.' . $nom) . '-' . Str::random(4) . '@benevole-test.amana.local';

        $personne = Personne::create([
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => $email,
            'telephone' => fake()->phoneNumber(),
            'password' => null,
            'statut' => 'Validé',
        ]);
        $personne->email_verified_at = now();
        $personne->save();

        $profil = BenevoleProfil::create([
            'id_personne' => $personne->id,
            'langue_preferee' => 'fr',
            'permis' => $permis,
            'id_vehicule_type' => $vehicule?->id,
            'statut' => 'Validé',
        ]);

        $profil->secteurs()->sync([$idSecteur]);

        $this->roleService->syncRoleFamilles($personne, 'benevole');
    }
}
