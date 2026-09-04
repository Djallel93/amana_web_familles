<?php
// app/Models/RouteIncident.php

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\Personne;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Notification;

/**
 * Événement méritant l'attention admin/gestionnaire, ou jalon suivi, sur
 * une tournée — voir create_route_incidents_table.php.
 *
 * @property int      $id
 * @property int      $id_route
 * @property string   $type            benevole_absent|capacite|chargement_termine|livraison_ignoree
 * @property int|null $id_livraison    renseigné uniquement pour type = livraison_ignoree
 * @property int      $signale_par
 * @property string|null $statut       ouvert|resolu — null pour type = chargement_termine
 * @property string|null $notes
 */
class RouteIncident extends Model
{
    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['id_route', 'type', 'id_livraison', 'signale_par', 'statut', 'notes'];

    public const TYPES = ['benevole_absent', 'capacite', 'chargement_termine', 'livraison_ignoree'];
    public const STATUTS = ['ouvert', 'resolu'];

    // Types pour lesquels `statut` est sans objet (jalon, pas alerte actionnable).
    public const TYPES_SANS_STATUT = ['chargement_termine'];

    public function route(): BelongsTo
    {
        return $this->belongsTo(RouteLivraison::class, 'id_route');
    }

    public function livraison(): BelongsTo
    {
        return $this->belongsTo(Livraison::class, 'id_livraison');
    }

    public function signalePar(): BelongsTo
    {
        return $this->belongsTo(Personne::class, 'signale_par');
    }

    public function scopeOuverts($query)
    {
        return $query->where('statut', 'ouvert');
    }

    /**
     * Notifie admin/gestionnaire à la création — voir le prompt du
     * 03/09/2026 §2.9/évenement urgent : centralisé ici (plutôt que
     * dans chacun des 4 points de création — ChargementController x3,
     * MaRouteController::signalerIgnoree()) pour qu'AUCUN futur point de
     * création n'oublie de notifier. Voir App\Notifications\
     * RouteIncidentNotification pour la sévérité par type.
     */
    protected static function booted(): void
    {
        static::created(function (RouteIncident $incident) {
            $destinataires = Personne::adminsDe()
                ->orWhere(fn ($q) => $q->avecRole('gestionnaire'))
                ->get();

            Notification::send($destinataires, new \App\Notifications\RouteIncidentNotification($incident));
        });
    }
}
