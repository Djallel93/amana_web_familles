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
}
