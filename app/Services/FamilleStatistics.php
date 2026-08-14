<?php
// app/Services/FamilleStatistics.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Famille;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Service de calcul des statistiques métier des dossiers familles
 * (section 8.2 du prompt de migration, "Statistiques familles") : suit
 * exactement le même pattern que AuditStatistics — calculs à la volée
 * depuis les tables existantes, aucune table de statistiques dédiée.
 *
 * Distinct de AuditStatistics : celui-ci mesure l'ACTIVITÉ dans l'app
 * (qui fait quoi), celui-ci mesure les DOSSIERS eux-mêmes (combien, pour
 * qui, où, avec quelle criticité...).
 */
class FamilleStatistics
{
    public function computeAll(): array
    {
        $familles = Famille::with(['quartier.secteur.ville', 'documents'])->get();

        return [
            'parEtatDossier' => $this->repartitionParEtat($familles),
            'eligibilite' => $this->eligibilite($familles),
            'parQuartier' => $this->repartitionParQuartier($familles),
            'parVille' => $this->repartitionParVille($familles),
            'seDeplace' => $this->repartitionSeDeplace($familles),
            'etudiant' => $this->repartitionEtudiant($familles),
            'estHotel' => $this->repartitionEstHotel($familles),
            'evolutionFoyer' => $this->evolutionFoyer($familles),
            'cartes' => $this->cartes($familles),
        ];
    }

    private function repartitionParEtat(Collection $familles): array
    {
        $ordre = Famille::ETATS;

        return collect($ordre)->map(fn($etat) => [
            'valeur' => $etat,
            'total' => $familles->where('etat_dossier', $etat)->count(),
        ])->all();
    }

    private function eligibilite(Collection $familles): array
    {
        return [
            'zakatElFitr' => $familles->where('zakat_el_fitr', true)->count(),
            'sadaqa' => $familles->where('sadaqa', true)->count(),
            'aucune' => $familles->where('zakat_el_fitr', false)->where('sadaqa', false)->count(),
        ];
    }

    /**
     * Remplace repartitionParCriticite() le 13/08/2026 (demande : la
     * répartition par criticité n'apportait pas grand-chose une fois la
     * carte "Criticité moyenne" en place, la répartition géographique par
     * quartier est plus actionnable). "Non résolu" pour les familles sans
     * id_quartier, même logique que repartitionParVille() ci-dessous. Les
     * 10 quartiers les plus représentés seulement — au-delà le graphique en
     * barres horizontales devient illisible.
     */
    private function repartitionParQuartier(Collection $familles): array
    {
        return $familles
            ->groupBy(fn(Famille $f) => $f->quartier?->nom ?? 'Non résolu')
            ->map(fn(Collection $groupe, string $quartier) => [
                'valeur' => $quartier,
                'total' => $groupe->count(),
            ])
            ->values()
            ->sortByDesc('total')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Groupé par ville (via quartier→secteur→ville) — "Non résolu" pour les
     * familles sans id_quartier (attendu tant que les tables géo restent
     * vides, décision 6.7, ou tant que ResoudreAdresseFamille n'a pas encore
     * traité la ligne). Trié par volume décroissant.
     */
    private function repartitionParVille(Collection $familles): array
    {
        return $familles
            ->groupBy(fn(Famille $f) => $f->quartier?->secteur?->ville?->nom ?? 'Non résolu')
            ->map(fn(Collection $groupe, string $ville) => [
                'valeur' => $ville,
                'total' => $groupe->count(),
            ])
            ->values()
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    private function repartitionSeDeplace(Collection $familles): array
    {
        return [
            'seDeplace' => $familles->where('se_deplace', true)->count(),
            'neSeDeplacePas' => $familles->where('se_deplace', false)->count(),
        ];
    }

    /**
     * Ajoutés le 13/08/2026 pour les cartes "Caractéristiques" de
     * FamillesStatistiques.vue (étudiant / hôtel), même forme que
     * repartitionSeDeplace() ci-dessus.
     */
    private function repartitionEtudiant(Collection $familles): array
    {
        return [
            'etudiant' => $familles->where('etudiant', true)->count(),
            'nonEtudiant' => $familles->where('etudiant', false)->count(),
        ];
    }

    private function repartitionEstHotel(Collection $familles): array
    {
        return [
            'estHotel' => $familles->where('est_hotel', true)->count(),
            'nonHotel' => $familles->where('est_hotel', false)->count(),
        ];
    }

    /**
     * Évolution mensuelle du nombre d'adultes/enfants (somme des dossiers
     * créés ce mois-là) — sur les 12 derniers mois, y compris les mois à
     * zéro pour ne pas casser l'échelle du graphique.
     */
    private function evolutionFoyer(Collection $familles): array
    {
        $parMois = $familles->groupBy(fn(Famille $f) => $f->created_at->format('Y-m'));

        $serie = [];
        $curseur = Carbon::now()->startOfMonth()->subMonths(11);

        for ($i = 0; $i < 12; $i++) {
            $cle = $curseur->format('Y-m');
            $groupe = $parMois->get($cle, collect());

            $serie[] = [
                'mois' => $cle,
                'adultes' => $groupe->sum('nombre_adulte'),
                'enfants' => $groupe->sum('nombre_enfant'),
                'nouveauxDossiers' => $groupe->count(),
            ];

            $curseur->addMonth();
        }

        return $serie;
    }

    private function cartes(Collection $familles): array
    {
        $documentsIdentiteManquants = $familles->filter(
            fn(Famille $f) => !$f->documents->where('type', 'identity')->count()
        )->count();

        // "À traiter en priorité" : migré depuis le bandeau KPI de
        // familles/index.blade.php le 13/08/2026 (seule carte de ce bandeau
        // sans équivalent déjà présent ici — criticité moyenne et
        // répartition par statut existaient déjà). Même règle métier que
        // l'ancien FamillesController::index() : un problème de traitement
        // signalé (échec géocodage, etc.), ou une criticité élevée sur un
        // dossier pas encore refermé (Validé/Rejeté/Archivé = déjà traité,
        // peu importe sa criticité) — recalculée ici en pur PHP sur la
        // Collection déjà chargée plutôt qu'en SQL, cohérent avec le reste
        // de ce service.
        $aTraiterPriorite = $familles->filter(
            fn(Famille $f) => $f->probleme_traitement !== null
                || ($f->criticite >= 4 && !in_array($f->etat_dossier, ['Validé', 'Rejeté', 'Archivé'], true))
        )->count();

        return [
            'totalFamilles' => $familles->count(),
            'totalAdultes' => $familles->sum('nombre_adulte'),
            'totalEnfants' => $familles->sum('nombre_enfant'),
            'criticiteMoyenne' => $familles->isEmpty() ? 0 : round($familles->avg('criticite'), 1),
            'documentsIdentiteManquants' => $documentsIdentiteManquants,
            'aTraiterPriorite' => $aTraiterPriorite,
        ];
    }
}
