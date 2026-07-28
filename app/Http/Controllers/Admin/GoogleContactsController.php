<?php
// app/Http/Controllers/Admin/GoogleContactsController.php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Amana\Shared\Models\Setting;
use App\Services\GoogleContactsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Flux d'autorisation OAuth 2.0 unique pour Google Contacts (décision du
 * 17/07/2026). Le compte à autoriser est amana44.pole.social@gmail.com —
 * un admin doit être connecté à ce compte Google dans son navigateur au
 * moment de cliquer sur le lien /admin/google-contacts/authorize.
 *
 * L'ancien flux "out-of-band" (copier/coller un code affiché par Google)
 * a été retiré par Google — on utilise donc un vrai redirect_uri
 * applicatif (cette route callback), à déclarer AUSSI côté Google Cloud
 * Console (Identifiants → ID client OAuth → URI de redirection autorisés)
 * pour correspondre exactement à GOOGLE_CONTACTS_REDIRECT_URI.
 *
 * Protégé par le même middleware que le reste de /admin ('auth' +
 * 'role:admin') — voir routes/web.php.
 */
class GoogleContactsController extends Controller
{
    public function __construct(
        private readonly GoogleContactsService $googleContacts
    ) {
    }

    /**
     * Redirige vers l'écran de consentement Google. À usage ponctuel
     * (autorisation initiale, ou ré-autorisation si le refresh token est
     * révoqué/expiré) — pas un lien affiché en permanence dans l'UI.
     */
    public function redirect(): RedirectResponse
    {
        $client = $this->googleContacts->createClient();

        return redirect()->away($client->createAuthUrl());
    }

    /**
     * Callback appelé par Google après consentement. Échange le code
     * d'autorisation contre un refresh token et le stocke chiffré dans
     * ref_settings via Setting::setEncrypted().
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route('admin.activite.index')
                ->with('error', "Autorisation Google refusée : {$request->input('error')}");
        }

        $code = $request->input('code');

        if (!$code) {
            return redirect()->route('admin.activite.index')
                ->with('error', 'Code d\'autorisation Google manquant dans la réponse.');
        }

        $client = $this->googleContacts->createClient();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            return redirect()->route('admin.activite.index')
                ->with('error', "Échec de l'échange du code d'autorisation : {$token['error']}");
        }

        if (empty($token['refresh_token'])) {
            // Google ne renvoie un refresh_token qu'à la toute première
            // autorisation d'un compte pour cette appli OAuth — s'il manque,
            // c'est que l'accès a déjà été accordé par le passé (session
            // Google précédente, accès jamais révoqué). setPrompt('consent')
            // dans GoogleContactsService::createClient() est censé forcer son
            // renvoi systématique ; si ce message apparaît malgré tout,
            // révoquer l'accès existant avant de relancer le flux.
            return redirect()->route('admin.activite.index')->with('error',
                "Google n'a pas renvoyé de refresh token. Révoquez l'accès existant sur ".
                'https://myaccount.google.com/permissions (compte amana44.pole.social@gmail.com) '.
                'puis relancez l\'autorisation.'
            );
        }

        Setting::setEncrypted(
            'google_contacts_refresh_token',
            'familles',
            $token['refresh_token'],
            'Jeton Google Contacts (People API)',
            "Refresh token OAuth pour la synchronisation des contacts (compte amana44.pole.social@gmail.com). ".
            'Généré automatiquement via /admin/google-contacts/authorize — ne pas éditer manuellement.'
        );

        return redirect()->route('admin.activite.index')
            ->with('success', 'Compte Google Contacts autorisé avec succès.');
    }
}
