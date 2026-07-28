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
| configuré côté IONOS — à vérifier avant d'activer.
|
| use Illuminate\Support\Facades\Schedule;
| Schedule::command('familles:envoyer-verifications')->monthlyOn(1, '08:00');
|
*/
