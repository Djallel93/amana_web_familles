<?php
// app/Http/Controllers/GuideController.php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Guide\GuideContenu;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Guide d'utilisation » d'AMANA Familles (18/10/2026) — page ouverte par le bouton ❓ de la sidebar partagée
 * (route `guide.index`, voir config 'amana-shared.guide_route').
 *
 * Le contenu est filtré par rôle ICI, côté serveur (GuideContenu::sectionsPour()) : la page ne reçoit que les
 * sections que la personne a le droit de lire — jamais le texte des autres rôles, même caché en CSS ou présent
 * dans le bundle JS. Le squelette visuel est celui de @amana/shared-ui (GuidePage / GuideSection / GuideItem).
 */
class GuideController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Guide/GuideIndex', [
            'sections' => GuideContenu::sectionsPour($request->user()),
        ]);
    }
}
