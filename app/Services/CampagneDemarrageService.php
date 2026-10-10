<?php
// app/Services/CampagneDemarrageService.php

declare(strict_types=1);

namespace App\Services;

use App\Models\Campagne;
use App\Models\CampagneJournee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * « Démarrer la campagne » (06/10/2026) — fin de la phase « avant la
 * campagne » du hub. Bouton unique, une seule fois : il passe la campagne à
 * 'en_cours' (statut déjà prévu par l'enum, jamais posé jusqu'ici), puis :
 *
 *   - crée les tournées des familles IMPOSÉES (une par chauffeur, sans
 *     créneau ni fenêtre horaire : le chauffeur les fait quand il veut) ;
 *   - planifie les rendez-vous de retrait au QG des familles se_deplace de
 *     chaque journée et envoie UNE FOIS leur email (où/quand venir). C'est
 *     ce qui couvre aussi une campagne où toutes les familles se déplacent
 *     (aucune tournée à générer). La génération des routes, elle, ne touche
 *     plus aux retraits QG : sinon chaque génération par créneau
 *     renverrait l'email à toutes ces familles.
 *
 * Les familles confirmées APRÈS le démarrage sont traitées par
 * LivraisonChangementService::apresConfirmation().
 */
class CampagneDemarrageService
{
    /** Statuts de départ autorisés ('collecte' existe dans l'enum, pas encore utilisé). */
    private const STATUTS_DEMARRABLES = ['preparation', 'collecte'];

    public function __construct(
        private readonly RouteGenerationService $generation,
        private readonly RetraitHqSchedulingService $retraitHqScheduling,
        private readonly RetraitHqNotificationService $retraitHqNotification,
    ) {}

    /** Message de refus, ou null si la campagne peut démarrer. */
    public function raisonDeRefus(Campagne $campagne): ?string
    {
        if (!in_array($campagne->statut, self::STATUTS_DEMARRABLES, true)) {
            return 'Cette campagne est déjà démarrée (ou terminée).';
        }

        if (!$campagne->livraisons()->where('statut_contact', 'confirme')->exists()) {
            return 'Au moins une famille doit être confirmée pour démarrer la campagne.';
        }

        return null;
    }

    /**
     * @return array{routes_imposees: int, retraits_planifies: int}
     *
     * @throws \RuntimeException message prêt à afficher
     */
    public function demarrer(Campagne $campagne): array
    {
        $refus = $this->raisonDeRefus($campagne);
        if ($refus !== null) {
            throw new \RuntimeException($refus);
        }

        $avant = $campagne->statut;

        // Routes imposées + statut dans la même transaction : un QG non
        // configuré (exception) ne laisse pas une campagne à moitié démarrée.
        $routesImposees = DB::transaction(function () use ($campagne) {
            $routes = $this->generation->genererRoutesImposees($campagne);
            $campagne->update(['statut' => 'en_cours']);

            return count($routes);
        });

        audit('update', 'campagnes', $campagne->id, ['statut' => $avant], ['statut' => 'en_cours']);

        $retraits = 0;
        $emailsEnvoyes = 0;
        foreach (CampagneJournee::where('id_campagne', $campagne->id)->get() as $journee) {
            $planifiees = $this->retraitHqScheduling->planifierPour($campagne, $journee);

            Log::info('[CampagneDemarrageService] Retraits QG planifiés', [
                'id_campagne' => $campagne->id,
                'id_campagne_journee' => $journee->id,
                'retraits' => $planifiees->count(),
            ]);

            foreach ($planifiees as $livraison) {
                if ($this->retraitHqNotification->notifierPour($livraison)) {
                    $emailsEnvoyes++;
                }
                $retraits++;
            }
        }

        // Bilan du démarrage (09/10/2026, debug des emails de rendez-vous) : un écart entre
        // retraits planifiés et emails envoyés se lit ici, le détail par famille juste au-dessus.
        Log::info('[CampagneDemarrageService] Campagne démarrée', [
            'id_campagne' => $campagne->id,
            'routes_imposees' => $routesImposees,
            'retraits_planifies' => $retraits,
            'emails_retrait_envoyes' => $emailsEnvoyes,
        ]);

        return ['routes_imposees' => $routesImposees, 'retraits_planifies' => $retraits];
    }
}
