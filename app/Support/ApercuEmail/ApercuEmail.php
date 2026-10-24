<?php
// app/Support/ApercuEmail/ApercuEmail.php

declare(strict_types=1);

namespace App\Support\ApercuEmail;

use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Rendu et envoi d'un email du CatalogueEmails (23/10/2026) — utilisé par la commande
 * `familles:apercu-email` et utilisable tel quel dans `php artisan tinker` :
 *
 *   $apercu = app(\App\Support\ApercuEmail\ApercuEmail::class);
 *   $apercu->rendre('retrait-hq', 'ar');            // ['sujet' => ..., 'html' => ...]
 *   $apercu->envoyer('retrait-hq', 'moi@exemple.fr'); // via le mailer configuré (Mailpit en local)
 *
 * Pourquoi un rendu dédié plutôt que `$mail->render()` seul : le logo et le QR code sont
 * embarqués dans l'email par `cid:` (pièces jointes inline ajoutées à l'envoi seulement, voir les
 * traits EmbedsLogo/EmbedsQrCode). Rendu tel quel dans un navigateur, ces images seraient
 * cassées ; on rejoue donc les mêmes callbacks sur un message Symfony vide et on remplace chaque
 * `cid:` par une URI `data:` — l'aperçu montre ce que verra le destinataire.
 *
 * Aucune donnée n'est écrite en base : les notifications d'exemple sont construites en mémoire,
 * et l'envoi passe par le canal `mail` UNIQUEMENT (jamais `amana-database`).
 */
final class ApercuEmail
{
    public function __construct(private readonly CatalogueEmails $catalogue) {}

    /**
     * @return array{sujet: string, html: string, destinataire: string}
     */
    public function rendre(string $cle, string $langue = 'fr', string $adresse = 'destinataire@exemple.test'): array
    {
        [$notification, $notifiable] = $this->catalogue->construire($cle, $langue, $adresse);

        /** @var MailMessage $mail */
        $mail = $notification->toMail($notifiable);

        return [
            'sujet' => (string) $mail->subject,
            'html' => $this->htmlAutonome($mail),
            'destinataire' => $adresse,
        ];
    }

    /**
     * Écrit le rendu dans un fichier HTML autonome et renvoie son chemin.
     */
    public function enregistrer(string $cle, string $langue, string $dossier): string
    {
        $rendu = $this->rendre($cle, $langue);

        if (!is_dir($dossier)) {
            mkdir($dossier, 0775, true);
        }

        $chemin = rtrim($dossier, '/') . "/{$cle}.{$langue}.html";
        file_put_contents($chemin, $rendu['html']);

        return $chemin;
    }

    /**
     * Envoie réellement l'email (canal mail seulement) au mailer configuré.
     */
    public function envoyer(string $cle, string $adresse, string $langue = 'fr'): string
    {
        [$notification, $notifiable] = $this->catalogue->construire($cle, $langue, $adresse);

        app(MailChannel::class)->send($notifiable, $notification);

        /** @var MailMessage $mail */
        $mail = $notification->toMail($notifiable);

        return (string) $mail->subject;
    }

    /**
     * HTML du message avec chaque `cid:` remplacé par la pièce inline correspondante.
     */
    public function htmlAutonome(MailMessage $mail): string
    {
        $html = (string) $mail->render();

        $message = new Email();
        foreach ($mail->callbacks as $callback) {
            $callback($message);
        }

        foreach ($message->getAttachments() as $piece) {
            if (!$piece instanceof DataPart) {
                continue;
            }

            $uri = 'data:' . $piece->getMediaType() . '/' . $piece->getMediaSubtype()
                . ';base64,' . base64_encode($piece->getBody());
            $html = str_replace('cid:' . $piece->getContentId(), $uri, $html);
        }

        return $html;
    }
}
