<?php
// app/Notifications/Concerns/EmbedsLogo.php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;
use Throwable;

/**
 * Attache le logo AMANA aux emails en tant que pièce jointe inline (CID),
 * au lieu de le référencer par une URL distante.
 *
 * Pourquoi : les images chargées par URL distante (config('app.url') . '/images/...')
 * ne s'affichaient de façon fiable sur aucun client testé (Gmail web/Android,
 * Thunderbird), très probablement à cause de Cloudflare qui bloque/challenge les
 * requêtes des proxys d'images de ces clients. Un CID embarqué dans l'email
 * élimine totalement cette dépendance réseau : le logo est déjà dans le
 * message que le client a téléchargé, donc aucun fetch externe n'est requis.
 *
 * Fonctionnement technique : on attache une DataPart inline avec un Content-ID
 * fixe et connu à l'avance (LOGO_CID) au message Symfony sous-jacent. Symfony
 * Mime détecte automatiquement la référence "cid:LOGO_CID" présente dans le
 * HTML rendu (voir Email::prepareParts()) et construit lui-même la structure
 * MIME "multipart/related" appropriée au moment de l'envoi — il n'est donc
 * pas nécessaire (et ce serait même risqué) de manipuler le corps du message
 * manuellement ici.
 *
 * Utilisation dans une classe Notification :
 *
 *   use EmbedsLogo;
 *
 *   return $this->embedLogo(new MailMessage)
 *       ->subject('...')
 *       ->view('emails.xxx', [
 *           'logoCid' => $this->logoCid(),
 *           ...
 *       ]);
 *
 * Et dans la vue Blade correspondante :
 *
 *   <img src="{{ $logoCid ?? config('app.url').'/images/amana-logo.png' }}" alt="AMANA">
 *
 * (le fallback vers l'URL distante ne sert qu'en cas de rendu hors contexte
 * d'une notification, par ex. une prévisualisation locale future).
 */
trait EmbedsLogo
{
    /**
     * Identifiant de contenu (Content-ID) utilisé pour référencer le logo
     * inline dans le HTML de l'email via "cid:...". C'est un identifiant
     * arbitraire — il n'a pas besoin de correspondre à un domaine réel,
     * il doit juste être unique et contenir un "@" (contrainte Symfony Mime).
     */
    private const LOGO_CID = 'logo-amana@planning.amana44.fr';

    /**
     * Attache le logo AMANA au message en tant que pièce jointe inline.
     * Reste silencieux (sans faire planter l'envoi) si le fichier est
     * introuvable ou illisible, pour ne jamais bloquer l'envoi d'un email
     * pour un problème purement visuel.
     */
    private function embedLogo(MailMessage $message): MailMessage
    {
        $chemin = public_path('images/amana-logo.png');

        return $message->withSymfonyMessage(function (Email $symfonyMessage) use ($chemin): void {
            if (!is_file($chemin) || !is_readable($chemin)) {
                return;
            }

            try {
                $piece = (new DataPart(new File($chemin), 'amana-logo.png', 'image/png'))
                    ->asInline()
                    ->setContentId(self::LOGO_CID);

                $symfonyMessage->addPart($piece);
            } catch (Throwable) {
                // Problème de lecture du fichier : on n'interrompt pas l'envoi,
                // le template retombera simplement sans logo visible.
            }
        });
    }

    /**
     * Retourne la référence "cid:..." à utiliser dans l'attribut src="" des
     * vues Blade pour pointer vers le logo inline attaché par embedLogo().
     */
    private function logoCid(): string
    {
        return 'cid:' . self::LOGO_CID;
    }
}
