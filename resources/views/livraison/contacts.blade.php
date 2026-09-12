{{-- resources/views/livraison/contacts.blade.php --}}
@extends('layouts.app')

@section('title', 'Suivi des contacts — AMANA Familles')

@section('content')
    <div class="max-w-5xl mx-auto py-8">
        {{-- Retour (prompt du 05/09/2026 §2.1) — gestionnaire a bien accès
             à livraison.campagnes.show (contrairement à équipe_pesee/
             reception/packaging, voir pesee.blade.php/reception.blade.php),
             donc ce lien pointe directement vers la campagne d'où l'on
             vient si elle est connue (?id_campagne= dans l'URL), sinon la
             liste des campagnes.
             Relabellé "← Retour à la campagne" et restylé en bouton
             plein bg-ink (07/09/2026, prompt §2.6 : "in black, use the
             same as in Pesee") — cohérent avec Pesee/Réception/
             Packaging/Chargement/Suivi livraison. --}}
        <a id="lien-retour" href="{{ route('livraison.campagnes.index') }}"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
            ← Retour à la campagne
        </a>

        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Suivi des contacts</h1>

        <div id="vue-livraison-contacts-queue" data-campagnes="{{ $campagnes->toJson() }}"
            data-villes="{{ $villes->toJson() }}"
            data-secteurs="{{ $secteurs->toJson() }}"
            data-quartiers="{{ $quartiers->toJson() }}"
            data-organisations="{{ $organisations->toJson() }}"
            data-queue-url="{{ route('livraison.contacts.queue') }}"
            data-statistiques-url="{{ route('livraison.contacts.statistiques') }}"
            data-assigner-url-template="{{ route('livraison.contacts.assigner', '__ID__') }}"
            data-assigner-lot-url="{{ route('livraison.contacts.assigner-lot') }}"
            data-contacter-manuel-url-template="{{ route('livraison.contacts.contacter-manuel', '__ID__') }}">
        </div>
    </div>

    {{--
        Point de montage du panneau de détail/édition famille (05/09/2026,
        prompt §2.3) — même composant, mêmes routes et mêmes référentiels
        que resources/views/familles/index.blade.php : "if family needs to
        be edited when contacted, open the Family panel. same rules apply".
        Monté globalement par app.ts dès que #vue-famille-detail est
        présent sur la page. Bloc partagé depuis le 10/09/2026 (Section A2
        du refactor) — voir familles/partials/vue-famille-detail.blade.php,
        auparavant copié-collé à l'identique ici et sur les deux vues
        familles/.
    --}}
    @include('familles.partials.vue-famille-detail', ['secteursActivite' => $secteursActivite, 'organismesAide' => $organismesAide])

    <script>
        // Le lien de retour pointe vers la campagne précise quand l'écran a
        // été ouvert depuis CampagneDetail.vue (?id_campagne=…) plutôt que
        // vers la liste — voir data-contacts-url dans campagne-detail.blade.php.
        const paramsRetour = new URLSearchParams(window.location.search);
        const idCampagneRetour = paramsRetour.get('id_campagne');
        if (idCampagneRetour) {
            document.getElementById('lien-retour').href = `/livraison/campagnes/${idCampagneRetour}`;
        }
    </script>
@endsection
