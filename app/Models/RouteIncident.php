<?php
// app/Models/RouteIncident.php

declare(strict_types=1);

namespace App\Models;

use Amana\Shared\Models\Personne;
use App\Notifications\RouteIncidentNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Notification;

/**
 * Événement méritant l'attention admin/gestionnaire, ou jalon suivi, sur
 * une tournée — voir create_route_incidents_table.php.
 *
 * @property int      $id
 * @property int|null $id_route          null pour type = retrait_hq_non_livre (pas de tournée)
 * @property int|null $id_campagne       renseigné quand id_route est null
 * @property string   $type            benevole_absent|capacite|chargement_annule|chargement_termine|livraison_ignoree|packaging_annule|retrait_hq_non_livre
 * @property int|null $id_livraison    renseigné pour type = livraison_ignoree ou packaging_annule
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

    protected $fillable = ['id_route', 'id_campagne', 'type', 'id_livraison', 'signale_par', 'statut', 'notes'];

    /**
     * 'packaging_annule' ajouté le 05/09/2026 (prompt §5.3) — voir
     * PackagingController::annulerConditionnement(). Actionnable comme
     * benevole_absent/capacite (statut ouvert/resolu, PAS dans
     * TYPES_SANS_STATUT ci-dessous) : contrairement à chargement_termine
     * (simple jalon), un packaging annulé mérite un suivi explicite —
     * l'équipe chargement doit savoir que ce colis n'est plus disponible
     * tant que gestionnaire/admin n'a pas marqué l'incident résolu.
     */
    public const TYPES = ['benevole_absent', 'capacite', 'chargement_termine', 'livraison_ignoree', 'packaging_annule', 'chargement_annule', 'retrait_hq_non_livre'];

    public const STATUTS = ['ouvert', 'ignore', 'resolu'];

    public const LABELS_STATUT = [
        'ouvert' => 'Ouvert',
        'ignore' => 'Fermé (ignoré)',
        'resolu' => 'Résolu',
    ];

    public const LABELS_TYPE = [
        'benevole_absent' => 'Bénévole absent',
        'capacite' => 'Capacité dépassée',
        'livraison_ignoree' => 'Livraison ignorée',
        'packaging_annule' => 'Packaging annulé',
        'chargement_termine' => 'Chargement terminé',
        // 06/10/2026 : chargement confirmé par erreur puis annulé par
        // l'équipe chargement — voir ChargementController::annulerChargement().
        'chargement_annule' => 'Chargement annulé',
        // 09/10/2026 : famille « Non livré (absent) » au Retrait QG — voir RetraitHqController.
        'retrait_hq_non_livre' => 'Retrait QG non livré',
    ];

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

    /**
     * Texte d'explication affiché dans la fenêtre de détail d'un incident
     * (section Incidents du hub de la campagne, 06/10/2026). S'appuie sur les
     * relations route.benevole, livraison.famille chargées par l'appelant.
     * Le guide de résolution (quoi faire, étape par étape) viendra dans une
     * évolution ultérieure — voir guide().
     */
    public function description(): string
    {
        $tournee = $this->route ? "tournée #{$this->route->id}" : 'une tournée';
        $chauffeur = $this->route?->benevole
            ? trim("{$this->route->benevole->prenom} {$this->route->benevole->nom}")
            : 'le chauffeur';
        $famille = $this->livraison?->famille
            ? trim("{$this->livraison->famille->prenom} {$this->livraison->famille->nom}")
            : 'une famille';

        $texte = match ($this->type) {
            'benevole_absent' => "{$chauffeur} est signalé(e) absent(e) pour la {$tournee}. "
                . 'Résoudre cet incident relance le clustering : les arrêts restants sont replacés sur d\'autres tournées.',
            'capacite' => "La {$tournee} de {$chauffeur} dépasse la capacité de son véhicule : "
                . 'des livraisons doivent être retirées ou réparties sur une autre tournée.',
            'livraison_ignoree' => "La livraison chez {$famille} a été ignorée par {$chauffeur} ({$tournee}). "
                . 'La famille n\'a pas reçu son colis.',
            'packaging_annule' => "Le packaging de {$famille} a été annulé après le chargement ({$tournee}) : "
                . 'le colis repart en préparation. L\'incident se résout tout seul quand le colis est de nouveau prêt.',
            'retrait_hq_non_livre' => "{$famille} ne s'est pas présentée au retrait QG (marquée « Non livré (absent) »). "
                . 'Son colis n\'a pas été remis : la famille peut revenir (statut remis à « Prête »), ou être livrée à domicile.',
            'chargement_annule' => "Le chargement de la {$tournee} de {$chauffeur} a été annulé par l'équipe chargement "
                . '(confirmé par erreur). La tournée est de nouveau « prête à charger » : vérifier que le chauffeur n\'est pas déjà parti.',
            default => 'Incident de tournée.',
        };

        return $texte;
    }

    /**
     * Guide de résolution — volontairement vide pour l'instant : il sera
     * enrichi dans une prochaine évolution (explications pas à pas pour
     * guider l'utilisateur), l'interface réserve déjà l'emplacement.
     */
    public function guide(): ?string
    {
        return null;
    }

    public function scopeOuverts($query)
    {
        return $query->where('statut', 'ouvert');
    }

    /**
     * Incidents d'une campagne (09/10/2026) : ceux de ses tournées, plus ceux
     * rattachés directement à la campagne (retrait_hq_non_livre, sans tournée).
     */
    public function scopeDeCampagne($query, int $idCampagne)
    {
        return $query->where(fn($q) => $q
            ->whereHas('route', fn($r) => $r->where('id_campagne', $idCampagne))
            ->orWhere('id_campagne', $idCampagne));
    }

    /** Campagne de l'incident, via sa tournée ou directement. */
    public function idCampagneEffectif(): ?int
    {
        return $this->id_campagne ?? data_get($this, 'route.id_campagne');
    }

    /**
     * Prévient admins et gestionnaires — à la création, et à la réouverture
     * d'un incident (09/10/2026) dont la notification avait été retirée.
     */
    public function notifierAdmins(): void
    {
        $destinataires = Personne::adminsDe()
            ->orWhere(fn($q) => $q->avecRole('gestionnaire'))
            ->get();

        Notification::send($destinataires, new RouteIncidentNotification($this));
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
        static::created(fn(RouteIncident $incident) => $incident->notifierAdmins());
    }
}
