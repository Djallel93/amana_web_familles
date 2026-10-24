<?php
// config/incidents.php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Détection automatique d'incidents (23/10/2026)
|--------------------------------------------------------------------------
|
| Seuils de IncidentDetectionService (commande `livraison:detecter-incidents`, planifiée toutes les
| 5 minutes dans routes/console.php — le cron `schedule:run` est installé par les workflows de
| déploiement). Chaque seuil se règle par variable d'environnement, sans toucher au code.
| Voir docs/incidents.md.
|
*/

return [
    // Une tournée « chargée » qui n'est pas partie X minutes après le DÉBUT de son créneau.
    'retard_demarrage_minutes' => (int) env('INCIDENT_RETARD_DEMARRAGE_MINUTES', 30),

    // Une tournée dont les colis ne sont pas tous prêts à moins de X minutes du début de son créneau.
    'anticipation_packaging_minutes' => (int) env('INCIDENT_ANTICIPATION_PACKAGING_MINUTES', 30),

    // Une tournée « prête à charger » qui n'est pas chargée X minutes après le début de son créneau.
    'retard_chargement_minutes' => (int) env('INCIDENT_RETARD_CHARGEMENT_MINUTES', 0),

    // Une famille attendue au QG qui n'est toujours pas passée X minutes après l'heure de son rendez-vous.
    'retard_retrait_hq_minutes' => (int) env('INCIDENT_RETARD_RETRAIT_HQ_MINUTES', 30),

    // Une famille « injoignable » depuis plus de X heures, sans nouveau contact.
    'injoignable_delai_heures' => (int) env('INCIDENT_INJOIGNABLE_DELAI_HEURES', 24),

    // Au-delà de X familles à domicile par chauffeur confirmé, les chauffeurs sont jugés insuffisants.
    'familles_max_par_chauffeur' => (int) env('INCIDENT_FAMILLES_MAX_PAR_CHAUFFEUR', 25),
];
