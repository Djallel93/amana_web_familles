<?php
// app/Models/BenevoleConsentRefusal.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Trace un refus de consentement RGPD à la première étape du formulaire de
 * candidature bénévole — miroir d'IntakeConsentRefusal (familles). Aucune
 * donnée personnelle n'est enregistrée — uniquement de quoi justifier,
 * en cas de contrôle, qu'un refus a bien été respecté (aucun profil créé).
 *
 * @property int    $id
 * @property string $langue
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
class BenevoleConsentRefusal extends Model
{
    public $timestamps = false;

    protected $fillable = ['langue', 'ip_address', 'user_agent', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
