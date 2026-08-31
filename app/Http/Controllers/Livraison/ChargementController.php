<?php
// app/Http/Controllers/Livraison/ChargementController.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Écran chargement — équipe_chargement confirme le "prêt à charger",
 * charge les véhicules, et signale les incidents (benevole_absent,
 * capacite, chargement_termine) — voir le prompt du 30/08/2026 §3.3
 * point 8 / §4 / §7. Voit les mêmes indicateurs de packaging (etudiant/
 * est_hotel/nombre_enfant) et note_besoins_speciaux que équipe_packaging,
 * en LECTURE SEULE, pour contexte uniquement.
 *
 * benevole_absent déclenche (Patch 4) la remise à `non_assignee` de tous
 * les arrêts/livraisons non livrés de la tournée, orphelinés dans le pool
 * non-assigné, en attente d'un re-clustering scopé déclenché manuellement
 * par l'admin (voir Patch 3 pour le moteur de clustering).
 *
 * SQUELETTE (Patch 1) — logique de confirmation/chargement et création
 * des route_incidents à écrire en Patch 4.
 */
class ChargementController extends Controller
{
    public function index(): View
    {
        return view('livraison.a-venir', ['titre' => 'Chargement']);
    }
}
