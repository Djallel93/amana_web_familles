<?php
// tests/Feature/Http/LayoutClocheNotificationsTest.php

declare(strict_types=1);

namespace Tests\Feature\Http;

use PHPUnit\Framework\TestCase;

/**
 * 23/10/2026 — la cloche de notifications est en position fixed (coin supérieur droit) : elle
 * recouvrait « Créer une campagne » / « Créer une famille ». Le layout réserve sa place avec un
 * padding-haut de 3.75rem (pt-15) sur <main> — voir amana_shared/docs/notification-bell-layout.md.
 * Test de non-régression sur le shell Inertia de l'app (le shell Blade partagé a le même garde-fou
 * côté amana_shared).
 */
class LayoutClocheNotificationsTest extends TestCase
{
    public function test_le_shell_inertia_reserve_la_place_de_la_cloche_sur_main(): void
    {
        $shell = (string) file_get_contents(dirname(__DIR__, 3) . '/resources/views/app.blade.php');

        preg_match('/<main class="([^"]*)"/', $shell, $correspondance);
        $classes = preg_split('/\s+/', $correspondance[1] ?? '') ?: [];

        $this->assertContains('pt-15', $classes, 'main doit réserver 3.75rem en haut pour la cloche (fixed top-right).');
        $this->assertNotContains('p-8', $classes, 'p-8 ramènerait le haut à 2rem : la cloche recouvrirait le coin supérieur droit.');
    }
}
