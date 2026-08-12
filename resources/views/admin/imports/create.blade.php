{{-- resources/views/admin/imports/create.blade.php --}}
@extends('layouts.app')

@section('title', 'Nouvel import — AMANA Familles')

@section('content')

    <div class="mb-7">
        <a href="{{ route('admin.imports.index') }}" class="text-[13px] text-ink-muted hover:text-accent transition-colors no-underline">← Retour aux imports</a>
        <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight mt-2">Nouvel import</h1>
        <p class="text-[13px] text-ink-muted mt-1">Ajout ou mise à jour de plusieurs dossiers — les deux modes utilisent le même traitement (dédup par email/téléphone+nom, résolution géographique automatique).</p>
    </div>

    <div class="grid lg:grid-cols-2 gap-5 mb-8">

        {{-- Upload CSV --}}
        <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-6">
            <div class="flex items-center gap-2.5 mb-4">
                <div class="w-8 h-8 bg-sky-50 rounded-md flex items-center justify-center text-sm flex-shrink-0">📄</div>
                <h2 class="font-heading text-[15px] font-semibold text-ink">Fichier CSV</h2>
            </div>

            @if($errors->any())
                <div class="mb-4 px-3 py-2.5 rounded-md bg-rose-50 border border-rose-200 text-rose-800 text-[12.5px]">
                    {{ $errors->first() }}
                </div>
            @endif

            <form id="form-import-csv" action="{{ route('admin.imports.store-csv') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="file" name="fichier" accept=".csv,.txt" required
                    class="w-full text-[12.5px] text-ink-muted mb-3 file:mr-3 file:px-3 file:py-1.5 file:rounded-md file:border-0 file:bg-accent/10 file:text-accent-dark file:text-[12px] file:font-semibold">
                <button type="submit" id="btn-import-csv"
                    class="w-full min-h-[44px] px-4 py-2.5 bg-accent hover:bg-accent-dark disabled:opacity-50 disabled:cursor-not-allowed text-white text-[13px] font-semibold rounded-lg transition-colors cursor-pointer">
                    Importer le fichier
                </button>
            </form>

            <script>
                // Le POST reste une soumission de formulaire classique (redirection
                // pleine page à la fin) — pas besoin de fetch/AJAX ici. On bloque
                // juste le double-clic / double-soumission pendant le traitement,
                // qui reste synchrone côté serveur (voir ImportsController).
                document.getElementById('form-import-csv')?.addEventListener('submit', function () {
                    document.getElementById('btn-import-csv').disabled = true;
                    window.showImportOverlay?.();
                });
            </script>

            <details class="mt-4 text-[12px] text-ink-muted">
                <summary class="cursor-pointer font-semibold text-ink">Format attendu</summary>
                <p class="mt-2 leading-relaxed">
                    En-tête avec (au minimum) : <code class="bg-surface-2 px-1 rounded">nom</code>,
                    <code class="bg-surface-2 px-1 rounded">prenom</code>,
                    <code class="bg-surface-2 px-1 rounded">telephone</code>. Colonnes optionnelles :
                    email, telephone_bis, adresse, code_postal, ville, nombre_adulte, nombre_enfant,
                    zakat_el_fitr, sadaqa, se_deplace, criticite, langue, etat_dossier, commentaire_dossier.
                    Séparateur <code class="bg-surface-2 px-1 rounded">;</code> ou
                    <code class="bg-surface-2 px-1 rounded">,</code> détecté automatiquement.
                </p>
            </details>
        </div>

        {{-- Saisie manuelle --}}
        <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-6">
            <div class="flex items-center gap-2.5 mb-4">
                <div class="w-8 h-8 bg-amber-50 rounded-md flex items-center justify-center text-sm flex-shrink-0">✍️</div>
                <h2 class="font-heading text-[15px] font-semibold text-ink">Saisie manuelle</h2>
            </div>
            <p class="text-[12.5px] text-ink-muted mb-3">Utilisez plutôt le CSV si vous avez beaucoup de dossiers à saisir — ce tableau reste pratique pour quelques lignes.</p>
        </div>

    </div>

    {{-- La grille manuelle prend toute la largeur : plus lisible avec autant de colonnes --}}
    <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-6">
        <div id="vue-import-manual-grid" data-store-url="{{ route('admin.imports.store-manuel') }}"></div>
    </div>

    {{--
        Overlay de progression partagé par le formulaire CSV (ci-dessus, via
        JS vanilla) et la grille de saisie manuelle (ImportManualGrid.vue) —
        les deux imports restent synchrones côté serveur (une seule requête,
        pas de job dédié — voir ImportsController), donc pas de progression
        réelle ligne-par-ligne possible sans passage en file d'attente +
        polling. La barre reste volontairement indéterminée (balayage
        continu) plutôt que de simuler un pourcentage trompeur.

        Rendu via le composant Modal partagé (voir
        resources/js/components/imports/ImportOverlay.vue) plutôt qu'un div
        fait main — cohérent avec le reste de l'app (DetailPanel.vue).
    --}}
    <div id="vue-import-overlay"></div>

@endsection
