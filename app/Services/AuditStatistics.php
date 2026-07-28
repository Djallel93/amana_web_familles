<?php
// app/Services/AuditStatistics.php

declare(strict_types=1);

namespace App\Services;

use Amana\Shared\Contracts\ActivityStatisticsProvider;
use Amana\Shared\Helpers\AuditHelper;
use Amana\Shared\Models\AuditLog;
use Illuminate\Support\Collection;

/**
 * Service de calcul des statistiques d'utilisation de l'application —
 * repris quasi à l'identique de amana_web_planning (serieParJour,
 * repartitionPar, utilisateursActifs sont entièrement génériques). Seule
 * cartes() est adaptée : les métriques de Planning (échanges,
 * régénérations planning déclenchées par absence) n'ont pas d'équivalent
 * ici, remplacées par des métriques propres à Familles (dossiers créés/
 * modifiés, webhooks envoyés).
 *
 * Implémente Amana\Shared\Contracts\ActivityStatisticsProvider — lié dans
 * AppServiceProvider::register() pour que
 * Amana\Shared\Http\Controllers\ActivityStatsController (partagé) délègue
 * son calcul ici.
 */
class AuditStatistics implements ActivityStatisticsProvider
{
    public function computeAll(string $from, string $to): array
    {
        // Scopé à cette application — audit_logs est partagée entre
        // plusieurs apps AMANA (voir id_application).
        $logs = AuditLog::with('personne')
            ->where('id_application', AuditHelper::applicationId())
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->get();

        return [
            'serieParJour' => $this->serieParJour($logs, $from, $to),
            'parModule' => $this->repartitionPar($logs, 'module'),
            'parAction' => $this->repartitionPar($logs, 'action'),
            'utilisateursActifs' => $this->utilisateursActifs($logs),
            'cartes' => $this->cartes($logs),
        ];
    }

    private function serieParJour(Collection $logs, string $from, string $to): array
    {
        $parJour = $logs->groupBy(fn(AuditLog $l) => $l->created_at->toDateString());

        $serie = [];
        $curseur = \Carbon\Carbon::parse($from);
        $fin = \Carbon\Carbon::parse($to);

        while ($curseur->lte($fin)) {
            $date = $curseur->toDateString();
            $serie[] = [
                'date' => $date,
                'total' => $parJour->get($date, collect())->count(),
            ];
            $curseur->addDay();
        }

        return $serie;
    }

    private function repartitionPar(Collection $logs, string $champ): array
    {
        return $logs->groupBy($champ)
            ->map(fn(Collection $groupe, string $valeur) => [
                'valeur' => $valeur,
                'total' => $groupe->count(),
            ])
            ->values()
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    private function utilisateursActifs(Collection $logs, int $limite = 8): array
    {
        return $logs->filter(fn(AuditLog $l) => $l->user_id !== null)
            ->groupBy('user_id')
            ->map(function (Collection $groupe) {
                /** @var AuditLog $premiere */
                $premiere = $groupe->first();
                $personne = $premiere->personne;

                return [
                    'nom' => $personne ? "{$personne->prenom} {$personne->nom}" : 'Personne supprimée',
                    'total' => $groupe->count(),
                ];
            })
            ->sortByDesc('total')
            ->take($limite)
            ->values()
            ->all();
    }

    /**
     * Cartes de synthèse propres à Familles : connexions, dossiers créés/
     * modifiés, webhooks envoyés (géocodage + contact confondus, module
     * commence par 'familles_').
     */
    private function cartes(Collection $logs): array
    {
        $connexions = $logs->where('action', 'login')->count();
        $dossiersCrees = $logs->where('module', 'familles')->where('action', 'create')->count();
        $dossiersModifies = $logs->where('module', 'familles')->where('action', 'update')->count();
        $webhooksEnvoyes = $logs->filter(fn(AuditLog $l) => str_starts_with($l->module ?? '', 'familles_') && $l->action === 'webhook')->count();

        return [
            'totalActions' => $logs->count(),
            'connexions' => $connexions,
            'dossiersCrees' => $dossiersCrees,
            'dossiersModifies' => $dossiersModifies,
            'webhooksEnvoyes' => $webhooksEnvoyes,
            'utilisateursDistincts' => $logs->pluck('user_id')->filter()->unique()->count(),
        ];
    }
}
