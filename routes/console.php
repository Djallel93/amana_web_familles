<?php
// routes/console.php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tâches planifiées
|--------------------------------------------------------------------------
|
| Décommenter pour automatiser l'envoi mensuel des vérifications (décision
| 6.10) plutôt que de dépendre uniquement du bouton admin.verifications.envoyer.
| Nécessite que le cron Laravel (`* * * * * php artisan schedule:run`) soit
| configuré côté IONOS — confirmé actif le 11/08/2026 (voir README), donc
| peut être décommenté dès que souhaité.
|
| use Illuminate\Support\Facades\Schedule;
| Schedule::command('familles:envoyer-verifications')->monthlyOn(1, '08:00');
|
*/

use Illuminate\Support\Facades\Schedule;

// Purge quotidienne des demandes d'intake non confirmées après 48h (voir
// NettoyerDemandesAttente / IntakeAttenteService) — activée par défaut
// puisque le cron IONOS est confirmé actif (11/08/2026, voir README).
Schedule::command('familles:nettoyer-demandes-attente')->daily();

// Libération des verrous d'édition de dossiers périmés (19/09/2026, suite du
// Scénario 5 du chantier "polling live") — voir Famille::libererVerrousPerimes().
// Toutes les 5 minutes suffisent : le TTL du verrou est de 20 minutes et le
// battement de cœur de DetailPanel.vue le renouvelle toutes les 5 minutes tant
// que l'édition est active. withoutOverlapping : jamais deux nettoyages à la fois.
Schedule::command('familles:liberer-verrous-perimes')->everyFiveMinutes()->withoutOverlapping();
