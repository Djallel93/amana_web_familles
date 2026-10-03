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

    /**
     * Nombre maximum de fichiers par section de justificatifs (identité,
     * aide CAF/AME, ressources) — 01/10/2026 : même plafond pour les trois
     * (la section Ressources en autorisait 10 jusque-là), côté formulaire
     * public (IntakeController) comme côté fiche (FamillesController).
     */
    public const MAX_PAR_TYPE = 5;

    /**
     * Nom d'affichage/téléchargement d'un document à partir du libellé
     * optionnel saisi par la famille ou le staff (01/10/2026) : « Passeport
     * Karim » + « scan0042.PDF » → « Passeport Karim.pdf ». Sans libellé
     * (null, vide, ou vide après nettoyage) le nom d'origine est conservé tel
     * quel. L'extension d'origine est toujours reprise (en minuscules) pour
     * que le fichier reste ouvrable ; si le libellé la porte déjà elle n'est
     * pas doublée. Les caractères interdits dans un nom de fichier
     * (/ \ : * ? " < > | et contrôles) sont retirés — le nom n'est qu'un nom
     * de téléchargement, le chemin disque reste généré par Laravel.
     */
    public static function nomAvecLabel(?string $label, string $nomOriginal): string
    {
        $label = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', (string) $label));
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        $label = mb_substr($label, 0, 100);

        if ($label === '') {
            return $nomOriginal;
        }

        $extension = strtolower(pathinfo($nomOriginal, PATHINFO_EXTENSION));

        if ($extension === '') {
            return $label;
        }

        // Le libellé porte déjà l'extension (« Passeport.PDF ») : on ne la
        // double pas, mais on la normalise en minuscules comme dans le cas
        // général — même résultat quelle que soit la casse saisie.
        if (str_ends_with(strtolower($label), '.' . $extension)) {
            return substr($label, 0, -strlen($extension)) . $extension;
        }

        return $label . '.' . $extension;
    }

    public function famille(): BelongsTo
    {
        return $this->belongsTo(Famille::class, 'id_famille');
    }
}
