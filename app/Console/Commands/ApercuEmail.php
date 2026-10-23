<?php
// app/Console/Commands/ApercuEmail.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ApercuEmail\ApercuEmail as Apercu;
use App\Support\ApercuEmail\CatalogueEmails;
use Illuminate\Console\Command;
use Throwable;

/**
 * Outil de mise au point des emails (23/10/2026) : rend ou envoie n'importe quel email de
 * l'application avec des données d'exemple, sans rien écrire en base. Le but : retoucher un
 * gabarit, relancer la commande, regarder le résultat — jusqu'à obtenir ce qu'on veut.
 * Procédure complète : docs/emails.md.
 *
 * Trois usages :
 *   - sans argument : liste les emails (clé, gabarit à éditer, langues, déclencheur) ;
 *   - avec une clé : écrit un fichier HTML à ouvrir dans le navigateur (logo et QR inclus) ;
 *   - avec --envoyer --a=adresse : envoie VRAIMENT l'email (en local, Mailpit : http://localhost:8025).
 *
 * Exemples :
 *   php artisan familles:apercu-email
 *   php artisan familles:apercu-email retrait-hq
 *   php artisan familles:apercu-email retrait-hq --langue=ar
 *   php artisan familles:apercu-email tous
 *   php artisan familles:apercu-email retrait-hq --envoyer --a=moi@exemple.fr
 *   php artisan familles:apercu-email retrait-hq --envoyer --a=moi@exemple.fr --mailer=smtp
 */
class ApercuEmail extends Command
{
    protected $signature = 'familles:apercu-email
        {email? : Clé de l\'email (voir la liste sans argument), ou « tous » pour tout rendre}
        {--langue=fr : Langue des emails multilingues : fr, ar ou en}
        {--envoyer : Envoie réellement l\'email au lieu d\'écrire un fichier HTML}
        {--a= : Adresse destinataire (obligatoire avec --envoyer)}
        {--mailer= : Mailer à utiliser pour l\'envoi (smtp, log...) au lieu de MAIL_MAILER}
        {--sortie= : Dossier de sortie du rendu (défaut : storage/app/email-previews)}';

    protected $description = 'Rend ou envoie un email de l\'application avec des données d\'exemple (mise au point des gabarits)';

    public function handle(CatalogueEmails $catalogue, Apercu $apercu): int
    {
        $cle = $this->argument('email');

        if ($cle === null) {
            return $this->afficherListe($catalogue);
        }

        $langue = (string) $this->option('langue');
        if (!in_array($langue, CatalogueEmails::LANGUES, true)) {
            $this->error('Langue invalide : utilisez fr, ar ou en.');

            return self::FAILURE;
        }

        if ($this->option('envoyer')) {
            return $this->envoyer($catalogue, $apercu, (string) $cle, $langue);
        }

        return $this->rendre($catalogue, $apercu, (string) $cle, $langue);
    }

    private function afficherListe(CatalogueEmails $catalogue): int
    {
        $lignes = [];
        foreach ($catalogue->entrees() as $cle => $entree) {
            $lignes[] = [
                $cle,
                $entree['vue'] ?? '(mise en page Laravel par défaut)',
                $entree['langues'] ? 'fr/ar/en' : 'fr',
                $entree['destinataire'] === 'anonyme' ? 'famille/demande' : 'compte',
                $entree['declencheur'],
            ];
        }

        $this->table(['Clé', 'Gabarit', 'Langues', 'Destinataire', 'Déclencheur'], $lignes);
        $this->line('Rendu : php artisan familles:apercu-email <clé> [--langue=ar]');
        $this->line('Envoi : php artisan familles:apercu-email <clé> --envoyer --a=adresse@exemple.fr');

        return self::SUCCESS;
    }

    private function rendre(CatalogueEmails $catalogue, Apercu $apercu, string $cle, string $langue): int
    {
        $cles = $cle === 'tous' ? $catalogue->cles() : [$cle];
        $dossier = (string) ($this->option('sortie') ?: storage_path('app/email-previews'));

        foreach ($cles as $uneCle) {
            if (!in_array($uneCle, $catalogue->cles(), true)) {
                $this->error("Email inconnu « {$uneCle} ». Lancez la commande sans argument pour la liste.");

                return self::FAILURE;
            }
        }

        $echecs = 0;
        foreach ($cles as $uneCle) {
            // Un email multilingue est rendu dans la langue demandée ; les autres existent en fr seulement.
            $langueEffective = $catalogue->entree($uneCle)['langues'] ? $langue : 'fr';

            try {
                $chemin = $apercu->enregistrer($uneCle, $langueEffective, $dossier);
                $sujet = $apercu->rendre($uneCle, $langueEffective)['sujet'];
            } catch (Throwable $e) {
                $echecs++;
                $this->error("{$uneCle} : {$e->getMessage()}");

                continue;
            }

            $this->info("{$uneCle} ({$langueEffective}) → {$chemin}");
            $this->line("  Sujet : {$sujet}");
        }

        if ($echecs === 0) {
            $this->line('Ouvrez le(s) fichier(s) dans un navigateur ; relancez la commande après chaque retouche du gabarit.');
        }

        return $echecs === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function envoyer(CatalogueEmails $catalogue, Apercu $apercu, string $cle, string $langue): int
    {
        $adresse = (string) $this->option('a');
        if ($adresse === '' || filter_var($adresse, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('--envoyer demande une adresse valide : --a=adresse@exemple.fr');

            return self::FAILURE;
        }

        if ($cle === 'tous') {
            $this->error('--envoyer n\'accepte qu\'un seul email à la fois (pas « tous »).');

            return self::FAILURE;
        }

        if (!in_array($cle, $catalogue->cles(), true)) {
            $this->error("Email inconnu « {$cle} ». Lancez la commande sans argument pour la liste.");

            return self::FAILURE;
        }

        if ($mailer = $this->option('mailer')) {
            config(['mail.default' => (string) $mailer]);
        }

        $mailerEffectif = (string) config('mail.default');
        if (in_array($mailerEffectif, ['log', 'array'], true)) {
            $this->warn("Mailer « {$mailerEffectif} » : rien ne part réellement (le message est seulement journalisé ou ignoré).");
            $this->warn('En local avec docker : MAIL_MAILER=smtp, MAIL_HOST=mailpit, MAIL_PORT=1025, ou --mailer=smtp.');
        }

        try {
            $sujet = $apercu->envoyer($cle, $adresse, $langue);
        } catch (Throwable $e) {
            $this->error("Échec de l'envoi : {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Envoyé à {$adresse} via « {$mailerEffectif} » : {$sujet}");

        return self::SUCCESS;
    }
}
