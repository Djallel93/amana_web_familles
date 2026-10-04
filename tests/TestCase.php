<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\MigratesCommunConnection;

abstract class TestCase extends BaseTestCase
{
    // Embeds Illuminate\Foundation\Testing\RefreshDatabase itself — see
    // MigratesCommunConnection's docblock for why it isn't also `use`d
    // here directly (fatal trait method-name conflict).
    use MigratesCommunConnection;

    protected function setUp(): void
    {
        parent::setUp();

        // Les tests HTTP rendent des pages Inertia, donc `app.blade.php`, qui
        // appelle @vite(). Sans build des assets (`public/build/manifest.json`,
        // absent en CI et sur un clone neuf) chaque rendu levait
        // ViteManifestNotFoundException. Les tests vérifient les props et le
        // HTML, jamais les assets compilés : on neutralise Vite plutôt que
        // d'imposer `npm run build` à la porte de qualité.
        $this->withoutVite();
    }
}
