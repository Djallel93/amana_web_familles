{{-- resources/views/familles/partials/vue-famille-detail.blade.php --}}
{{--
    Point de montage du panneau de détail/édition famille — voir
    resources/js/components/familles/DetailPanel.vue. Extrait le
    10/09/2026 (Section A2 du refactor) : ce bloc était copié-collé à
    l'identique dans familles/index.blade.php, familles/nouvelles.blade.php
    et livraison/contacts.blade.php. Monté globalement par app.ts dès que
    #vue-famille-detail est présent sur la page.

    Attend $secteursActivite et $organismesAide (mêmes listes fermées que
    IntakeController::showForm, consommées par l'onglet Situation du
    panneau).
--}}
<div id="vue-famille-detail"
     data-update-url-template="{{ route('familles.update', ['id' => '__ID__']) }}"
     data-show-url-template="{{ route('familles.show', ['id' => '__ID__']) }}"
     data-deverrouiller-url-template="{{ route('familles.deverrouiller', ['id' => '__ID__']) }}"
     data-forcer-deverrouillage-url-template="{{ route('familles.forcer-deverrouillage', ['id' => '__ID__']) }}"
     data-upload-url-template="{{ route('familles.documents.store', ['id' => '__ID__']) }}"
     data-download-url-template="{{ route('familles.documents.download', ['id' => '__ID__', 'documentId' => '__DOC__']) }}"
     data-delete-doc-url-template="{{ route('familles.documents.destroy', ['id' => '__ID__', 'documentId' => '__DOC__']) }}"
     data-secteurs-activite="{{ $secteursActivite->toJson() }}"
     data-organismes-aide="{{ $organismesAide->toJson() }}"
     data-google-places-key="{{ config('services.google.maps.places_api_key') }}"
     data-google-embed-key="{{ config('services.google.maps.embed_api_key') }}">
</div>
