<?php
// app/Models/FamilleImport.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property string      $type    import | update
 * @property string      $source  manual | csv
 * @property int|null    $uploaded_by  ID de ref_personnes — pas de FK (table partagée, voir migration)
 * @property string      $status
 */
class FamilleImport extends Model
{
    public $timestamps = false;

    protected $fillable = ['type', 'source', 'uploaded_by', 'status'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(FamilleImportRow::class, 'id_import');
    }
}
