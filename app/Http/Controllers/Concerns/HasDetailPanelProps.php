<?php
// app/Http/Controllers/Concerns/HasDetailPanelProps.php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

/**
 * Props DetailPanel.vue (resources/js/components/familles/DetailPanel.vue)
 * — extrait le 16/09/2026 (Section E4 du refactor, chunk contacts du
 * domaine livraison) depuis FamillesController::detailPanelProps()
 * (chunk 4, 12/09/2026), qui restait alors private et n'avait qu'un seul
 * appelant. ContactTrackingController::index() en a désormais besoin lui
 * aussi (contacts.blade.php montait DetailPanel.vue via l'ancien pont
 * double-mode que ce même chunk retire — voir son docblock) : mêmes
 * routes familles.*, même clés Google Maps, extraction pure sans aucun
 * changement de comportement, sur le modèle des traits déjà utilisés
 * dans App\Http\Controllers\Livraison\Concerns pour la même raison
 * (logique identique entre plusieurs contrôleurs).
 *
 * secteursActivite/organismesAide restent hors de ce trait : ce sont des
 * référentiels propres à chaque écran (voir index()/nouvelles()/
 * ContactTrackingController::index()), pas des props du panneau
 * lui-même.
 */
trait HasDetailPanelProps
{
    private function detailPanelProps(): array
    {
        return [
            'showUrlTemplate' => route('familles.show', ['id' => '__ID__']),
            'updateUrlTemplate' => route('familles.update', ['id' => '__ID__']),
            'deverrouillerUrlTemplate' => route('familles.deverrouiller', ['id' => '__ID__']),
            'renouvelerVerrouUrlTemplate' => route('familles.renouveler-verrou', ['id' => '__ID__']),
            'forcerDeverrouillageUrlTemplate' => route('familles.forcer-deverrouillage', ['id' => '__ID__']),
            'uploadUrlTemplate' => route('familles.documents.store', ['id' => '__ID__']),
            'downloadUrlTemplate' => route('familles.documents.download', ['id' => '__ID__', 'documentId' => '__DOC__']),
            'deleteDocUrlTemplate' => route('familles.documents.destroy', ['id' => '__ID__', 'documentId' => '__DOC__']),
            'initialGooglePlacesKey' => config('services.google.maps.places_api_key'),
            'initialGoogleEmbedKey' => config('services.google.maps.embed_api_key'),
        ];
    }
}
