<?php
// app/Services/FamilleUpsertService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Famille;
use App\Models\Organisation;

/**
 * Logique de dédup + création/mise à jour partagée entre le formulaire
 * public d'intake (IntakeController) et l'import en masse
 * (FamilleImportService) — évite de dupliquer la même règle de
 * rapprochement à deux endroits (décision 6.9 : "même pipeline de
 * traitement", même esprit appliqué ici au niveau service).
 *
 * Reprend la logique de dédup de findDuplicateFamily() (amana_familles) :
 * priorité 1 email, priorité 2 téléphone + nom (insensible à la casse).
 *
 * Révision du 28/08/2026 (organisations partenaires) — le dédup reste
 * inchangé, mais un doublon trouvé n'entraîne plus systématiquement une
 * fusion : si $donnees['id_organisation'] correspond à une organisation
 * PAS ENCORE rattachée au dossier trouvé, le dossier n'est pas touché et
 * une demande de rattachement est créée pour validation staff (voir
 * FamilleOrganisationDemandeService) — c'est la traduction du "flag it and
 * require confirmation" décidé le 28/08/2026. Si l'organisation est déjà
 * rattachée (ou si $donnees ne précise pas d'organisation — anciens
 * appelants, formulaires non encore migrés), le comportement de fusion
 * historique s'applique tel quel.
 */
class FamilleUpsertService
{
    public function __construct(
        private readonly FamilleOrganisationDemandeService $demandeService,
    ) {
    }

    public function trouverDoublon(array $donnees): ?Famille
    {
        if (!empty($donnees['email'])) {
            $famille = Famille::whereRaw('LOWER(email) = ?', [strtolower($donnees['email'])])->first();
            if ($famille) {
                return $famille;
            }
        }

        if (!empty($donnees['telephone']) && !empty($donnees['nom'])) {
            return Famille::where('telephone', $donnees['telephone'])
                ->whereRaw('LOWER(nom) = ?', [strtolower($donnees['nom'])])
                ->first();
        }

        return null;
    }

    /**
     * Crée ou met à jour une famille à partir d'un tableau de champs déjà
     * validés. En cas de doublon, etat_dossier n'est écrasé QUE si
     * explicitement fourni dans $donnees (préserve le travail de triage du
     * staff sinon) — sur une création, $defauts s'applique.
     *
     * `avant` (snapshot pré-mise à jour, null sur une création OU quand la
     * fusion n'a pas eu lieu — voir `rattachement_en_attente`) est renvoyé
     * pour permettre à l'appelant de stocker un point de restauration —
     * voir FamilleImportRow::donnees_avant / FamilleImportRollbackService.
     *
     * `rattachement_en_attente` (ajouté le 28/08/2026) : true quand
     * $donnees['id_organisation'] désigne une organisation différente de
     * celle(s) déjà rattachées au doublon trouvé — le dossier renvoyé est
     * alors le dossier EXISTANT, INCHANGÉ (`cree` false, `avant` null),
     * une demande de rattachement a été créée à la place. L'appelant doit
     * traiter ce cas comme un "en attente de revue", pas comme une fusion
     * réussie (voir IntakeAttenteService::confirmer(), FamilleImportService).
     *
     * `secteursActivite` / `organismesAide` (tableaux d'IDs, optionnels) sont
     * synchronisés séparément des colonnes de familles — ce sont des
     * relations belongsToMany, pas des colonnes fillable de Famille. Ne
     * sont PAS synchronisés quand rattachement_en_attente est true (le
     * dossier n'est pas touché).
     *
     * @param int[]|null $secteursActivite
     * @param int[]|null $organismesAide
     * @param string $sourceDemande intake|import|manuel — voir FamilleOrganisationDemande::SOURCES
     * @return array{famille: Famille, cree: bool, avant: ?array, rattachement_en_attente: bool}
     */
    public function upsert(
        array $donnees,
        array $defauts = [],
        ?array $secteursActivite = null,
        ?array $organismesAide = null,
        string $sourceDemande = 'import',
        ?int $submittedBy = null,
    ): array {
        $idOrganisation = $donnees['id_organisation'] ?? null;
        $existante = $this->trouverDoublon($donnees);

        if ($existante) {
            if ($idOrganisation && !$existante->estRattacheeA($idOrganisation)) {
                $this->demandeService->creerOuMettreAJour(
                    $existante,
                    $idOrganisation,
                    $sourceDemande,
                    $submittedBy,
                    $donnees,
                );

                return ['famille' => $existante, 'cree' => false, 'avant' => null, 'rattachement_en_attente' => true];
            }

            $avant = $existante->toArray();
            $existante->fill($donnees);
            $existante->save();
            $this->syncListes($existante, $secteursActivite, $organismesAide);

            audit('update', 'familles', $existante->id, $avant, $existante->toArray());

            return ['famille' => $existante, 'cree' => false, 'avant' => $avant, 'rattachement_en_attente' => false];
        }

        $famille = Famille::create(array_merge($defauts, $donnees));
        $this->syncListes($famille, $secteursActivite, $organismesAide);
        $this->rattacherOrganisationInitiale($famille, $idOrganisation);

        audit('create', 'familles', $famille->id, null, $famille->toArray());

        return ['famille' => $famille, 'cree' => true, 'avant' => null, 'rattachement_en_attente' => false];
    }

    /**
     * @param int[]|null $secteursActivite
     * @param int[]|null $organismesAide
     */
    private function syncListes(Famille $famille, ?array $secteursActivite, ?array $organismesAide): void
    {
        // null = "non fourni, ne pas toucher" (ex : mise à jour partielle
        // depuis l'import) ; [] = "vider explicitement" — distinction
        // volontaire, contrairement à un simple `$secteursActivite ?: []`.
        if ($secteursActivite !== null) {
            $famille->secteursActivite()->sync($secteursActivite);
        }

        if ($organismesAide !== null) {
            $famille->organismesAide()->sync($organismesAide);
        }
    }

    /**
     * Rattache la nouvelle famille à l'organisation fournie, ou à
     * l'organisation principale (AMANA) à défaut — décision du 28/08/2026 :
     * aucun dossier ne doit se retrouver sans organisation, y compris les
     * appelants qui n'ont pas encore de contexte d'organisation à fournir.
     */
    private function rattacherOrganisationInitiale(Famille $famille, ?int $idOrganisation): void
    {
        $idOrganisation ??= Organisation::principale()?->id;

        if (!$idOrganisation) {
            return;
        }

        if (!$famille->id_organisation) {
            $famille->id_organisation = $idOrganisation;
            $famille->save();
        }

        $famille->organisations()->syncWithoutDetaching([$idOrganisation]);
    }
}
