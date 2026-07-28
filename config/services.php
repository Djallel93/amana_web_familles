<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Contacts (People API) — AMANA Familles
    |--------------------------------------------------------------------------
    |
    | Intégration directe (google/apiclient), remplace le webhook Make.com
    | de synchronisation contact (décision du 17/07/2026). client_id/secret
    | proviennent du projet GCP AMANA existant (déjà utilisé pour Calendar/
    | Geocoding) — créer un ID client OAuth 2.0 "Application Web" dédié si ce
    | n'est pas déjà fait, et déclarer redirect_uri ci-dessous dans ses URI
    | de redirection autorisées.
    |
    | Le refresh token obtenu via /admin/google-contacts/authorize N'EST PAS
    | stocké ici : il est chiffré en base (ref_settings, cle=
    | 'google_contacts_refresh_token', app='familles') — voir
    | App\Models\Setting::setEncrypted() et Admin\GoogleContactsController.
    | Ce choix (DB plutôt que .env) permet de le renouveler sans redéploiement.
    |
    | account_email est purement informatif (affichage/logs) — le compte
    | réellement autorisé est déterminé par la session Google utilisée lors
    | du consentement, pas par cette valeur.
    |
    */

    'google' => [
        'contacts' => [
            'client_id' => env('GOOGLE_CONTACTS_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CONTACTS_CLIENT_SECRET'),
            'redirect_uri' => env('GOOGLE_CONTACTS_REDIRECT_URI'),
            'account_email' => env('GOOGLE_CONTACTS_ACCOUNT_EMAIL', 'amana44.pole.social@gmail.com'),
        ],

        /*
        |----------------------------------------------------------------
        | Google Maps Geocoding
        |----------------------------------------------------------------
        |
        | Intégration directe (appel REST via la façade Http, pas de SDK
        | google/apiclient — inutile pour ce endpoint), remplace le webhook
        | Make.com de géocodage (décision du 17/07/2026, révisée par
        | rapport à la décision initiale de le laisser sur Make.com — le
        | coût de facturation GCP est accepté vu le faible volume, ~130
        | familles). Voir App\Services\GoogleGeocodingService.
        |
        | Contrairement à 'contacts' ci-dessus, PAS d'OAuth : la Geocoding
        | API est un endpoint au niveau du projet GCP, authentifié par une
        | simple clé API en query string. La clé doit être restreinte dans
        | Google Cloud Console à l'API Geocoding uniquement + à l'IP du
        | serveur applicatif (appel exclusivement côté serveur, jamais
        | exposé au frontend).
        |
        */

        'maps' => [
            'geocoding_api_key' => env('GOOGLE_MAPS_GEOCODING_API_KEY'),
        ],
    ],

];
