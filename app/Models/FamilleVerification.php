<?php
// app/Models/FamilleVerification.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property int    $id_famille
 * @property string $token  hash sha256 (voir App\Support\TokenHasher) — jamais le jeton en clair
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 */
class FamilleVerification extends Model
{
    public $timestamps = false;

    protected $fillable = ['id_famille', 'token', 'expires_at', 'confirmed_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function famille(): BelongsTo
    {
        return $this->belongsTo(Famille::class, 'id_famille');
    }

    public function estExpiree(): bool
    {
        return $this->expires_at->isPast();
    }

    public function estConfirmee(): bool
    {
        return $this->confirmed_at !== null;
    }
}
