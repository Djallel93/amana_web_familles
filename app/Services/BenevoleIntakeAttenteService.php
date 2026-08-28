<?php
// app/Services/BenevoleIntakeAttenteService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Services\PersonneIntakeService;
use App\Models\BenevoleDemandeAttente;
use App\Models\BenevoleProfil;
use App\Models\Personne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Centralise le cycle de vie d'une candidature bénévole en attente de
 * confirmation par email — miroir d'IntakeAttenteService (voir cette
 * classe pour le raisonnement détaillé sur la table d'attente 48h).
 *
 * Différence principale avec le flux familles : à la confirmation, on ne
 * crée pas seulement une entité locale (Famille) mais on lie/crée une
 * Personne (base commune) ET un BenevoleProfil (base commune également,
 * voir décision du 24/08/2026) — jamais de doublon de compte, voir
 * PersonneIntakeService::trouverOuPreparerPersonne().
 *
 * Révision du 24/08/2026 : plus de disponibilités (retiré, fonctionnalité
 * event-related future) ; vehicule_type/capacite_kg/nombre_part_max
 * remplacés par id_vehicule_type (référentiel ref_vehicules).
 */
class BenevoleIntakeAttenteService
{
    private const DUREE_VALIDITE_HEURES = 48;

    /**
     * @param array<string, mixed> $donneesValidees Nom/prenom/email/telephone/langue/permis/id_vehicule_type
     * @param int[] $secteurs
     */
    public function creerDemande(
        array $donneesValidees,
        array $secteurs,
        string $langue,
    ): BenevoleDemandeAttente {
        $existante = $this->trouverAttenteExistante($donneesValidees);
        if ($existante) {
            $existante->delete();
        }

        return BenevoleDemandeAttente::create([
            'token' => Str::random(60),
            'langue' => $langue,
            'donnees' => $donneesValidees,
            'secteurs' => $secteurs,
            'expires_at' => now()->addHours(self::DUREE_VALIDITE_HEURES),
        ]);
    }

    /**
     * Même logique de rapprochement que IntakeAttenteService::
     * trouverAttenteExistante() (priorité email, puis téléphone + nom),
     * appliquée aux candidatures pas encore confirmées.
     */
    private function trouverAttenteExistante(array $donnees): ?BenevoleDemandeAttente
    {
        if (!empty($donnees['email'])) {
            $demande = BenevoleDemandeAttente::whereRaw(
                'LOWER(JSON_UNQUOTE(JSON_EXTRACT(donnees, "$.email"))) = ?',
                [strtolower($donnees['email'])],
            )->first();
            if ($demande) {
                return $demande;
            }
        }

        if (!empty($donnees['telephone']) && !empty($donnees['nom'])) {
            return BenevoleDemandeAttente::whereRaw(
                'JSON_UNQUOTE(JSON_EXTRACT(donnees, "$.telephone")) = ?',
                [$donnees['telephone']],
            )->whereRaw(
                'LOWER(JSON_UNQUOTE(JSON_EXTRACT(donnees, "$.nom"))) = ?',
                [strtolower($donnees['nom'])],
            )->first();
        }

        return null;
    }

    /**
     * Transforme une candidature confirmée en Personne liée/créée (statut
     * 'En attente' si nouvelle — même logique que CandidatureController de
     * amana_web_planning : c'est une candidature publique, pas une
     * création staff, donc pas de 'Validé' immédiat) + BenevoleProfil.
     *
     * @return array{personne: Personne, profil: BenevoleProfil, personneExistante: bool}
     */
    public function confirmer(BenevoleDemandeAttente $demande): array
    {
        $donnees = $demande->donnees;

        return DB::connection(config('amana-shared.connection', 'commun'))->transaction(function () use ($donnees, $demande) {
            ['personne' => $personne, 'existante' => $existante] = PersonneIntakeService::trouverOuPreparerPersonne(
                Personne::class,
                $donnees,
                'En attente',
            );
            $personne->save();

            // Un profil bénévole existe peut-être déjà (ex : personne déjà
            // candidate par le passé, refusée puis qui retente) — on le met
            // à jour plutôt que d'en créer un second (contrainte unique
            // id_personne de toute façon, voir migration
            // create_benevole_profils_table).
            $profil = BenevoleProfil::updateOrCreate(
                ['id_personne' => $personne->id],
                [
                    'langue_preferee' => $donnees['langue'] ?? 'fr',
                    'permis' => (bool) ($donnees['permis'] ?? false),
                    'id_vehicule_type' => $donnees['id_vehicule_type'],
                    'statut' => 'Reçu',
                ],
            );

            $profil->secteurs()->sync($demande->secteurs ?? []);

            $demande->delete();

            return ['personne' => $personne, 'profil' => $profil, 'personneExistante' => $existante];
        });
    }
}
