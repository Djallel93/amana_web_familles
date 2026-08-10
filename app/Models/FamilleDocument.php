<?php
// app/Models/FamilleDocument.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property int    $id_famille
 * @property string $type  identity | caf | ame | resource
 * @property string $disk_path
 * @property string $original_name
 * @property string $mime_type
 */
class FamilleDocument extends Model
{
    public $timestamps = false;

    protected $fillable = ['id_famille', 'type', 'disk_path', 'original_name', 'mime_type', 'uploaded_at'];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    public const TYPES = [
        'identity' => 'Pièce d\'identité',
        'caf' => 'Attestation CAF',
        'ame' => 'Aide médicale de l\'État (AME)',
        'resource' => 'Justificatif de ressources',
    ];

    public function famille(): BelongsTo
    {
        return $this->belongsTo(Famille::class, 'id_famille');
    }
}
