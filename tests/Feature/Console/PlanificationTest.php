<?php
// tests/Feature/Console/PlanificationTest.php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tâches planifiées (11/10/2026) : routes/console.php relance chaque commande avec le PHP CLI
 * absolu SCHEDULE_PHP_BINARY (Schedule::exec) plutôt que de laisser Symfony deviner le binaire —
 * sur IONOS la devinette tombe sur le mauvais /usr/bin/php (exit 255). Et les workflows de
 * déploiement installent le cron qui fait tourner ce scheduler : on garde les deux en place.
 */
class PlanificationTest extends TestCase
{
    /** @return array<string, Event> nom => événement */
    private function evenements(): array
    {
        $evenements = [];
        foreach (app(Schedule::class)->events() as $evenement) {
            $evenements[$evenement->description] = $evenement;
        }

        return $evenements;
    }

    public function test_les_deux_commandes_planifiees_sont_declarees_avec_leur_frequence(): void
    {
        $evenements = $this->evenements();

        $this->assertArrayHasKey('familles:nettoyer-demandes-attente', $evenements);
        $this->assertSame('0 0 * * *', $evenements['familles:nettoyer-demandes-attente']->expression);

        $this->assertArrayHasKey('familles:liberer-verrous-perimes', $evenements);
        $this->assertSame('*/5 * * * *', $evenements['familles:liberer-verrous-perimes']->expression);
        $this->assertTrue($evenements['familles:liberer-verrous-perimes']->withoutOverlapping);

        $this->assertArrayHasKey('livraison:detecter-incidents', $evenements);
        $this->assertSame('*/5 * * * *', $evenements['livraison:detecter-incidents']->expression);
        $this->assertTrue($evenements['livraison:detecter-incidents']->withoutOverlapping);
    }

    public function test_chaque_commande_est_relancee_via_le_binaire_php_configure(): void
    {
        $attendu = (string) config('app.schedule_php_binary');

        foreach ($this->evenements() as $nom => $evenement) {
            $this->assertStringContainsString($attendu . ' ' . base_path('artisan') . ' ' . $nom, $evenement->command, $nom);
        }
    }

    public function test_les_commandes_planifiees_existent_dans_artisan(): void
    {
        $commandes = array_keys(Artisan::all());

        foreach (array_keys($this->evenements()) as $nom) {
            $this->assertContains($nom, $commandes, "La commande planifiée « {$nom} » n'existe pas.");
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function workflows(): array
    {
        return [
            'production' => ['.github/workflows/deploy.yaml', 'amana_web_familles-scheduler'],
            'preprod' => ['.github/workflows/deploy-preprod.yaml', 'amana_web_familles-preprod-scheduler'],
        ];
    }

    #[DataProvider('workflows')]
    public function test_les_workflows_de_deploiement_installent_le_cron_du_scheduler(string $fichier, string $marqueur): void
    {
        $workflow = (string) file_get_contents(base_path($fichier));

        $this->assertStringContainsString('Ensure Laravel scheduler cron entry (idempotent)', $workflow);
        $this->assertStringContainsString('artisan schedule:run', $workflow);
        $this->assertStringContainsString("# {$marqueur}", $workflow);
        // Le cron ne doit jamais faire échouer un déploiement déjà en ligne.
        $this->assertMatchesRegularExpression('/Ensure Laravel scheduler cron entry[^\n]*\n\s+continue-on-error: true/', $workflow);
        // Même variable que le cron pour le PHP des commandes planifiées.
        $this->assertStringContainsString('IONOS_PHP_CLI_PATH: ${{ vars.IONOS_PHP_CLI_PATH }}', $workflow);
    }

    public function test_les_marqueurs_de_production_et_de_preprod_different(): void
    {
        $production = (string) file_get_contents(base_path('.github/workflows/deploy.yaml'));

        $this->assertStringNotContainsString('preprod-scheduler', $production);
    }

    #[DataProvider('templates')]
    public function test_les_templates_d_env_rendent_schedule_php_binary_depuis_ionos_php_cli_path(string $fichier): void
    {
        $this->assertStringContainsString(
            'SCHEDULE_PHP_BINARY=${IONOS_PHP_CLI_PATH}',
            (string) file_get_contents(base_path($fichier)),
        );
    }

    /** @return array<string, array{string}> */
    public static function templates(): array
    {
        return [
            'production' => ['.github/deploy/.env.production.template'],
            'preprod' => ['.github/deploy/.env.preprod.template'],
        ];
    }
}
