<?php
// config/auth.php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | Guard par défaut : 'web' (session + cookie).
    | Broker de reset mot de passe : 'personnes'.
    |
    | SSO : même provider/table/broker que amana_web_planning (ref_personnes,
    | password_reset_tokens partagées — voir section 6.2 du prompt de
    | migration). Un même compte staff peut se connecter aux deux apps avec
    | le même email/mot de passe ; ses rôles sont scopés par id_application
    | dans ref_roles, donc totalement indépendants d'une app à l'autre.
    |
    */

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'personnes',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'personnes',
        ],
    ],

    'providers' => [
        'personnes' => [
            'driver' => 'eloquent',
            'model' => App\Models\Personne::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | table : password_reset_tokens — table PARTAGÉE, possédée et migrée
    | uniquement par amana_web_planning (cette app ne la migre jamais).
    |
    */

    'passwords' => [
        'personnes' => [
            'provider' => 'personnes',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
            'connection' => env('AMANA_COMMUN_CONNECTION', 'commun'),
        ],
    ],

    'password_timeout' => 10800,

];
