{{-- resources/views/livraison/campagne-detail.blade.php --}}
@extends('layouts.app')

@section('title', 'Campagne — AMANA Familles')

@section('content')
    <div class="max-w-4xl mx-auto py-8">
        {{--
            Retour (prompt du 05/09/2026 §1.1 : "not visible enough") —
            remplacé par un vrai bouton bordé, plus grand, avec flèche et
            hover, au lieu du simple lien texte 12px discret d'avant.
        --}}
        <a href="{{ route('livraison.campagnes.index') }}"
            class="inline-flex items-center gap-1.5 text-[13px] font-medium text-ink border border-surface-border rounded-lg px-3 py-1.5 mb-4 hover:bg-stone-50">
            ← Retour aux campagnes
        </a>

        {{-- Reconstruit en Vue le 03/09/2026 (voir CampagneDetail.vue) —
             la Blade ne passe plus que ce qu'elle est seule à connaître
             sans aller-retour JSON (l'enregistrement Campagne déjà chargé,
             les référentiels quartiers/villes/secteurs/organisations pour
             le filtre partagé — voir CampagnesController::show()) ; tout
             le reste (éligibles/génération/notification) est chargé et mis
             à jour côté Vue via l'API JSON existante.

             generer-routes-url / non-couvertes-url retirés le 05/09/2026
             (prompt §1.5/§1.8) : le clustering et la liste "jamais
             couvertes" ont quitté cet écran (voir ContactsQueue.vue et
             ShortfallPanel.vue respectivement). update-url ajouté pour
             l'édition HQ/commentaire (§1.2/§1.3). --}}
        <div id="vue-livraison-campagne-detail"
            data-campagne="{{ $campagne->toJson() }}"
            data-quartiers="{{ $quartiers->toJson() }}"
            data-villes="{{ $villes->toJson() }}"
            data-secteurs="{{ $secteurs->toJson() }}"
            data-organisations="{{ $organisations->toJson() }}"
            data-google-places-key="{{ config('services.google.maps.places_api_key') }}"
            data-eligibles-url="{{ route('livraison.campagnes.eligibles', $campagne) }}"
            data-generer-livraisons-url="{{ route('livraison.campagnes.generer-livraisons', $campagne) }}"
            {{-- Clustering déplacé ici depuis Suivi des contacts (09/09/2026, prompt §2.2). --}}
            data-generer-routes-url="{{ route('livraison.campagnes.generer-routes', $campagne) }}"
            {{-- Réutilisée pour la gate du clustering (09/09/2026 §2.2) — même endpoint que ContactsQueue.vue. --}}
            data-queue-url="{{ route('livraison.contacts.queue') }}"
            {{-- Ajoutée le 09/09/2026 (prompt de cette date §1.5, second passage) : total de la journée pour distinguer "rien à contacter" de "rien du tout". --}}
            data-contacts-statistiques-url="{{ route('livraison.contacts.statistiques') }}"
            data-benevoles-url="{{ route('livraison.campagnes.benevoles.index', $campagne) }}"
            data-equipes-url="{{ route('livraison.campagnes.equipes.index', $campagne) }}"
            {{-- Ajoutée le 09/09/2026 (prompt de cette date §1.1). --}}
            data-reception-url="{{ route('livraison.reception.show', $campagne) }}"
            data-ajouter-journee-url="{{ route('livraison.campagnes.journees.store', $campagne) }}"
            data-avancement-url="{{ route('livraison.campagnes.avancement', $campagne) }}"
            data-update-url="{{ route('livraison.campagnes.update', $campagne) }}"
            data-contacts-url="{{ route('livraison.contacts.index', ['id_campagne' => $campagne->id]) }}"
            data-pesee-url="{{ route('livraison.pesee.show', $campagne) }}"
            data-packaging-url="{{ route('livraison.packaging.index', $campagne) }}"
            data-chargement-url="{{ route('livraison.chargement.index', $campagne) }}"
            data-suivi-livraison-url="{{ route('livraison.suivi-livraison.index', $campagne) }}"
            {{-- Ajoutée le 09/09/2026 (prompt de cette date §1.3) : campagne présélectionnée sur Statistiques, même patron que suivi-livraison-url ci-dessus. --}}
            data-statistiques-url="{{ route('livraison.statistiques.index', $campagne) }}">
        </div>
    </div>
@endsection
