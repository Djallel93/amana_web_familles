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
| Le cron Laravel (`* * * * * php artisan schedule:run`) est installé par les workflows de
| déploiement (voir docs/scheduled-tasks.md) — rien à configurer à la main sur IONOS.
|
| Décommenter pour automatiser l'envoi mensuel des vérifications (décision
| 6.10) plutôt que de dépendre uniquement du bouton admin.verifications.envoyer :
|
| Schedule::exec("{$phpPourCron} {$artisan} familles:envoyer-verifications")
|     ->monthlyOn(1, '08:00')->name('familles:envoyer-verifications');
|
*/

use Illuminate\Support\Facades\Schedule;

// PHP CLI absolu utilisé pour RE-lancer chaque commande planifiée (11/10/2026), au lieu de
// laisser Schedule::command() résoudre le binaire via Symfony PhpExecutableFinder : sur IONOS ce
// finder retombe sur « /usr/bin/php », souvent une version incompatible (exit 255), même quand le
// cron appelle le bon binaire. SCHEDULE_PHP_BINARY est injecté depuis la variable
// IONOS_PHP_CLI_PATH — la même que celle du cron installé par les workflows de déploiement —
// et lu via config('app.schedule_php_binary') : env() renverrait null une fois la config
// mise en cache. PHP_BINARY reste le repli en local (php artisan serve / tests).
$phpPourCron = (string) config('app.schedule_php_binary');
$artisan = base_path('artisan');

// Purge quotidienne des demandes d'intake non confirmées après 48h (voir
// NettoyerDemandesAttente / IntakeAttenteService).
Schedule::exec("{$phpPourCron} {$artisan} familles:nettoyer-demandes-attente")
    ->daily()
    ->name('familles:nettoyer-demandes-attente');

// Libération des verrous d'édition de dossiers périmés (19/09/2026, suite du
// Scénario 5 du chantier "polling live") — voir Famille::libererVerrousPerimes().
// Toutes les 5 minutes suffisent : le TTL du verrou est de 20 minutes et le
// battement de cœur de DetailPanel.vue le renouvelle toutes les 5 minutes tant
// que l'édition est active. withoutOverlapping : jamais deux nettoyages à la fois.
Schedule::exec("{$phpPourCron} {$artisan} familles:liberer-verrous-perimes")
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('familles:liberer-verrous-perimes');

// Détection automatique des incidents des campagnes en cours (23/10/2026) : famille non localisée,
// tournée qui ne démarre pas, packaging en retard, collecte insuffisante… voir docs/incidents.md.
// Aucun nouveau workflow : les workflows de déploiement installent déjà le cron « schedule:run ».
Schedule::exec("{$phpPourCron} {$artisan} livraison:detecter-incidents")
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('livraison:detecter-incidents');
