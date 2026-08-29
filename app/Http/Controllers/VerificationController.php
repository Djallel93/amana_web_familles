<?php
// app/Http/Controllers/VerificationController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FamilleVerification;
use Illuminate\View\View;

/**
 * Page publique de vérification des informations — remplace
 * confirmFamilyInfo() (emailVerificationService.js). Accessible via le
 * lien reçu par email (FamilleVerificationNotification), token à durée de
 * vie limitée (famille_verifications.expires_at, décision 6.10).
 *
 * Confirmation en un clic (changement du 29/08/2026) : cliquer le lien de
 * l'email confirme directement (plus d'écran intermédiaire "a_confirmer"
 * avec un second bouton — jugé confus et superflu). L'écran de récap des
 * informations avant confirmation disparaît avec cet écran ; la personne
 * garde le lien "mes informations ont changé" (voir verification/show.blade.php)
 * si besoin de corriger après coup.
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

        $verification->confirmed_at = now();
        $verification->save();

        audit('update', 'familles_verification', $verification->id, null, [
            'id_famille' => $verification->id_famille,
            'confirmed_at' => $verification->confirmed_at->toIso8601String(),
        ]);

        return view('verification.show', ['etat' => 'confirmee', 'verification' => $verification]);
    }
}
