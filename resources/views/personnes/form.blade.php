{{-- resources/views/personnes/form.blade.php --}}
@extends('layouts.app')

@section('title', ($personne ? 'Modifier' : 'Ajouter') . ' une personne — AMANA Familles')

@section('content')

    <div class="max-w-xl mx-auto">

        <div class="mb-7">
            <a href="{{ route('admin.personnes.index') }}" class="text-[13px] text-ink-muted hover:text-accent transition-colors no-underline">
                ← Retour à la liste
            </a>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight mt-2">
                {{ $personne ? 'Modifier ' . $personne->prenom . ' ' . $personne->nom : 'Ajouter une personne' }}
            </h1>
            <p class="text-[13px] text-ink-muted mt-1">
                @if($personne)
                    Modifier les informations et le rôle sur AMANA Familles.
                @else
                    Si l'adresse email correspond à un compte AMANA existant (ex : déjà staff Planning), le rôle
                    Familles lui est simplement ajouté — aucun doublon n'est créé.
                @endif
            </p>
        </div>

        <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-6">
            <form action="{{ $personne ? route('admin.personnes.update', $personne->id) : route('admin.personnes.store') }}"
                method="POST">
                @csrf
                @if($personne) @method('PUT') @endif

                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="prenom" class="block text-xs font-bold text-ink mb-1.5 tracking-[0.2px]">Prénom</label>
                        <input type="text" id="prenom" name="prenom" value="{{ old('prenom', $personne->prenom ?? '') }}"
                            required autofocus
                            class="w-full px-3.5 py-2.5 border-[1.5px] border-ink-faint rounded-lg text-[14px] font-body text-ink bg-surface-2 outline-none transition
                                    focus:border-accent focus:bg-surface focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]">
                        @error('prenom')<span class="block text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
                    </div>
                    <div>
                        <label for="nom" class="block text-xs font-bold text-ink mb-1.5 tracking-[0.2px]">Nom</label>
                        <input type="text" id="nom" name="nom" value="{{ old('nom', $personne->nom ?? '') }}" required
                            class="w-full px-3.5 py-2.5 border-[1.5px] border-ink-faint rounded-lg text-[14px] font-body text-ink bg-surface-2 outline-none transition
                                    focus:border-accent focus:bg-surface focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]">
                        @error('nom')<span class="block text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="mb-4">
                    <label for="email" class="block text-xs font-bold text-ink mb-1.5 tracking-[0.2px]">Adresse email</label>
                    <input type="email" id="email" name="email" value="{{ old('email', $personne->email ?? '') }}"
                        {{ $personne ? 'readonly' : 'required' }}
                        class="w-full px-3.5 py-2.5 border-[1.5px] border-ink-faint rounded-lg text-[14px] font-body text-ink bg-surface-2 outline-none transition
                                focus:border-accent focus:bg-surface focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]
                                {{ $personne ? 'opacity-60 cursor-not-allowed' : '' }}">
                    @if($personne)
                        <p class="text-[11.5px] text-ink-muted mt-1">L'adresse email d'un compte existant ne peut pas être modifiée ici.</p>
                    @endif
                    @error('email')<span class="block text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
                </div>

                <div class="mb-4">
                    <label for="telephone" class="block text-xs font-bold text-ink mb-1.5 tracking-[0.2px]">Téléphone</label>
                    <input type="text" id="telephone" name="telephone" value="{{ old('telephone', $personne->telephone ?? '') }}"
                        class="w-full px-3.5 py-2.5 border-[1.5px] border-ink-faint rounded-lg text-[14px] font-body text-ink bg-surface-2 outline-none transition
                                focus:border-accent focus:bg-surface focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]">
                    @error('telephone')<span class="block text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
                </div>

                <div class="mb-6">
                    <label for="role" class="block text-xs font-bold text-ink mb-1.5 tracking-[0.2px]">Rôle sur AMANA Familles</label>
                    <select id="role" name="role" required
                        class="w-full px-3.5 py-2.5 border-[1.5px] border-ink-faint rounded-lg text-[14px] font-body text-ink bg-surface-2 outline-none transition
                                focus:border-accent focus:bg-surface focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]">
                        <option value="" disabled {{ !old('role', $roleActuel) ? 'selected' : '' }}>Sélectionner un rôle…</option>
                        @foreach($roles as $role)
                            <option value="{{ $role->code }}" {{ old('role', $roleActuel) === $role->code ? 'selected' : '' }}>
                                {{ $role->libelle }}
                            </option>
                        @endforeach
                    </select>
                    <p class="text-[11.5px] text-ink-muted mt-1">
                        Permissions fines à affiner au fil des écrans — pour l'instant admin/gestionnaire ont accès
                        complet, membre/bénévole sont réservés pour un usage futur.
                    </p>
                    @error('role')<span class="block text-xs text-rose-600 mt-1">{{ $message }}</span>@enderror
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                        class="flex-1 min-h-[46px] px-6 py-2.5 bg-accent hover:bg-accent-dark text-white font-bold text-[13.5px] rounded-lg
                                shadow-[0_3px_12px_rgba(180,83,9,0.3)] hover:-translate-y-px active:translate-y-0 transition-all cursor-pointer">
                        {{ $personne ? '💾 Enregistrer' : '✉️ Créer et envoyer l\'invitation' }}
                    </button>
                    <a href="{{ route('admin.personnes.index') }}"
                        class="min-h-[46px] px-5 py-2.5 border border-surface-border bg-surface hover:bg-surface-2 text-ink text-[13.5px] font-semibold rounded-lg
                                transition-colors no-underline flex items-center">
                        Annuler
                    </a>
                </div>
            </form>
        </div>
    </div>

@endsection
