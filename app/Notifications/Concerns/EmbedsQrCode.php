<?php
// app/Notifications/Concerns/EmbedsQrCode.php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;

/**
 * Attache un QR code SVG (généré en mémoire par App\Services\QrCodeService,
 * jamais écrit sur disque) en pièce jointe inline (CID) — pendant de
 * Amana\Shared\Notifications\Concerns\EmbedsLogo, mais à partir d'une
 * chaîne déjà en mémoire plutôt que d'un fichier sur disque (DataPart
 * accepte directement le contenu, pas besoin de Symfony\Mime\Part\File
 * ni d'écrire un fichier temporaire) — voir le prompt du 24/09/2026 §2.
 *
 * Un CID par appel (contentId dérivé de l'id de livraison, pas une
 * constante comme LOGO_CID) : contrairement au logo (même fichier pour
 * tout le monde), chaque email de retrait QG embarque un QR DIFFÉRENT.
 */
trait EmbedsQrCode
{
    private function embedQrCode(MailMessage $message, string $svg, int|string $idLivraison): MailMessage
    {
        $cid = "qr-retrait-hq-{$idLivraison}@amana-familles";

        return $message->withSymfonyMessage(function (Email $symfonyMessage) use ($svg, $cid): void {
            try {
                $piece = (new DataPart($svg, "qr-{$cid}.svg", 'image/svg+xml'))
                    ->asInline()
                    ->setContentId($cid);

                $symfonyMessage->addPart($piece);
            } catch (Throwable) {
                // Problème d'encodage du SVG : on n'interrompt pas l'envoi,
                // l'email part sans QR (la famille reste identifiable par
                // Famille.id/pièce d'identité, voir le prompt §7 — "purely
                // offline" pour ce repli).
            }
        });
    }

    private function qrCid(int|string $idLivraison): string
    {
        return 'cid:qr-retrait-hq-' . $idLivraison . '@amana-familles';
    }
}
