<?php
// app/Services/IncidentDetectionService.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Models\VehiculeType;
use Amana\Shared\Services\NotificationCenterService;
use App\Models\Campagne;
use App\Models\Livraison;
use App\Models\RouteIncident;
use App\Models\RouteLivraison;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Détection automatique des incidents « potentiels » (23/10/2026, voir docs/incidents.md).
 *
 * Avant ce service, seuls des gestes humains levaient un incident (chauffeur absent, livraison ignorée…) ;
 * des situations pourtant détectables restaient muettes : famille non géocodée, chauffeur qui ne démarre
 * pas, packaging en retard, collecte insuffisante… Chaque détecteur ci-dessous décrit UNE situation et
 * renvoie des « candidats » ; reconcilier() les compare aux incidents déjà levés.
 *
 * Idempotence : chaque candidat porte une clé `[auto:type:cle]` écrite dans les notes de l'incident.
 *  - clé déjà présente en ouvert, fermé (ignoré) ou résolu À LA MAIN → rien n'est recréé (un humain a tranché) ;
 *  - clé présente mais résolue AUTOMATIQUEMENT (marqueur `[auto-resolu]`) → recréée si le problème revient ;
 *  - incident automatique encore ouvert dont le problème a disparu → résolu tout seul (`[auto-resolu]`).
 * Les types levés sur événement (email_echec, job_echec) ne sont jamais auto-résolus : rien à re-tester.
 *
 * Lancé toutes les 5 minutes par `livraison:detecter-incidents` pour les campagnes en cours.
 * Seuils : config/incidents.php.
 */
class IncidentDetectionService
{
    public const MARQUEUR_AUTO_RESOLU = '[auto-resolu]';

    /** Types réévalués à chaque passage (les autres, levés sur événement, ne le sont pas). */
    private const TYPES_ETAT = [
        'famille_non_geocodee', 'livraisons_non_couvertes', 'famille_injoignable', 'tournee_non_demarree',
        'packaging_en_retard', 'chargement_en_retard', 'retrait_hq_en_retard', 'collecte_insuffisante',
        'chauffeur_retire', 'chauffeurs_insuffisants', 'capacite',
    ];

    public function __construct(
        private readonly NotificationCenterService $notificationCenter,
        private readonly ChauffeursConfirmesService $chauffeursConfirmes,
        private readonly CouvertureCollecteService $couverture,
    ) {}

    /**
     * @return array{crees: int, auto_resolus: int}
     */
    public function detecter(Campagne $campagne, ?CarbonInterface $maintenant = null): array
    {
        $maintenant ??= Carbon::now();

        $candidats = array_merge(
            $this->famillesNonGeocodees($campagne),
            $this->livraisonsNonCouvertes($campagne),
            $this->famillesInjoignables($campagne, $maintenant),
            $this->retraitsEnRetard($campagne, $maintenant),
            $this->tourneesEnRetard($campagne, $maintenant),
            $this->capacites($campagne),
            $this->chauffeursRetires($campagne),
            $this->collecteInsuffisante($campagne),
            $this->chauffeursInsuffisants($campagne),
        );

        return $this->reconcilier($campagne, $candidats);
    }

    /**
     * Lève un incident de façon idempotente à partir d'un événement (email en échec, job en échec).
     * Même clé = même incident : un email qui échoue dix fois pour la même famille n'ouvre qu'un incident.
     */
    public function lever(Campagne|int $campagne, string $type, string $cle, string $notes, ?int $idLivraison = null, ?int $idRoute = null): ?RouteIncident
    {
        $idCampagne = $campagne instanceof Campagne ? $campagne->id : $campagne;
        $cle = Str::slug($cle, '_');
        $candidat = ['type' => $type, 'cle' => $cle, 'notes' => $notes, 'id_livraison' => $idLivraison, 'id_route' => $idRoute];
        $existants = $this->incidentsAutomatiques($idCampagne);

        if (!$this->doitCreer($existants, $candidat)) {
            return null;
        }

        return $this->creer($idCampagne, $candidat);
    }

    /** Un email lié à une livraison n'a pas pu partir (RetraitHqNotificationService, ContactTokenService). */
    public function signalerEchecEmail(Livraison $livraison, string $contexte, string $message): void
    {
        $this->lever($livraison->id_campagne, 'email_echec', 'liv' . $livraison->id . '-' . $contexte,
            "Email « {$contexte} » non envoyé : " . mb_substr($message, 0, 300), $livraison->id);
    }

