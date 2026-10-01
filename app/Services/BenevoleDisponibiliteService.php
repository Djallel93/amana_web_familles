<?php
// app/Services/BenevoleDisponibiliteService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Models\BenevoleProfil;
use Amana\Shared\Models\VehiculeType;
use App\Models\BenevoleDisponibilite;
use App\Models\Campagne;
use App\Models\CampagneJournee;
use App\Notifications\CampagneDisponibiliteNotification;
use App\Support\GeographiePicker;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Notification de lancement de campagne aux bénévoles + upsert de leur
 * disponibilité — voir le prompt du 30/08/2026 §3.2. Miroir de
 * FamilleVerificationService::envoyerParLot() côté structure (envoi par
 * lot, ne fait pas échouer l'ensemble si un envoi individuel échoue).
 */
class BenevoleDisponibiliteService
{
    private ?int $idSansPermis = null;

    /**
     * @return array{envoyes: int, echecs: int}
     */
    public function notifierCampagne(Campagne $campagne): array
    {
        $resultats = ['envoyes' => 0, 'echecs' => 0];

        $profils = BenevoleProfil::where('statut', 'Validé')->with('personne')->get();

        foreach ($profils as $profil) {
            if (!$profil->personne) {
                continue;
            }

            try {
                $profil->personne->notify(new CampagneDisponibiliteNotification($campagne, $profil));
                $resultats['envoyes']++;
            } catch (\Throwable $e) {
                Log::error('[BenevoleDisponibiliteService] Échec envoi', [
                    'id_personne' => $profil->id_personne,
                    'id_campagne' => $campagne->id,
                    'erreur' => $e->getMessage(),
                ]);
                $resultats['echecs']++;
            }
        }

        return $resultats;
    }

    /**
     * Id de la ligne ref_vehicules « Sans permis » — valeur stockée pour
     * un bénévole non titulaire du permis (même convention que
     * l'inscription et les seeders).
     */
    public function idSansPermis(): ?int
    {
        if ($this->idSansPermis === null) {
            $id = VehiculeType::where('type', GeographiePicker::LIBELLE_SANS_PERMIS)->value('id');
            $this->idSansPermis = $id === null ? null : (int) $id;
        }

        return $this->idSansPermis;
    }

    /**
     * Validateur des blocs « Véhicule » et « Couverture » (01/10/2026),
     * partagé par la page du bénévole et la fiche personne admin — mêmes
     * règles aux deux endroits, jamais deux copies qui divergent.
     *
     * Véhicule : « même que mon profil » OU (permis + véhicule). Sans
     * permis, aucun véhicule n'est choisi (« Sans permis » est stocké) ;
     * avec permis, un véhicule de la liste est obligatoire et « Sans
     * permis » est refusé (contradiction). Couverture : « même zone que
     * mon profil » OU au moins un secteur.
     *
     * @param  array<string, mixed>  $donnees
     * @param  array<string, mixed>  $reglesSupplementaires
     */
    public function validateur(array $donnees, ?BenevoleProfil $profil, array $reglesSupplementaires = []): ValidatorContract
    {
        $validator = Validator::make($donnees, [
            'vehicule_confirme' => ['required', 'boolean'],
            'permis' => ['nullable', 'boolean'],
            'id_vehicule_type' => ['nullable', 'integer', 'exists:commun.ref_vehicules,id'],
            'coverage_confirmee' => ['required', 'boolean'],
            'secteurs' => ['nullable', 'array'],
            'secteurs.*' => ['integer', 'exists:commun.secteurs,id'],
        ] + $reglesSupplementaires);

        $validator->after(function (ValidatorContract $v) use ($donnees, $profil) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            if (filter_var($donnees['vehicule_confirme'], FILTER_VALIDATE_BOOLEAN)) {
                if (!$profil) {
                    $v->errors()->add('vehicule_confirme', "Aucun profil bénévole : choisissez votre véhicule.");
                }
            } elseif (!array_key_exists('permis', $donnees) || $donnees['permis'] === null) {
                $v->errors()->add('permis', 'Indiquez si vous êtes titulaire du permis de conduire.');
            } elseif (filter_var($donnees['permis'], FILTER_VALIDATE_BOOLEAN)) {
                $idVehicule = (int) ($donnees['id_vehicule_type'] ?? 0);
                if ($idVehicule === 0) {
                    $v->errors()->add('id_vehicule_type', 'Choisissez un véhicule.');
                } elseif ($idVehicule === $this->idSansPermis()) {
                    $v->errors()->add('id_vehicule_type', '« Sans permis » est incompatible avec un permis de conduire.');
                }
            }

            if (filter_var($donnees['coverage_confirmee'], FILTER_VALIDATE_BOOLEAN)) {
                if (!$profil) {
                    $v->errors()->add('coverage_confirmee', 'Aucun profil bénévole : choisissez vos secteurs.');
                }
            } elseif (empty($donnees['secteurs'])) {
                $v->errors()->add('secteurs', 'Sélectionnez au moins un secteur.');
            }
        });

        return $validator;
    }

    /**
     * État initial des blocs « Véhicule » / « Couverture » pour une
     * journée — consommé par le JS de
     * resources/views/livraison/partials/vehicule-couverture.blade.php.
     * Sans ligne pour la journée : tout décoché/vide, le bénévole doit
     * répondre explicitement.
     *
     * @return array{vehicule_confirme: bool, permis: bool, id_vehicule_type: int|null, coverage_confirmee: bool, secteurs: int[]}
     */
    public function etatFormulaire(?BenevoleDisponibilite $disponibilite): array
    {
        $idSansPermis = $this->idSansPermis();
        $propre = $disponibilite?->aVehiculePropre() ?? false;
        $idVehicule = $propre && (int) $disponibilite->id_vehicule_type !== $idSansPermis
            ? (int) $disponibilite->id_vehicule_type
            : null;

        return [
            'vehicule_confirme' => (bool) $disponibilite?->vehicule_confirme,
            'permis' => $propre && $disponibilite->permis === true,
            'id_vehicule_type' => $idVehicule,
            'coverage_confirmee' => (bool) $disponibilite?->coverage_confirmee,
            'secteurs' => $disponibilite
                ? $disponibilite->secteurs->pluck('id_secteur')->map(fn ($id) => (int) $id)->values()->all()
                : [],
        ];
    }

    /**
     * Enregistre le véhicule et la couverture d'un bénévole pour UNE
     * journée, SANS toucher à ses créneaux — utilisé par la fiche
     * personne admin (une modification admin vaut confirmation, 01/10/2026)
     * et, via confirmer(), par la page du bénévole.
     *
     * Une clé absente de $donnees laisse la valeur déjà enregistrée
     * intacte (créneaux seuls depuis Suivi des bénévoles, voir
     * confirmer()). `$donnees` doit avoir été validé par validateur().
     *
     * @param  array<string, mixed>  $donnees
     */
    public function enregistrerInformations(int $idPersonne, CampagneJournee $journee, array $donnees): BenevoleDisponibilite
    {
        return DB::transaction(function () use ($idPersonne, $journee, $donnees) {
            $attributs = ['statut' => 'confirme'];

            if (array_key_exists('vehicule_confirme', $donnees)) {
                if (filter_var($donnees['vehicule_confirme'], FILTER_VALIDATE_BOOLEAN)) {
                    $attributs += ['vehicule_confirme' => true, 'permis' => null, 'id_vehicule_type' => null];
                } else {
                    $permis = filter_var($donnees['permis'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    $attributs += [
                        'vehicule_confirme' => false,
                        'permis' => $permis,
                        'id_vehicule_type' => $permis
                            ? (((int) ($donnees['id_vehicule_type'] ?? 0)) ?: null)
                            : $this->idSansPermis(),
                    ];
                }
            }

            $couvertureDefinie = array_key_exists('coverage_confirmee', $donnees);
            $couvertureConfirmee = $couvertureDefinie && filter_var($donnees['coverage_confirmee'], FILTER_VALIDATE_BOOLEAN);
            if ($couvertureDefinie) {
                $attributs['coverage_confirmee'] = $couvertureConfirmee;
            }

            $disponibilite = BenevoleDisponibilite::updateOrCreate(
                ['id_personne' => $idPersonne, 'id_campagne_journee' => $journee->id],
                $attributs,
            );

            if ($couvertureDefinie) {
                $disponibilite->secteurs()->delete();
                if (!$couvertureConfirmee) {
                    foreach (array_unique(array_map('intval', $donnees['secteurs'] ?? [])) as $idSecteur) {
                        $disponibilite->secteurs()->create(['id_secteur' => $idSecteur]);
                    }
                }
            }

            return $disponibilite;
        });
    }

    /**
     * Crée ou met à jour la disponibilité d'un bénévole pour UNE journée
     * de campagne — éditable à tout moment après la confirmation
     * initiale (voir le prompt §3.2 : "Editable by them at any time
     * after"), donc un simple upsert plutôt qu'un flux "renvoyer le
     * formulaire".
     *
     * Rescopée de Campagne vers CampagneJournee le 05/09/2026 : un
     * bénévole confirme désormais séparément pour chaque journée (ex:
     * dispo le jour de collecte, pas le jour de livraison) — voir
     * create_benevole_disponibilites_table.php.
     *
     * Les clés véhicule/couverture de $donnees sont facultatives
     * (07/09/2026, prompt §5.1 : "Modifier disponibilités" ne touche plus
     * qu'aux créneaux) : absentes, la valeur déjà enregistrée est
     * conservée — voir enregistrerInformations().
     *
     * @param  array<string, mixed>  $donnees
     * @param  string[]  $creneaux
     */
    public function confirmer(int $idPersonne, CampagneJournee $journee, array $donnees, array $creneaux): BenevoleDisponibilite
    {
        return DB::transaction(function () use ($idPersonne, $journee, $donnees, $creneaux) {
            $disponibilite = $this->enregistrerInformations($idPersonne, $journee, $donnees);

            $disponibilite->creneaux()->delete();
            foreach ($creneaux as $creneau) {
                $disponibilite->creneaux()->create(['creneau' => $creneau]);
            }

            return $disponibilite;
        });
    }
}
