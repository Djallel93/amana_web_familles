{{-- resources/views/livraison/contacts.blade.php --}}
@extends('layouts.app')

@section('title', 'Suivi des contacts — AMANA Familles')

@section('content')
    <div class="max-w-3xl mx-auto py-8">
        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Suivi des contacts</h1>

        {{-- Reconstruit en Vue le 03/09/2026 (voir ContactsQueue.vue) —
             $campagnes reste passé ici (même requête que
             ContactTrackingController::index() avant ce changement) : la
             Blade la connaît déjà, pas besoin d'un aller-retour JSON en
             plus pour un select de campagnes qui ne change pas pendant la
             session. --}}
        <div id="vue-livraison-contacts-queue" data-campagnes="{{ $campagnes->toJson() }}"
            data-queue-url="{{ route('livraison.contacts.queue') }}"
            data-assigner-url-template="{{ route('livraison.contacts.assigner', '__ID__') }}"
            data-assigner-lot-url="{{ route('livraison.contacts.assigner-lot') }}"
            data-contacter-manuel-url-template="{{ route('livraison.contacts.contacter-manuel', '__ID__') }}">
        </div>
    </div>
@endsection
