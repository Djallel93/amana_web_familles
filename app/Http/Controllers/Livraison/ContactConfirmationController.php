<?php
// app/Http/Controllers/Livraison/ContactConfirmationController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use App\Services\ContactTokenService;
use App\Services\FamilleConfirmationSyncService;
use App\Support\Creneau;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Formulaire public de confirmation famille — aucune authentification,
 * accès scopé par jeton (contact_tokens), voir le prompt du 30/08/2026
 * §2/§3.1/§7. Le jeton résout STRICTEMENT la livraison associée (via
 * ContactTokenService::resoudre(), eager-load limité à
 * livraison.famille) — jamais d'autre donnée famille exposée par cet
 * écran.
 *
 * Formulaire classique (POST + redirection avec erreurs), pas une île Vue
 * comme le reste de l'app : une île dédiée n'apporterait rien pour un
 * formulaire de cette taille — décision du 31/08/2026, à l'inverse du
 * formulaire d'intake (7 étapes, multilingue, autocomplete Google Places)
 * qui justifie pleinement Vue.
 *
 * Champs alignés sur la granularité de familles depuis le 31/08/2026 (voir
 * 2026_08_31_000200_revise_livraisons_confirmation_fields.php) :
 * adresse/code_postal/ville séparés (pas un seul champ libre), adulte/
 * enfant séparés (pas un total unique) — et réécrits vers familles via
 * FamilleConfirmationSyncService, qui reste la seule source de vérité.
 */
class ContactConfirmationController extends Controller
{
    public function __construct(
        private readonly ContactTokenService $tokenService,
        private readonly FamilleConfirmationSyncService $syncService,
    ) {
    }

    public function show(string $token): View
    {
        $contactToken = $this->tokenService->resoudre($token);

        if (!$contactToken) {
            return view('livraison.confirmation', ['etat' => 'introuvable']);
        }

        if ($contactToken->used_at !== null) {
            return view('livraison.confirmation', ['etat' => 'deja_confirmee']);
        }

        if (!$contactToken->estValide()) {
            return view('livraison.confirmation', ['etat' => 'expiree']);
        }

        return view('livraison.confirmation', [
            'etat' => 'formulaire',
            'token' => $token,
            'livraison' => $contactToken->livraison,
            'famille' => $contactToken->livraison->famille,
            'creneaux' => Creneau::LIBELLES,
        ]);
    }

    public function store(Request $request, string $token): View|RedirectResponse
    {
        $contactToken = $this->tokenService->resoudre($token);

        if (!$contactToken || !$contactToken->estValide()) {
            return view('livraison.confirmation', ['etat' => $contactToken ? 'expiree' : 'introuvable']);
        }

        $validator = Validator::make($request->all(), [
            'adresse_confirmee' => 'required|string|max:500',
            'code_postal_confirme' => 'nullable|string|max:10',
            'ville_confirmee' => 'nullable|string|max:150',
            'nombre_adulte_confirme' => 'required|integer|min:1|max:30',
            'nombre_enfant_confirme' => 'required|integer|min:0|max:30',
            'creneaux' => 'required|array|min:1',
            'creneaux.*' => 'in:' . implode(',', Creneau::TOUS),
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $livraison = $contactToken->livraison;
        $donneesConfirmees = $validator->safe()->only([
            'adresse_confirmee', 'code_postal_confirme', 'ville_confirmee',
            'nombre_adulte_confirme', 'nombre_enfant_confirme',
        ]);

        $livraison->update([...$donneesConfirmees, 'statut_contact' => 'confirme']);

        $this->syncService->synchroniser($livraison, $donneesConfirmees);

        $livraison->creneaux()->delete();
        foreach ($request->input('creneaux') as $creneau) {
            $livraison->creneaux()->create(['creneau' => $creneau]);
        }

        $contactToken->update(['used_at' => now()]);

        return view('livraison.confirmation', ['etat' => 'confirmee']);
    }
}
