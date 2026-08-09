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
 * @property int|null    $id_famille  Famille créée/mise à jour par cette ligne (status=success uniquement)
 * @property bool|null   $cree        true = création, false = mise à jour d'un doublon existant
 * @property array|null  $donnees_avant  Snapshot Famille avant mise à jour (cree=false uniquement)
 */
class FamilleImportRow extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'id_import', 'row_number', 'payload', 'status', 'error_message',
        'id_famille', 'cree', 'donnees_avant',
    ];

    protected $casts = [
        'payload' => 'array',
        'donnees_avant' => 'array',
        'cree' => 'boolean',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(FamilleImport::class, 'id_import');
    }
}
