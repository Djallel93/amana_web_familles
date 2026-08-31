<?php
// app/Services/LivraisonGenerationService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Campagne;
use App\Models\Famille;
use App\Models\Livraison;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Sélection des familles éligibles à une campagne + génération des lignes
 * Livraison correspondantes — voir le prompt du 30/08/2026 §7 ("Family
 * eligibility selection : criteria (criticité, quartier, dernière
 * livraison, organisation)") et §2 (colonnes de livraisons).
 *
 * Étape ajoutée en préparant le Patch 2 (pas décrite comme telle dans le
 * prompt, mais nécessaire en amont : la confirmation famille/bénévole
 * suppose que la ligne Livraison existe déjà) — voir échange du
 * 31/08/2026.
 */
class LivraisonGenerationService
{
    /**
     * Familles éligibles à une campagne — dossier validé uniquement (même
     * restriction que FamilleVerificationService::envoyerParLot(), qui ne
     * cible que les dossiers 'Validé'), triées par critère métier
     * (criticité décroissante par défaut, "dernière livraison" via un
     * sous-select car ce n'est PAS une colonne de familles — voir
     * dateDerniereLivraisonSql() ci-dessous).
     *
     * $campagne, si fournie, exclut les familles ayant DÉJÀ une Livraison
     * pour CETTE campagne précise (évite de les re-proposer à la sélection
     * une fois déjà générées) — n'exclut PAS les familles bénéficiaires
     * d'une autre campagne, ni les conflits etudiant/est_hotel à ce stade :
     * ces familles doivent rester visibles pour que l'admin voie qu'elles
     * existent ; le conflit n'est signalé qu'au moment de la génération
     * effective (voir genererPour()), pas avant, pour ne pas les faire
     * disparaître silencieusement de l'écran de sélection.
     *
     * @param array{criticite_min?: int, id_quartier?: int, id_organisation?: int} $filtres
     */
    public function eligibles(array $filtres = [], ?Campagne $campagne = null): Builder
    {
        $query = Famille::query()
            ->where('etat_dossier', 'Validé')
            ->select('familles.*')
            ->selectSub($this->dateDerniereLivraisonSql(), 'derniere_livraison_le');

        if (!empty($filtres['criticite_min'])) {
            $query->where('criticite', '>=', $filtres['criticite_min']);
        }
        if (!empty($filtres['id_quartier'])) {
            $query->where('id_quartier', $filtres['id_quartier']);
        }
        if (!empty($filtres['id_organisation'])) {
            $query->where('id_organisation', $filtres['id_organisation']);
        }

        if ($campagne) {
            $query->whereDoesntHave('livraisons', function ($q) use ($campagne) {
                $q->where('id_campagne', $campagne->id);
            });
        }

        return $query->orderByDesc('criticite')->orderBy('derniere_livraison_le');
    }

    /**
     * "Dernière livraison" n'est pas une colonne de familles — calculée en
     * sous-requête : date de campagne la plus récente pour laquelle cette
     * famille a une livraison au statut 'livree'. NULL (jamais livré) est
     * trié en premier par orderBy() ci-dessus (comportement MySQL par
     * défaut : NULL avant les valeurs pour un ASC), ce qui correspond au
     * besoin métier ("familles jamais livrées d'abord").
     */
    private function dateDerniereLivraisonSql(): \Illuminate\Database\Eloquent\Builder
    {
        return Livraison::query()
            ->join('campagnes', 'campagnes.id', '=', 'livraisons.id_campagne')
            ->whereColumn('livraisons.id_famille', 'familles.id')
            ->where('livraisons.statut', 'livree')
            ->selectRaw('MAX(campagnes.date_livraison)');
    }

    /**
     * Génère une ligne Livraison par famille sélectionnée pour cette
     * campagne — snapshot nombre_personnes/poids_kg/note_besoins_speciaux
     * au moment de l'appel (voir prompt §2 : "snapshot at generation
     * time"). Une famille déjà pourvue d'une Livraison pour CETTE
     * campagne (double clic, re-sélection) est silencieusement sautée
     * plutôt que dupliquée.
     *
     * Les familles etudiant ET est_hotel à la fois sont exclues de la
     * génération et renvoyées séparément dans `conflits` plutôt que de
     * choisir un taux au hasard (décision du 31/08/2026, voir
     * Livraison::calculerPoidsKg()) — à l'admin de corriger le dossier
     * (FamillesController) puis relancer la génération pour ces familles.
     *
     * @param int[] $idsFamilles
     * @return array{livraisons: Collection<int, Livraison>, conflits: Collection<int, Famille>, deja_existantes: int}
     */
    public function genererPour(Campagne $campagne, array $idsFamilles): array
    {
        $familles = Famille::whereIn('id', $idsFamilles)->get();

        $dejaGenerees = Livraison::where('id_campagne', $campagne->id)
            ->whereIn('id_famille', $idsFamilles)
            ->pluck('id_famille')
            ->all();

        $conflits = collect();
        $livraisons = collect();
        $dejaExistantesCount = 0;

        foreach ($familles as $famille) {
            if (in_array($famille->id, $dejaGenerees, true)) {
                $dejaExistantesCount++;
                continue;
            }

            if ($famille->etudiant && $famille->est_hotel) {
                $conflits->push($famille);
                continue;
            }

            $nombrePersonnes = $famille->nombre_adulte + $famille->nombre_enfant;

            $livraisons->push(Livraison::create([
                'id_famille' => $famille->id,
                'id_campagne' => $campagne->id,
                'statut' => 'non_assignee',
                'statut_conditionnement' => 'en_attente',
                'nombre_personnes' => $nombrePersonnes,
                'poids_kg' => Livraison::calculerPoidsKg($famille, $campagne, $nombrePersonnes),
                'note_besoins_speciaux' => $famille->specificites,
                'statut_contact' => 'a_contacter',
            ]));
        }

        return ['livraisons' => $livraisons, 'conflits' => $conflits, 'deja_existantes' => $dejaExistantesCount];
    }
}
