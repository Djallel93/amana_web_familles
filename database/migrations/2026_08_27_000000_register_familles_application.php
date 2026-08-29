<?php
// database/migrations/2026_08_27_000000_register_familles_application.php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre l'application 'familles' + ses rôles (admin/gestionnaire/
 * membre/benevole) dans ref_applications/ref_roles (amana_commun).
 *
 * Anciennement un seeder (FamillesApplicationSeeder) à lancer manuellement
 * — corrigé le 27/08/2026 : une migration cible parfaitement une autre
 * connexion (voir $connection ci-dessous, même mécanisme que les
 * migrations d'amana/shared elles-mêmes) tout en restant suivie dans la
 * table `migrations` de familles (connexion par défaut). Plus besoin
 * d'une commande à part — `php artisan migrate` suffit désormais.
 *
 * Doit s'exécuter après les migrations d'amana/shared (`php artisan
 * amana:migrate-shared`), qui créent ref_applications/ref_roles — ce
 * n'est PAS géré automatiquement (deux commandes/mécanismes de suivi
 * distincts, voir amana:migrate-shared), donc l'ordre reste à respecter
 * manuellement au premier déploiement : amana:migrate-shared PUIS
 * migrate. Si lancée avant, échoue proprement (table introuvable) plutôt
 * que silencieusement.
 *
 * Idempotente par construction (vérifie l'existence avant insertion) —
 * utile si amana_web_planning a déjà inséré des rôles admin/gestionnaire/
 * membre/benevole par ailleurs (ce n'est pas le cas actuellement, chaque
 * app gérant ses propres rôles scopés, mais reste défensif).
 */
return new class extends Migration {
    public function up(): void
    {
        $commun = DB::connection(config('amana-shared.connection', 'commun'));

        $dejaPresent = $commun->table('ref_applications')->where('code', 'familles')->exists();

        if (!$dejaPresent) {
            $commun->table('ref_applications')->insert([
                'code' => 'familles',
                'libelle' => 'AMANA Familles',
                'actif' => true,
            ]);
        }

        $famillesId = $commun->table('ref_applications')->where('code', 'familles')->value('id');

        $roles = [
            ['code' => 'admin', 'libelle' => 'Administrateur'],
            ['code' => 'gestionnaire', 'libelle' => 'Gestionnaire'],
            ['code' => 'membre', 'libelle' => 'Membre'],
            ['code' => 'benevole', 'libelle' => 'Bénévole'],
            // Ajouté le 28/08/2026 (organisations partenaires) — rôle
            // latéral, hors cascade, voir Amana\Shared\Http\Middleware\
            // EnsureRole et App\Models\Personne::isGestionnaireExterne().
            ['code' => 'gestionnaire_externe', 'libelle' => 'Gestionnaire (organisation partenaire)'],
        ];

        foreach ($roles as $role) {
            $existe = $commun->table('ref_roles')
                ->where('id_application', $famillesId)
                ->where('code', $role['code'])
                ->exists();

            if (!$existe) {
                $commun->table('ref_roles')->insert([
                    'code' => $role['code'],
                    'libelle' => $role['libelle'],
                    'id_application' => $famillesId,
                ]);
            }
        }

        // Réglages "suspendre l'inscription" (ajouté le 29/08/2026) — deux
        // interrupteurs indépendants dans Paramètres, un par formulaire
        // public (voir IntakeController::showForm()/store() et
        // BenevoleIntakeController::showForm()/store()). Ouvert ('1') par
        // défaut — même valeur par défaut que si le réglage n'existait pas
        // encore, pour ne rien changer au comportement actuel au premier
        // déploiement de cette migration.
        $reglagesInscription = [
            [
                'cle' => 'inscription_familles_ouverte',
                'valeur' => '1',
                'type' => 'boolean',
                'libelle' => 'Inscription des familles ouverte',
                'description' => "Désactivez pour suspendre temporairement le formulaire public de demande d'aide (les familles déjà enregistrées ne sont pas affectées).",
            ],
            [
                'cle' => 'inscription_benevoles_ouverte',
                'valeur' => '1',
                'type' => 'boolean',
                'libelle' => 'Inscription des bénévoles ouverte',
                'description' => 'Désactivez pour suspendre temporairement le formulaire public de candidature bénévole.',
            ],
        ];

        foreach ($reglagesInscription as $reglage) {
            $existe = $commun->table('ref_settings')
                ->where('id_application', $famillesId)
                ->where('cle', $reglage['cle'])
                ->exists();

            if (!$existe) {
                $commun->table('ref_settings')->insert([
                    'id_application' => $famillesId,
                    'cle' => $reglage['cle'],
                    'valeur' => $reglage['valeur'],
                    'type' => $reglage['type'],
                    'libelle' => $reglage['libelle'],
                    'description' => $reglage['description'],
                ]);
            }
        }
    }

    /**
     * Pas de suppression au rollback — 'familles' et ses rôles peuvent
     * déjà être référencés (ref_personnes_roles, benevole_profils, etc.)
     * au moment d'un `migrate:rollback` ; les supprimer casserait ces
     * références sans raison valable. Même choix que
     * FamillesApplicationSeeder (l'ancien seeder n'avait pas non plus de
     * mécanisme de suppression).
     */
    public function down(): void
    {
    }
};
