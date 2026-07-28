<?php
// app/Http/Controllers/VerificationController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FamilleVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Page publique de vérification des informations — remplace
 * confirmFamilyInfo() (emailVerificationService.js). Accessible via le
 * lien reçu par email (FamilleVerificationNotification), token à durée de
 * vie limitée (famille_verifications.expires_at, décision 6.10).
 */
class VerificationController extends Controller
{
    public function show(string $token): View
    {
        $verification = FamilleVerification::with('famille')->where('token', $token)->first();

        if (!$verification) {
            return view('verification.show', ['etat' => 'introuvable']);
        }

        if ($verification->estConfirmee()) {
            return view('verification.show', ['etat' => 'deja_confirmee', 'verification' => $verification]);
        }

        if ($verification->estExpiree()) {
            return view('verification.show', ['etat' => 'expiree', 'verification' => $verification]);
        }

        return view('verification.show', ['etat' => 'a_confirmer', 'verification' => $verification]);
    }

    public function confirmer(string $token): RedirectResponse|View
    {
        $verification = FamilleVerification::with('famille')->where('token', $token)->first();

        if (!$verification || $verification->estExpiree() || $verification->estConfirmee()) {
            return view('verification.show', [
                'etat' => !$verification ? 'introuvable' : ($verification->estConfirmee() ? 'deja_confirmee' : 'expiree'),
                'verification' => $verification,
            ]);
        }

        $verification->confirmed_at = now();
        $verification->save();

        audit('update', 'familles_verification', $verification->id, null, [
            'id_famille' => $verification->id_famille,
            'confirmed_at' => $verification->confirmed_at->toIso8601String(),
        ]);

        return view('verification.show', ['etat' => 'confirmee', 'verification' => $verification]);
    }
}
