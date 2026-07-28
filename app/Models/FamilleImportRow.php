<?php
// app/Models/FamilleImportRow.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property int    $id_import
 * @property int    $row_number
 * @property array  $payload
 * @property string $status  pending | success | error | skipped
 * @property string|null $error_message
 */
class FamilleImportRow extends Model
{
    public $timestamps = false;

    protected $fillable = ['id_import', 'row_number', 'payload', 'status', 'error_message'];

    protected $casts = [
        'payload' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(FamilleImport::class, 'id_import');
    }
}