    /** Un job en file a définitivement échoué : l'incident est rattaché aux campagnes actives de la famille. */
    public function signalerEchecJob(int $idFamille, string $job, string $message): void
    {
        $livraisons = Livraison::where('id_famille', $idFamille)
            ->whereHas('campagne', fn($q) => $q->whereIn('statut', ['preparation', 'en_cours']))
            ->get();

        foreach ($livraisons as $livraison) {
            $this->lever($livraison->id_campagne, 'job_echec', 'liv' . $livraison->id . '-' . $job,
                "Tâche « {$job} » en échec : " . mb_substr($message, 0, 300), $livraison->id);
        }
    }

    // ── Détecteurs ──────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function famillesNonGeocodees(Campagne $campagne): array
    {
        return $this->livraisonsActives($campagne)
            ->with('famille:id,nom,prenom,latitude,longitude')
            ->get()
            ->filter(fn(Livraison $l) => $l->famille !== null && ($l->famille->latitude === null || $l->famille->longitude === null))
            ->map(fn(Livraison $l) => [
                'type' => 'famille_non_geocodee', 'cle' => 'liv' . $l->id, 'id_livraison' => $l->id, 'id_route' => null,
                'notes' => 'Aucune coordonnée pour cette adresse.',
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function livraisonsNonCouvertes(Campagne $campagne): array
    {
        // Sans aucune tournée, la génération n'a simplement pas eu lieu : ce n'est pas encore un incident.
        $aDesTournees = RouteLivraison::where('id_campagne', $campagne->id)->where('statut', '!=', 'annulee')->exists();
        if (!$aDesTournees) {
            return [];
        }

        $orphelines = $this->livraisonsActives($campagne)
            ->where('statut', 'non_assignee')
            ->where('se_deplace', false)
            ->whereNull('id_benevole_impose')
            ->with('famille:id,nom,prenom,latitude,longitude')
            ->get()
            // Les familles sans coordonnées ont leur propre incident (famille_non_geocodee).
            ->filter(fn(Livraison $l) => $l->famille?->latitude !== null && $l->famille?->longitude !== null);

        if ($orphelines->isEmpty()) {
            return [];
        }

        return [[
            'type' => 'livraisons_non_couvertes', 'cle' => 'camp' . $campagne->id, 'id_livraison' => null, 'id_route' => null,
            'notes' => $orphelines->count() . ' famille(s) sans tournée : '
                . $orphelines->take(15)->map(fn(Livraison $l) => trim("{$l->famille->prenom} {$l->famille->nom}") . " (#{$l->id})")->implode(', ')
                . ($orphelines->count() > 15 ? '…' : ''),
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function famillesInjoignables(Campagne $campagne, CarbonInterface $maintenant): array
    {
        $limite = $maintenant->copy()->subHours((int) config('incidents.injoignable_delai_heures'));

        return Livraison::where('id_campagne', $campagne->id)
            ->where('statut_contact', 'injoignable')
            ->where('updated_at', '<=', $limite)
            ->get()
            ->map(fn(Livraison $l) => [
                'type' => 'famille_injoignable', 'cle' => 'liv' . $l->id, 'id_livraison' => $l->id, 'id_route' => null,
                'notes' => 'Injoignable depuis le ' . $l->updated_at?->timezone(config('app.timezone'))->format('d/m H:i') . '.',
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function retraitsEnRetard(Campagne $campagne, CarbonInterface $maintenant): array
    {
        $limite = $maintenant->copy()->subMinutes((int) config('incidents.retard_retrait_hq_minutes'));

        return Livraison::where('id_campagne', $campagne->id)
            ->where('se_deplace', true)
            ->where('statut_contact', 'confirme')
            ->where('statut', '!=', 'ignoree')
            ->whereNull('statut_retrait_hq')
            ->whereNotNull('heure_arrivee_prevue_hq')
            ->where('heure_arrivee_prevue_hq', '<=', $limite)
            ->get()
            ->map(fn(Livraison $l) => [
                'type' => 'retrait_hq_en_retard', 'cle' => 'liv' . $l->id, 'id_livraison' => $l->id, 'id_route' => null,
                'notes' => 'Rendez-vous prévu à ' . $l->heure_arrivee_prevue_hq->timezone(config('app.timezone'))->format('H:i') . '.',
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function tourneesEnRetard(Campagne $campagne, CarbonInterface $maintenant): array
    {
        $candidats = [];
        $routes = RouteLivraison::where('id_campagne', $campagne->id)
            ->whereIn('statut', ['planifiee', 'chargement', 'charge'])
            ->whereNotNull('creneau')
            ->with(['campagneJournee', 'etapes.livraison:id,statut_conditionnement'])
            ->get();

        foreach ($routes as $route) {
            $debut = $this->debutCreneau($route, $campagne);
            if ($debut === null) {
                continue;
            }
            $cle = 'route' . $route->id;
            $livraisons = $route->etapes->pluck('livraison')->filter();
            $toutPret = $livraisons->isNotEmpty() && $livraisons->every(fn($l) => $l->statut_conditionnement === 'prete');

            if (in_array($route->statut, ['planifiee', 'chargement'], true) && !$toutPret
                && $maintenant->greaterThanOrEqualTo($debut->copy()->subMinutes((int) config('incidents.anticipation_packaging_minutes')))) {
                $candidats[] = ['type' => 'packaging_en_retard', 'cle' => $cle, 'id_route' => $route->id, 'id_livraison' => null,
                    'notes' => $livraisons->where('statut_conditionnement', '!=', 'prete')->count() . ' colis non prêt(s) ; créneau à ' . $debut->format('H:i') . '.'];
            }
            if ($route->statut === 'chargement' && $toutPret
                && $maintenant->greaterThanOrEqualTo($debut->copy()->addMinutes((int) config('incidents.retard_chargement_minutes')))) {
                $candidats[] = ['type' => 'chargement_en_retard', 'cle' => $cle, 'id_route' => $route->id, 'id_livraison' => null,
                    'notes' => 'Colis prêts, chargement non confirmé ; créneau à ' . $debut->format('H:i') . '.'];
            }
            if ($route->statut === 'charge'
                && $maintenant->greaterThanOrEqualTo($debut->copy()->addMinutes((int) config('incidents.retard_demarrage_minutes')))) {
                $candidats[] = ['type' => 'tournee_non_demarree', 'cle' => $cle, 'id_route' => $route->id, 'id_livraison' => null,
                    'notes' => 'Créneau commencé à ' . $debut->format('H:i') . ', tournée toujours chargée.'];
            }
        }

        return $candidats;
    }

    /** @return list<array<string, mixed>> */
    private function capacites(Campagne $campagne): array
    {
        $candidats = [];
        $routes = RouteLivraison::where('id_campagne', $campagne->id)
            ->whereIn('statut', RouteLivraison::STATUTS_PAS_PARTIE)
            ->with('vehiculeType')
            ->get();

        foreach ($routes as $route) {
            $vehicule = $route->vehiculeType;
            $capacite = $vehicule instanceof VehiculeType ? (float) $vehicule->capacite_kg : 0.0;
            if ($capacite > 0 && (float) $route->poids_total_kg > $capacite) {
                $candidats[] = ['type' => 'capacite', 'cle' => 'route' . $route->id, 'id_route' => $route->id, 'id_livraison' => null,
                    'notes' => 'Poids ' . round((float) $route->poids_total_kg, 1) . ' kg pour une capacité de ' . round($capacite, 1) . ' kg.'];
            }
        }

        return $candidats;
    }

    /** @return list<array<string, mixed>> */
    private function chauffeursRetires(Campagne $campagne): array
    {
        $candidats = [];
        $routes = RouteLivraison::where('id_campagne', $campagne->id)
            ->whereIn('statut', RouteLivraison::STATUTS_PAS_PARTIE)
            ->whereNotNull('id_benevole')
            ->get();

        foreach ($routes as $route) {
            if (!$this->chauffeursConfirmes->estConfirme((int) $route->id_benevole, $route->id_campagne_journee, $campagne->id)) {
                $candidats[] = ['type' => 'chauffeur_retire', 'cle' => 'route' . $route->id, 'id_route' => $route->id, 'id_livraison' => null,
                    'notes' => 'Le chauffeur #' . $route->id_benevole . ' n\'est plus confirmé pour cette journée.'];
            }
        }

        return $candidats;
    }

    /** @return list<array<string, mixed>> */
    private function collecteInsuffisante(Campagne $campagne): array
    {
        $c = $this->couverture->calculer($campagne);
        if (!$c['collecte_demarree'] || $c['couverture'] === null || $c['couverture'] >= 1.0 || $c['reste_kg'] <= 0) {
            return [];
        }

        return [[
            'type' => 'collecte_insuffisante', 'cle' => 'camp' . $campagne->id, 'id_livraison' => null, 'id_route' => null,
            'notes' => 'Couverture ' . round($c['couverture'] * 100) . ' % : ' . round($c['disponible_kg'], 1) . ' kg disponibles pour ' . round($c['reste_kg'], 1) . ' kg à conditionner.',
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function chauffeursInsuffisants(Campagne $campagne): array
    {
        $candidats = [];
        $max = (int) config('incidents.familles_max_par_chauffeur');

        foreach ($campagne->journees()->get() as $journee) {
            $familles = Livraison::where('id_campagne', $campagne->id)
                ->where('id_campagne_journee', $journee->id)
                ->where('statut_contact', 'confirme')
                ->where('statut', '!=', 'ignoree')
                ->where('se_deplace', false)
                ->count();
            if ($familles === 0) {
                continue;
            }
            $chauffeurs = count($this->chauffeursConfirmes->pourJournee($journee->id, $campagne->id));
            if ($chauffeurs === 0 || $familles / $chauffeurs > $max) {
                $candidats[] = ['type' => 'chauffeurs_insuffisants', 'cle' => 'jour' . $journee->id, 'id_livraison' => null, 'id_route' => null,
                    'notes' => "{$familles} famille(s) à livrer pour {$chauffeurs} chauffeur(s) confirmé(s) le " . $journee->date->format('d/m') . " (seuil : {$max} par chauffeur)."];
            }
        }

        return $candidats;
    }

    // ── Rapprochement avec les incidents existants ──────────────────────

    /**
     * @param  list<array<string, mixed>>  $candidats
     * @return array{crees: int, auto_resolus: int}
     */
    private function reconcilier(Campagne $campagne, array $candidats): array
    {
        $existants = $this->incidentsAutomatiques($campagne->id);
        $crees = 0;

        foreach ($candidats as $candidat) {
            if ($this->doitCreer($existants, $candidat)) {
                $this->creer($campagne->id, $candidat);
                $crees++;
            }
        }

        $clesActuelles = array_map(fn($c) => $this->marqueur($c['type'], $c['cle']), $candidats);
        $autoResolus = 0;
        foreach ($existants as $incident) {
            if ($incident->statut !== 'ouvert' || !in_array($incident->type, self::TYPES_ETAT, true)) {
                continue;
            }
            if (!in_array($this->marqueurDe($incident), $clesActuelles, true)) {
                $incident->update([
                    'statut' => 'resolu',
                    'notes' => trim($incident->notes . "\n" . self::MARQUEUR_AUTO_RESOLU . ' La situation est rentrée dans l\'ordre.'),
                ]);
                $this->notificationCenter->resoudreParDonnee('id_incident', $incident->id);
                $autoResolus++;
            }
        }

        return ['crees' => $crees, 'auto_resolus' => $autoResolus];
    }

    /** @return Collection<int, RouteIncident> */
    private function incidentsAutomatiques(int $idCampagne)
    {
        return RouteIncident::deCampagne($idCampagne)
            ->where('notes', 'like', '%[auto:%')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, RouteIncident>  $existants
     * @param  array<string, mixed>  $candidat
     */
    private function doitCreer($existants, array $candidat): bool
    {
        $marqueur = $this->marqueur($candidat['type'], $candidat['cle']);
        $dernier = $existants->filter(fn(RouteIncident $i) => $this->marqueurDe($i) === $marqueur)->last();

        if ($dernier === null) {
            return true;
        }

        // Recréé seulement si le précédent s'est refermé tout seul : un humain qui a résolu ou ignoré a tranché.
        return $dernier->statut === 'resolu' && str_contains((string) $dernier->notes, self::MARQUEUR_AUTO_RESOLU);
    }

    /** @param array<string, mixed> $candidat */
    private function creer(int $idCampagne, array $candidat): RouteIncident
    {
        return RouteIncident::create([
            'id_route' => $candidat['id_route'] ?? null,
            'id_campagne' => $idCampagne,
            'type' => $candidat['type'],
            'id_livraison' => $candidat['id_livraison'] ?? null,
            'signale_par' => 0,
            'statut' => 'ouvert',
            'notes' => $this->marqueur($candidat['type'], $candidat['cle']) . ' ' . $candidat['notes'],
        ]);
    }

    private function marqueur(string $type, string $cle): string
    {
        return "[auto:{$type}:{$cle}]";
    }

    private function marqueurDe(RouteIncident $incident): ?string
    {
        return preg_match('/\[auto:[a-z_]+:[A-Za-z0-9_]+\]/', (string) $incident->notes, $m) ? $m[0] : null;
    }

    // ── Utilitaires ─────────────────────────────────────────────────────

    /** Livraisons confirmées qui doivent encore être servies. */
    private function livraisonsActives(Campagne $campagne)
    {
        return Livraison::where('id_campagne', $campagne->id)
            ->where('statut_contact', 'confirme')
            ->whereNotIn('statut', ['ignoree', 'livree']);
    }

    private function debutCreneau(RouteLivraison $route, Campagne $campagne): ?CarbonInterface
    {
        $jour = $route->campagneJournee?->date ?? $campagne->date_livraison;
        if ($jour === null || $route->creneau === null) {
            return null;
        }
        $heure = (int) explode('-', $route->creneau)[0];

        return Carbon::parse($jour->format('Y-m-d'), config('app.timezone'))->setTime($heure, 0);
    }
}
