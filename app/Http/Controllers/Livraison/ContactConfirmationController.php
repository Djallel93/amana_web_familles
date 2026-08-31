<?php
// app/Http/Controllers/Livraison/ContactConfirmationController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use App\Services\ContactTokenService;
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
 * comme le reste de l'app : 3 champs seulement (adresse, membres du
 * foyer, créneaux), une île dédiée n'apporterait rien ici — décision du
 * 31/08/2026, à l'inverse du formulaire d'intake (7 étapes, multilingue,
 * autocomplete Google Places) qui justifie pleinement Vue.
 */
class ContactConfirmationController extends Controller
{
    public function __construct(
        private readonly ContactTokenService $tokenService,
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
            'membres_foyer_confirmes' => 'required|integer|min:1|max:30',
            'creneaux' => 'required|array|min:1',
            'creneaux.*' => 'in:' . implode(',', Creneau::TOUS),
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $livraison = $contactToken->livraison;

        $livraison->update([
            'adresse_confirmee' => $request->input('adresse_confirmee'),
            'membres_foyer_confirmes' => $request->input('membres_foyer_confirmes'),
            'statut_contact' => 'confirme',
        ]);

        $livraison->creneaux()->delete();
        foreach ($request->input('creneaux') as $creneau) {
            $livraison->creneaux()->create(['creneau' => $creneau]);
        }

        $contactToken->update(['used_at' => now()]);

        return view('livraison.confirmation', ['etat' => 'confirmee']);
    }
}
