<?php
// app/Http/Controllers/GoogleContactsReverseSyncController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\GoogleContactsService;
use App\Services\ReverseSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Panneau "Sync retour Google Contacts" (Dossiers familles → bouton dédié,
 * voir familles/index.blade.php + resources/js/components/familles/
 * ReverseSyncPanel.vue) — décision du 14/08/2026, pendant du menu
 * "Synchronisation Contact → Feuille" de l'ancien système Google Apps
 * Script (amana_familles/Google_Sheets/views/dialogs/reverseContactSync.html).
 *
 * Deux actions distinctes plutôt qu'un seul endpoint synchrone : scan()
 * (lecture seule, potentiellement longue selon le nombre de familles
 * synchronisées) alimente l'écran de chargement puis de résolution du
 * panneau ; apply() n'est appelé qu'une fois le staff a tranché chaque
 * champ en écart.
 *
 * Route protégée par 'role:gestionnaire' (voir routes/web.php) — même
 * niveau d'accès que l'édition des dossiers eux-mêmes.
 */
class GoogleContactsReverseSyncController extends Controller
{
    public function __construct(
        private readonly ReverseSyncService $reverseSync,
        private readonly GoogleContactsService $googleContacts
    ) {
    }

    public function scan(): JsonResponse
    {
        if (!$this->googleContacts->isConfigured()) {
            return response()->json([
                'error' => "Google Contacts n'est pas configuré/autorisé — voir /admin/google-contacts/authorize.",
            ], 422);
        }

        return response()->json($this->reverseSync->scanner());
    }

    public function apply(Request $request): JsonResponse
    {
        if (!$this->googleContacts->isConfigured()) {
            return response()->json([
                'error' => "Google Contacts n'est pas configuré/autorisé — voir /admin/google-contacts/authorize.",
            ], 422);
        }

        $validated = $request->validate([
            'decisions' => ['required', 'array', 'min:1'],
            'decisions.*.id_famille' => ['required', 'integer', 'exists:familles,id'],
            'decisions.*.champs' => ['required', 'array', 'min:1'],
            'decisions.*.champs.*.champ' => ['required', 'string'],
            'decisions.*.champs.*.action' => ['required', 'string', 'in:accepter_db,accepter_contact,ecraser'],
            'decisions.*.champs.*.valeur' => ['nullable', 'string'],
            'decisions.*.champs.*.valeur_contact' => ['nullable', 'string'],
        ]);

        return response()->json(['resultats' => $this->reverseSync->appliquer($validated['decisions'])]);
    }
}
