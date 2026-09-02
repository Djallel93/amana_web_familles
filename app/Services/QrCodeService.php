<?php
// app/Services/QrCodeService.php

declare(strict_types=1);

namespace App\Services;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Génération de QR codes — étiquettes de colis (verso, fallback de
 * confirmation de livraison, voir le prompt du 30/08/2026 §3.4). SVG
 * généré côté serveur (endroid/qr-code, aucune dépendance GD/Imagick,
 * aucun appel externe) et inséré directement dans la page imprimable —
 * pas de fichier écrit sur disque.
 *
 * Le QR code encode une URL vers l'espace authentifié du bénévole (voir
 * routes/web.php, groupe role:benevole) — PAS un endpoint public/ouvert :
 * scanner le code sans être connecté redirige vers la connexion, comme
 * n'importe quelle page de l'espace bénévole. Corrige explicitement le
 * défaut de l'ancien système (endpoint de scan non authentifié) — voir
 * le prompt §3.4.
 */
class QrCodeService
{
    public function genererSvg(string $url): string
    {
        $qrCode = new QrCode(data: $url, size: 200, margin: 5);
        $writer = new SvgWriter();

        return $writer->write($qrCode)->getString();
    }
}
