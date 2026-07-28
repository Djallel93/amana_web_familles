<?php
// app/Http/Controllers/SettingsController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use Amana\Shared\Http\Controllers\SettingsControllerBase;

class SettingsController extends SettingsControllerBase
{
    protected function appCode(): string
    {
        return 'familles';
    }
}