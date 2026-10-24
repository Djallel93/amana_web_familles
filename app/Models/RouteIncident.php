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
 * @property int|null $id_route          null pour les types sans tournée (retrait_hq_non_livre, se_deplace_remplace, types de campagne ou de famille)
 * @property int|null $id_campagne       renseigné quand id_route est null
 * @property string   $type            voir TYPES (21 types) — dont 12 détectés automatiquement, voir TYPES_DETECTES
 * @property int|null $id_livraison    renseigné pour les types qui concernent UNE famille (livraison_ignoree, packaging_annule, famille_non_geocodee…)
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
    public const TYPES = [
        'benevole_absent', 'capacite', 'chargement_termine', 'livraison_ignoree', 'packaging_annule', 'chargement_annule',
        'retrait_hq_non_livre', 'se_deplace_remplace', 'chauffeur_indisponible',
        // 23/10/2026 — détectés automatiquement (IncidentDetectionService), voir TYPES_DETECTES.
        'famille_non_geocodee', 'livraisons_non_couvertes', 'famille_injoignable', 'tournee_non_demarree',
        'packaging_en_retard', 'chargement_en_retard', 'retrait_hq_en_retard', 'email_echec', 'job_echec',
        'collecte_insuffisante', 'chauffeur_retire', 'chauffeurs_insuffisants',
    ];

    /**
     * Types levés SANS action d'un utilisateur, par IncidentDetectionService (23/10/2026) : situations
     * qui existaient déjà dans le domaine mais ne déclenchaient rien (famille non géocodée, chauffeur
     * qui ne démarre pas, email en échec…). `signale_par` vaut 0 pour eux. Le type `capacite` est lui
     * aussi levé automatiquement (tournée au-dessus de la capacité de son véhicule) mais reste un
     * type manuel historique.
     */
    public const TYPES_DETECTES = [
        'famille_non_geocodee', 'livraisons_non_couvertes', 'famille_injoignable', 'tournee_non_demarree',
        'packaging_en_retard', 'chargement_en_retard', 'retrait_hq_en_retard', 'email_echec', 'job_echec',
        'collecte_insuffisante', 'chauffeur_retire', 'chauffeurs_insuffisants',
    ];

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
        // 11/10/2026 : un chauffeur imposé l'emporte sur « se déplace au QG » — voir LivraisonChangementService.
        'se_deplace_remplace' => 'Retrait QG remplacé par une livraison',
        // 18/10/2026 : un chauffeur retenu pour une tournée a répondu « Non » à « Revenez-vous au QG ? » — voir RetourQgService.
        'chauffeur_indisponible' => 'Chauffeur indisponible',
        // 23/10/2026 : incidents détectés automatiquement — voir IncidentDetectionService.
        'famille_non_geocodee' => 'Adresse non localisée',
        'livraisons_non_couvertes' => 'Familles sans tournée',
        'famille_injoignable' => 'Famille injoignable',
        'tournee_non_demarree' => 'Tournée non démarrée',
        'packaging_en_retard' => 'Packaging en retard',
        'chargement_en_retard' => 'Chargement en retard',
        'retrait_hq_en_retard' => 'Famille en retard au QG',
        'email_echec' => 'Email non envoyé',
        'job_echec' => 'Tâche en échec',
        'collecte_insuffisante' => 'Collecte insuffisante',
        'chauffeur_retire' => 'Chauffeur plus disponible',
        'chauffeurs_insuffisants' => 'Chauffeurs insuffisants',
    ];

    // Types pour lesquels `statut` est sans objet (jalon, pas alerte actionnable).
    public const TYPES_SANS_STATUT = ['chargement_termine'];

    /** @return BelongsTo<RouteLivraison, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(RouteLivraison::class, 'id_route');
    }

    /** @return BelongsTo<Livraison, $this> */
    public function livraison(): BelongsTo
    {
        return $this->belongsTo(Livraison::class, 'id_livraison');
    }

    /** @return BelongsTo<Personne, $this> */
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
            'se_deplace_remplace' => "{$famille} devait venir chercher son colis au QG, mais un chauffeur lui a été imposé : "
                . 'sa livraison à domicile remplace le retrait. La contacter pour l\'informer qu\'elle n\'a plus besoin de se déplacer, puis résoudre l\'incident.',
            'chauffeur_indisponible' => "{$chauffeur} a répondu « Non » à « Revenez-vous au QG ? » alors que la {$tournee} lui était déjà attribuée : "
                . 'il ne sera pas là pour la livrer. Réassigner la tournée à un chauffeur au véhicule de même type ou plus grand, ou la répartir entre plusieurs chauffeurs.',
            'famille_non_geocodee' => "L'adresse de {$famille} n'a pas pu être localisée (coordonnées manquantes) : "
                . 'elle est exclue du calcul des tournées tant que son adresse n\'est pas corrigée.',
            'livraisons_non_couvertes' => 'Des familles confirmées, à livrer à domicile, ne sont dans aucune tournée : '
                . 'aucun chauffeur ni véhicule n\'a pu les prendre, ou leur adresse n\'est pas localisée. Elles ne seront pas livrées en l\'état.',
            'famille_injoignable' => "{$famille} est restée injoignable depuis plusieurs heures : sa disponibilité n'est pas confirmée, "
                . 'elle ne sera donc pas livrée tant qu\'elle n\'a pas été jointe.',
            'tournee_non_demarree' => "La {$tournee} de {$chauffeur} est chargée mais n'est pas partie alors que son créneau a commencé : "
                . 'les familles attendent leur colis.',
            'packaging_en_retard' => "Les colis de la {$tournee} ne sont pas tous prêts alors que son créneau est sur le point de commencer (ou a commencé) : "
                . 'le chargement ne peut pas démarrer.',
            'chargement_en_retard' => "La {$tournee} est prête à charger mais n'a pas été chargée alors que son créneau a commencé : "
                . "{$chauffeur} attend peut-être au QG.",
            'retrait_hq_en_retard' => "{$famille} devait venir chercher son colis au QG, mais n'est pas passée à l'heure de son rendez-vous "
                . 'et n\'est pas marquée « Non livré ». La contacter, ou la marquer absente.',
            'email_echec' => "Un email n'a pas pu être envoyé : le destinataire n'a donc peut-être pas reçu son information. "
                . 'Le détail de l\'erreur est dans les notes.',
            'job_echec' => 'Une tâche automatique (localisation d\'une adresse, synchronisation du contact Google) a échoué après plusieurs tentatives. '
                . 'Le détail de l\'erreur est dans les notes.',
            'collecte_insuffisante' => 'La collecte pesée ne couvre pas tous les colis restant à conditionner : '
                . 'il manque de quoi préparer toutes les familles au poids moyen actuel.',
            'chauffeur_retire' => "{$chauffeur}, affecté(e) à la {$tournee}, n'est plus confirmé(e) disponible pour cette journée : "
                . 'la tournée n\'a plus de chauffeur sûr.',
            'chauffeurs_insuffisants' => 'Il y a trop peu de chauffeurs confirmés pour le nombre de familles à livrer à domicile : '
                . 'certaines ne pourront pas être couvertes.',
            default => 'Incident de tournée.',
        };

        return $texte;
    }

    /**
     * Guides de résolution pas à pas (23/10/2026) — affichés dans la fenêtre de détail d'un incident
     * (section « Guide de résolution »). Une étape par ligne, numérotée ; les noms d'écrans sont ceux du
     * menu de l'application. `chargement_termine` n'en a pas : c'est un jalon, pas une alerte.
     */
    public const GUIDES = [
        'benevole_absent' => "1. Appelez le chauffeur (numéro ci-dessus) pour confirmer qu'il est bien absent.\n"
            . "2. Cliquez sur « Résoudre » : les arrêts restants de sa tournée sont replacés sur d'autres tournées.\n"
            . "3. Regardez le résultat : s'il reste des familles non couvertes, un incident « Familles sans tournée » est ouvert — traitez-le.",
        'capacite' => "1. Regardez le poids de la tournée et la capacité de son véhicule.\n"
            . "2. Choisissez : retirer quelques familles (elles repasseront à planifier), scinder la tournée en deux, ou la confier à un véhicule plus grand.\n"
            . '3. Le chauffeur est prévenu automatiquement de toute modification.',
        'livraison_ignoree' => "1. Appelez le chauffeur pour comprendre pourquoi l'arrêt a été ignoré (famille absente, adresse introuvable…).\n"
            . "2. Choisissez la suite : remettre la famille à planifier, l'ajouter à une tournée, la faire venir au QG, ou l'imposer à un chauffeur.",
        'packaging_annule' => "1. Rien à faire de votre côté : l'équipe packaging reprend le colis.\n"
            . "2. L'incident se résout tout seul quand le colis est de nouveau prêt. Si la tournée doit partir sans lui, retirez la famille de la tournée depuis l'écran Chargement.",
        'chargement_annule' => "1. Le chargement a été annulé par l'équipe chargement (confirmé par erreur).\n"
            . "2. Vérifiez que le chauffeur n'est pas déjà parti (appelez-le au besoin).\n"
            . "3. Quand l'équipe chargement confirme de nouveau la tournée, cet incident se résout automatiquement.",
        'retrait_hq_non_livre' => "1. Appelez la famille (numéro ci-dessus) pour savoir si elle peut encore venir.\n"
            . "2. Si oui : « La famille peut revenir » remet son retrait à « Prête ».\n"
            . '3. Sinon : « La livrer à domicile » la repasse en livraison, à planifier dans une tournée.',
        'se_deplace_remplace' => "1. Un chauffeur a été imposé à cette famille : elle n'a plus à venir au QG.\n"
            . "2. Si elle a un email, elle est prévenue automatiquement ; sinon appelez-la (numéro ci-dessus).\n"
            . '3. Cliquez sur « Famille prévenue » une fois fait. Pour revenir en arrière, choisissez « Annuler la livraison imposée ».',
        'chauffeur_indisponible' => "1. Le chauffeur a répondu « Non » à « Revenez-vous au QG ? » : il ne livrera pas cette tournée.\n"
            . "2. Réassignez-la à un chauffeur au véhicule de même type ou plus grand, ou répartissez-la entre plusieurs chauffeurs.\n"
            . "3. S'il est finalement revenu, utilisez « Le remettre dans le pool ».",
        'famille_non_geocodee' => "1. Ouvrez la fiche de la famille et vérifiez son adresse (rue, code postal, ville).\n"
            . "2. Corrigez l'adresse si besoin, puis cliquez sur « Relancer la localisation ».\n"
            . "3. L'incident se résout tout seul dès que l'adresse est localisée.",
        'livraisons_non_couvertes' => "1. Regardez la liste des familles et la cause probable indiquée.\n"
            . "2. Pas assez de chauffeurs : confirmez de nouvelles disponibilités, puis relancez la génération des tournées (Campagne → Générer les tournées).\n"
            . "3. Adresse non localisée : corrigez la fiche de la famille.\n"
            . '4. En dernier recours : « Faire venir au QG » bascule toutes ces familles en retrait au QG.',
        'famille_injoignable' => "1. Réessayez de joindre la famille (numéros ci-dessus), à une autre heure ou par SMS.\n"
            . "2. Jointe : « Remettre à contacter » la replace dans le suivi des contacts pour confirmer sa disponibilité.\n"
            . "3. Toujours injoignable : « Retirer de la campagne », ou ignorez l'incident pour la garder en attente.",
        'tournee_non_demarree' => "1. Appelez le chauffeur (numéro ci-dessus) : est-il parti, en retard, bloqué ?\n"
            . "2. S'il ne peut pas partir : réassignez la tournée depuis l'écran Chargement, ou signalez-le absent.\n"
            . "3. Résolvez l'incident quand la tournée a démarré.",
        'packaging_en_retard' => "1. Ouvrez l'écran Packaging : quels colis de cette tournée ne sont pas prêts ?\n"
            . "2. Demandez à l'équipe packaging de les préparer en priorité (« Préparation prioritaire »).\n"
            . "3. Si la collecte manque, vérifiez l'incident « Collecte insuffisante ».",
        'chargement_en_retard' => "1. Appelez l'équipe chargement : les colis sont prêts, pourquoi la tournée n'est-elle pas chargée ?\n"
            . "2. Prévenez le chauffeur (numéro ci-dessus) de l'attente.\n"
            . "3. L'incident se résout dès que le chargement est confirmé.",
        'retrait_hq_en_retard' => "1. Appelez la famille (numéro ci-dessus) : est-elle en route ?\n"
            . "2. Si elle ne vient pas, marquez-la « Non livré (absent) » sur l'écran Retrait QG : cela ouvre l'incident de suite à donner.\n"
            . '3. Si elle est passée, marquez-la « Livré » : cet incident se résout tout seul.',
        'email_echec' => "1. Lisez le détail de l'erreur dans les notes (adresse invalide, serveur mail…).\n"
            . "2. Vérifiez l'adresse email de la famille sur sa fiche ; corrigez-la si besoin.\n"
            . "3. Renvoyez l'email avec « Renvoyer l'email » (retrait QG), ou prévenez la famille par téléphone.",
        'job_echec' => "1. Lisez le détail de l'erreur dans les notes.\n"
            . "2. Erreur de configuration (clé Google, quota) : corrigez-la dans les paramètres ou l'environnement.\n"
            . "3. Relancez ensuite l'action depuis la fiche de la famille (enregistrer l'adresse relance la localisation).",
        'collecte_insuffisante' => "1. Ouvrez l'écran Packaging : la carte « Couverture » montre le manque.\n"
            . "2. Deux options : collecter davantage, ou recalculer à un poids moyen réalisable (bouton de recalcul du poids moyen).\n"
            . "3. L'incident se résout tout seul quand la collecte suffit.",
        'chauffeur_retire' => "1. Appelez le chauffeur pour comprendre : empêchement, erreur de saisie ?\n"
            . "2. S'il est de nouveau disponible, demandez-lui de reconfirmer sa disponibilité.\n"
            . "3. Sinon réassignez la tournée depuis l'écran Chargement, puis résolvez l'incident.",
        'chauffeurs_insuffisants' => "1. Relancez les bénévoles pour obtenir davantage de disponibilités sur les journées concernées.\n"
            . "2. Envisagez de basculer des familles en retrait au QG (écran Contacts de la campagne).\n"
            . '3. Le seuil est réglable (INCIDENT_FAMILLES_MAX_PAR_CHAUFFEUR, voir docs/incidents.md).',
    ];

    /**
     * Guide de résolution du type de cet incident, ou null pour un jalon (chargement_termine).
     */
    public function guide(): ?string
    {
        return self::GUIDES[$this->type] ?? null;
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
