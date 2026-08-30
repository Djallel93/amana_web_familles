<?php
// app/Services/FamilleUpsertService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Famille;
use App\Models\HotelAddress;
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
 *
 * Ajout du 30/08/2026 — `est_hotel` forcé à true dans $donnees quand
 * l'adresse correspond à une entrée du référentiel hotel_addresses (voir
 * forcerEstHotelSiAdresseConnue()), même si la famille n'a pas coché la
 * case "hôtel" (ou si l'import ne la renseigne pas). Appliqué ici plutôt
 * que dans chaque appelant (intake, import) pour ne pas dupliquer la
 * règle — même raisonnement que le reste de cette classe.
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
        $donnees = $this->forcerEstHotelSiAdresseConnue($donnees);

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
     * Force $donnees['est_hotel'] à true si l'adresse fournie correspond à
     * une entrée du référentiel hotel_addresses — ne fait rien si $donnees
     * ne contient pas 'adresse' (mise à jour partielle qui ne touche pas
     * l'adresse : pas de raison de réévaluer le flag), et ne touche jamais
     * $donnees['est_hotel'] à la baisse (un flag déjà positionné à true
     * manuellement par le staff n'est jamais réinitialisé ici, seule une
     * mise à true est possible).
     *
     * Comparaison en confinement mutuel après normalisation
     * (HotelAddress::normaliser()) plutôt qu'en égalité stricte : le
     * référentiel mélange volontairement adresses brutes et adresses
     * préfixées du nom de l'établissement (voir migration
     * create_hotel_addresses_table), une correspondance exacte ligne à
     * ligne manquerait trop de cas légitimes. Le jeu de données restant
     * petit (quelques dizaines de lignes au plus), la comparaison se fait
     * en PHP plutôt qu'en SQL.
     */
    private function forcerEstHotelSiAdresseConnue(array $donnees): array
    {
        if (!array_key_exists('adresse', $donnees) || !filled($donnees['adresse'])) {
            return $donnees;
        }

        $adresseFamille = HotelAddress::normaliser(implode(' ', array_filter([
            $donnees['adresse'] ?? null,
            $donnees['code_postal'] ?? null,
            $donnees['ville_texte'] ?? null,
        ])));

        if ($adresseFamille === '') {
            return $donnees;
        }

        $correspond = HotelAddress::query()->pluck('adresse_normalisee')->contains(
            fn(string $adresseConnue) => $adresseConnue !== ''
                && (str_contains($adresseFamille, $adresseConnue) || str_contains($adresseConnue, $adresseFamille)),
        );

        if ($correspond) {
            $donnees['est_hotel'] = true;
        }

        return $donnees;
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
