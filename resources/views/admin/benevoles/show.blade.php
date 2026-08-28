{{-- resources/views/admin/benevoles/show.blade.php --}}
@extends('layouts.app')

@section('title', 'Candidature bénévole — AMANA Familles')

@section('content')

    <div class="flex flex-wrap items-center justify-between gap-4 mb-7">
        <div>
            <a href="{{ route('admin.benevoles.index') }}" class="text-[12.5px] text-accent hover:underline">← Retour aux candidatures</a>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight mt-1">
                {{ $profil->personne?->prenom }} {{ $profil->personne?->nom }}
            </h1>
        </div>
        @if($profil->statut === 'Reçu')
            <div class="flex flex-wrap items-end gap-2">
                <form action="{{ route('admin.benevoles.valider', $profil->id) }}" method="POST"
                    data-confirm="Valider cette candidature bénévole ?" class="flex items-end gap-2">
                    @csrf
                    <div>
                        <label class="text-[10px] font-bold uppercase tracking-[0.6px] text-ink-muted block mb-1">Rôle attribué</label>
                        <select name="role"
                            class="px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-[13px] font-body text-ink bg-surface-2 outline-none transition
                                   focus:border-accent focus:shadow-[0_0_0_3px_rgba(3,105,161,0.2)] cursor-pointer">
                            @foreach($roles as $r)
                                @php $roleLabels = ['admin' => '🛡️ Administrateur', 'gestionnaire' => '⚙️ Gestionnaire', 'membre' => '👤 Membre', 'benevole' => '🤝 Bénévole']; @endphp
                                <option value="{{ $r->code }}" @selected($r->code === 'benevole')>{{ $roleLabels[$r->code] ?? $r->libelle }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[13px] font-bold rounded-lg transition-colors cursor-pointer min-h-[44px] flex items-center gap-1.5">
                        ✅ Valider
                    </button>
                </form>
                <form action="{{ route('admin.benevoles.rejeter', $profil->id) }}" method="POST"
                    data-confirm="Refuser la candidature de {{ $profil->personne?->prenom }} {{ $profil->personne?->nom }} ?" data-confirm-danger>
                    @csrf
                    <button type="submit" class="px-4 py-2.5 bg-rose-600 hover:bg-rose-700 text-white text-[13px] font-bold rounded-lg transition-colors cursor-pointer min-h-[44px] flex items-center gap-1.5">
                        ✕ Refuser
                    </button>
                </form>
            </div>
        @endif
    </div>

    <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-6 space-y-6">

        <div>
            <h2 class="text-[11px] font-bold uppercase tracking-[0.6px] text-ink-muted mb-2">Identité</h2>
            <dl class="grid grid-cols-2 gap-3 text-[13.5px]">
                <div><dt class="text-ink-muted text-[11.5px]">Email</dt><dd class="text-ink">{{ $profil->personne?->email }}</dd></div>
                <div><dt class="text-ink-muted text-[11.5px]">Téléphone</dt><dd class="text-ink">{{ $profil->personne?->telephone }}</dd></div>
                <div><dt class="text-ink-muted text-[11.5px]">Langue préférée</dt><dd class="text-ink">{{ $profil->langue_preferee }}</dd></div>
                <div><dt class="text-ink-muted text-[11.5px]">Statut</dt><dd class="text-ink">{{ $profil->statut }}</dd></div>
            </dl>
        </div>

        <div>
            <h2 class="text-[11px] font-bold uppercase tracking-[0.6px] text-ink-muted mb-2">Véhicule</h2>
            <dl class="grid grid-cols-2 gap-3 text-[13.5px]">
                <div><dt class="text-ink-muted text-[11.5px]">Permis</dt><dd class="text-ink">{{ $profil->permis ? 'Oui' : 'Non' }}</dd></div>
                <div><dt class="text-ink-muted text-[11.5px]">Type</dt><dd class="text-ink">{{ $profil->vehiculeType?->type }}</dd></div>
                <div><dt class="text-ink-muted text-[11.5px]">Capacité (référentiel)</dt><dd class="text-ink">{{ $profil->vehiculeType?->capacite_kg }} kg</dd></div>
                <div><dt class="text-ink-muted text-[11.5px]">Nb. parts max (référentiel)</dt><dd class="text-ink">{{ $profil->vehiculeType?->nombre_part_max }}</dd></div>
            </dl>
        </div>

        <div>
            <h2 class="text-[11px] font-bold uppercase tracking-[0.6px] text-ink-muted mb-2">Couverture géographique</h2>
            @if($profil->secteurs->isEmpty())
                <p class="text-ink-muted text-[13px] italic">Aucun secteur spécifique renseigné.</p>
            @else
                <div class="flex flex-wrap gap-1.5">
                    @foreach($profil->secteurs as $secteur)
                        <span class="inline-flex px-2 py-1 rounded-md text-[12px] bg-surface-2 text-ink border border-surface-3">{{ $secteur->nom }}</span>
                    @endforeach
                </div>
            @endif
        </div>

    </div>

@endsection
