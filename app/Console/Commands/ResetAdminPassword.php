<?php
// app/Console/Commands/ResetAdminPassword.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Personne;
use App\Services\RoleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Commande de secours pour réinitialiser le compte administrateur.
 *
 * Adaptée de amana_web_planning\Console\Commands\ResetAdminPassword (même
 * structure), mais attribue le rôle 'admin' de l'application 'familles'
 * au lieu de 'planning' — le compte ref_personnes lui-même reste partagé
 * entre les deux apps.
 *
 * Cas d'usage :
 *   - Mot de passe admin perdu
 *   - Compte admin accidentellement supprimé ou corrompu
 *   - Première installation sur un nouveau serveur
 *
 * Utilisation via SSH sur le serveur :
 *   php artisan familles:reset-admin
 *
 * Options disponibles :
 *   --email=    : email du compte à réinitialiser (défaut : admin@amana.fr)
 *   --password= : nouveau mot de passe (si absent, un mot de passe aléatoire
 *                 sécurisé est généré et affiché UNE SEULE FOIS)
 *
 * Exemples :
 *   php artisan familles:reset-admin
 *   php artisan familles:reset-admin --email=autre@amana.fr
 *   php artisan familles:reset-admin --password=MonNouveauMotDePasse123!
 */
class ResetAdminPassword extends Command
{
    protected $signature = 'familles:reset-admin
                            {--email=admin@amana.fr : Email du compte administrateur à réinitialiser}
                            {--password= : Nouveau mot de passe (optionnel — généré automatiquement si absent)}';

    protected $description = 'Réinitialise le mot de passe du compte administrateur AMANA Familles. '
        . 'À utiliser uniquement en cas de perte d\'accès via SSH.';

    public function __construct(
        private readonly RoleService $roleService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->newLine();
        $this->components->info('Réinitialisation du compte administrateur AMANA Familles');
        $this->newLine();

        $email = $this->option('email');

        // ── 1. Trouver ou créer le compte admin ────────────────────────────
        $admin = Personne::where('email', $email)->first();

        if (!$admin) {
            $this->components->warn("Aucun compte trouvé avec l'email : {$email}");

            if (!$this->confirm('Voulez-vous créer ce compte administrateur ?', true)) {
                $this->components->error('Opération annulée.');
                return Command::FAILURE;
            }

            $admin = new Personne();
            $admin->email = $email;
            $admin->nom = 'Admin';
            $admin->prenom = 'AMANA';
            $admin->statut = 'Validé';
            $admin->email_verified_at = now();
        }

        // ── 2. Générer ou utiliser le mot de passe fourni ──────────────────
        $motDePasse = $this->option('password');
        $genere = false;

        if (empty($motDePasse)) {
            $motDePasse = str_shuffle(
                Str::upper(Str::random(4))
                . Str::lower(Str::random(4))
                . rand(1000, 9999)
                . Str::substr('!@#$%^&*', rand(0, 4), 1)
                . Str::substr('!@#$%^&*', rand(4, 7), 1)
            );
            $genere = true;
        }

        // ── 3. Validation basique du mot de passe ──────────────────────────
        if (strlen($motDePasse) < 8) {
            $this->components->error('Le mot de passe doit contenir au moins 8 caractères.');
            return Command::FAILURE;
        }

        // ── 4. Confirmation avant d'appliquer ─────────────────────────────
        $this->table(
            ['Champ', 'Valeur'],
            [
                ['Email', $email],
                ['Nom', $admin->nom . ' ' . $admin->prenom],
                ['Statut', $admin->statut ?? 'Validé'],
                ['Mot de passe', $genere ? '(généré automatiquement)' : '(fourni manuellement)'],
            ]
        );

        if (!$this->confirm('Confirmer la réinitialisation ?', true)) {
            $this->components->error('Opération annulée.');
            return Command::FAILURE;
        }

        // ── 5. Appliquer les changements ───────────────────────────────────
        $admin->password = Hash::make($motDePasse);
        $admin->email_verified_at = now();
        $admin->statut = 'Validé';
        $admin->save();

        $this->components->info('Mot de passe mis à jour avec succès.');

        // ── 6. S'assurer que le rôle admin (familles) est bien attribué ────
        $famillesApp = $this->roleService->famillesApp();

        if ($famillesApp) {
            $roleAdmin = $this->roleService->famillesRoles()
                ->firstWhere('code', 'admin');

            if ($roleAdmin) {
                $dejaAttribue = DB::connection(config('amana-shared.connection', 'commun'))->table('ref_personnes_roles')
                    ->where('id_personne', $admin->id)
                    ->where('id_role', $roleAdmin->id)
                    ->exists();

                if (!$dejaAttribue) {
                    $this->roleService->syncRoleFamilles($admin, 'admin');
                    $this->components->info('Rôle admin (familles) attribué.');
                } else {
                    $this->components->info('Rôle admin (familles) déjà attribué — inchangé.');
                }
            }
        } else {
            $this->components->warn('Application "familles" introuvable dans ref_applications — '
                . 'la migration 2026_07_12_000001_register_familles_application.php doit être exécutée '
                . '(côté amana_web_planning) avant que le rôle admin puisse être attribué.');
        }

        // ── 7. Afficher le mot de passe UNE SEULE FOIS si généré ──────────
        $this->newLine();
        $this->line('  ┌─────────────────────────────────────────────┐');
        $this->line('  │          INFORMATIONS DE CONNEXION           │');
        $this->line('  ├─────────────────────────────────────────────┤');
        $this->line("  │  Email      : {$email}");
        if ($genere) {
            $this->line("  │  Mot de passe : <fg=yellow;options=bold>{$motDePasse}</>");
            $this->line('  ├─────────────────────────────────────────────┤');
            $this->line('  │  ⚠️  Notez ce mot de passe MAINTENANT.       │');
            $this->line('  │  Il ne sera plus affiché après cette ligne.  │');
        } else {
            $this->line('  │  Mot de passe : (celui que vous avez fourni) │');
        }
        $this->line('  └─────────────────────────────────────────────┘');
        $this->newLine();

        // ── 8. Invalider toutes les sessions actives de ce compte ─────────
        DB::table('sessions')
            ->where('user_id', $admin->id)
            ->delete();

        $this->components->info('Sessions actives invalidées — reconnexion requise.');
        $this->newLine();

        return Command::SUCCESS;
    }
}
