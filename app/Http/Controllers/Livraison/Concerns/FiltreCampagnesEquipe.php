<?php
// app/Http/Controllers/Livraison/Concerns/FiltreCampagnesEquipe.php

declare(strict_types=1);

namespace App\Http\Controllers\Livraison\Concerns;

use Amana\Shared\Models\Personne;
use App\Models\Campagne;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Filtrage des campagnes proposées par choisir() (PosteReleveController
 * pour pesée/réception depuis le 10/09/2026 — voir sa fusion Section A1
 * du refactor —, PackagingController, ChargementController) — extrait
 * en trait le 08/09/2026 (prompt de cette date), les 4 implémentations
 * étant identiques à la relation ->with('journees') près (absente côté
 * ChargementController, qui n'affiche pas de sélecteur de journée).
 *
 * Décision du 08/09/2026 : choisir() ne doit lister QUE les campagnes où
 * la personne a une ligne campagne_equipe_membres pour ce rôle — pas
 * toutes les campagnes actives comme avant (ancien comportement, qui ne
 * distinguait pas encore affectation globale et affectation par
 * campagne). Un admin/gestionnaire continue de voir toutes les campagnes
 * actives (même bypass que partout ailleurs dans le domaine, voir
 * CampagnePolicy) — lui seul peut de toute façon peupler
 * campagne_equipe_membres depuis le nouvel écran d'admin, il n'y a pas de
 * sens à le restreindre ici.
 *
 * Application encore en dev au moment de ce patch (voir prompt) : pas de
 * backfill de campagne_equipe_membres à partir de l'ancien rôle global —
 * toute personne equipe_* pas encore affectée verra une liste vide
 * (message déjà géré par choisir-poste.blade.php) jusqu'à affectation
 * manuelle via le nouvel écran.
 */
trait FiltreCampagnesEquipe
{
    /**
     * @return Collection<int, Campagne>
     */
    private function campagnesPourEquipe(Personne $personne, string $role, bool $avecJournees): Collection
    {
        $requete = Campagne::whereIn('statut', ['preparation', 'en_cours'])
            ->when($avecJournees, fn (Builder $q) => $q->with('journees'))
            ->orderByDesc('date_livraison');

        if ($personne->isAdmin() || $personne->isGestionnaire()) {
            return $requete->get();
        }

        return $requete->get()->filter(fn (Campagne $campagne) => $campagne->aRole($personne->id, $role))->values();
    }
}
