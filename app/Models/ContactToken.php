<?php
// app/Models/ContactToken.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jeton à usage unique du formulaire public de confirmation famille — voir
 * create_contact_tokens_table.php. La génération/vérification du hash et
 * la logique de résolution (scope strictement limité à la livraison
 * associée) vivent dans un service dédié à écrire en Patch 2, pas ici.
 *
 * @property int      $id
 * @property int      $id_livraison
 * @property string   $token        haché au repos
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $used_at
 */
class ContactToken extends Model
{
    public function getConnectionName(): ?string
    {
        return config('database.default');
    }

    protected $fillable = ['id_livraison', 'token', 'expires_at', 'used_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function livraison(): BelongsTo
    {
        return $this->belongsTo(Livraison::class, 'id_livraison');
    }

    public function estValide(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
