{{-- resources/views/admin/benevoles/index.blade.php --}}
@extends('layouts.app')

@section('title', 'Candidatures bénévoles — AMANA Familles')

@section('content')

    <div class="flex flex-wrap items-center justify-between gap-4 mb-7">
        <div>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Candidatures bénévoles</h1>
            <p class="text-[13px] text-ink-muted mt-1">
                Revue des candidatures confirmées par email, en attente de validation.
            </p>
        </div>
    </div>

    <form method="GET" class="flex flex-wrap items-center gap-2 mb-4">
        <select name="statut" onchange="this.form.submit()"
            class="px-3 py-2 border border-surface-border rounded-lg text-[13px] bg-surface text-ink">
            <option value="">Toutes en attente (Reçu)</option>
            @foreach($statuts as $statut)
                <option value="{{ $statut }}" @selected(request('statut') === $statut)>{{ $statut }}</option>
            @endforeach
        </select>
        <select name="id_vehicule_type" onchange="this.form.submit()"
            class="px-3 py-2 border border-surface-border rounded-lg text-[13px] bg-surface text-ink">
            <option value="">Tous véhicules</option>
            @foreach($vehicules as $vehicule)
                <option value="{{ $vehicule->id }}" @selected((int) request('id_vehicule_type') === $vehicule->id)>{{ $vehicule->type }}</option>
            @endforeach
        </select>
    </form>

    <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden">
        @if($candidatures->isEmpty())
            <div class="text-center py-16 px-8">
                <div class="text-5xl mb-3 opacity-40">🤝</div>
                <h3 class="font-heading text-base font-semibold text-ink mb-1.5">Aucune candidature</h3>
                <p class="text-ink-muted text-[13.5px]">Aucune candidature ne correspond aux filtres sélectionnés.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            @foreach(['Candidat', 'Véhicule', 'Permis', 'Statut', 'Rôle', 'Actions'] as $col)
                                <th class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                                    {{ $col }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($candidatures as $candidature)
                            <tr class="border-b border-surface-3 last:border-0 hover:bg-surface-2 transition-colors">
                                <td class="px-4 py-2.5 text-ink align-top">
                                    <a href="{{ route('admin.benevoles.show', $candidature->id) }}" class="text-accent hover:underline font-semibold">
                                        {{ $candidature->personne?->prenom }} {{ $candidature->personne?->nom }}
                                    </a>
                                    <div class="text-ink-muted text-[11.5px]">{{ $candidature->personne?->email }}</div>
                                </td>
                                <td class="px-4 py-2.5 text-ink-muted align-top">{{ $candidature->vehiculeType?->type }}</td>
                                <td class="px-4 py-2.5 text-ink-muted align-top">{{ $candidature->permis ? '✅' : '—' }}</td>
                                <td class="px-4 py-2.5 align-top">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold border
                                        {{ $candidature->statut === 'Validé' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : ($candidature->statut === 'Rejeté' ? 'bg-rose-50 text-rose-700 border-rose-200' : 'bg-amber-50 text-amber-700 border-amber-200') }}">
                                        {{ $candidature->statut }}
                                    </span>
                                </td>
                                {{--
                                    Le <select> ci-dessous vit HORS du <form> "valider" (colonnes
                                    séparées, demande du 26/08/2026) mais y reste rattaché via
                                    l'attribut HTML5 form="..." — un élément de formulaire n'a pas
                                    besoin d'être un descendant DOM du <form> pour être soumis avec
                                    lui, il suffit de référencer son id. Supporté nativement par
                                    tous les navigateurs modernes.
                                --}}
                                <td class="px-4 py-2.5 align-top">
                                    @if($candidature->statut === 'Reçu')
                                        <select name="role" form="valider-benevole-{{ $candidature->id }}"
                                            class="w-full min-w-[140px] px-2.5 py-1.5 border-[1.5px] border-ink-faint rounded-lg text-[12.5px] font-body text-ink bg-surface-2 outline-none transition
                                                   focus:border-accent focus:shadow-[0_0_0_3px_rgba(3,105,161,0.2)] cursor-pointer">
                                            @foreach($roles as $r)
                                                @php $roleLabels = ['admin' => '🛡️ Administrateur', 'gestionnaire' => '⚙️ Gestionnaire', 'membre' => '👤 Membre', 'benevole' => '🤝 Bénévole']; @endphp
                                                <option value="{{ $r->code }}" @selected($r->code === 'benevole')>{{ $roleLabels[$r->code] ?? $r->libelle }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 align-top">
                                    @if($candidature->statut === 'Reçu')
                                        <div class="flex flex-col gap-2 min-w-[130px]">
                                            <form id="valider-benevole-{{ $candidature->id }}"
                                                action="{{ route('admin.benevoles.valider', $candidature->id) }}" method="POST"
                                                data-confirm="Valider cette candidature bénévole ?">
                                                @csrf
                                                <button type="submit"
                                                    class="w-full min-h-[38px] px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-[12.5px] rounded-lg cursor-pointer transition-colors flex items-center justify-center gap-1.5">
                                                    ✅ Valider
                                                </button>
                                            </form>
                                            <form action="{{ route('admin.benevoles.rejeter', $candidature->id) }}" method="POST"
                                                data-confirm="Refuser la candidature de {{ $candidature->personne?->prenom }} {{ $candidature->personne?->nom }} ?" data-confirm-danger>
                                                @csrf
                                                <button type="submit"
                                                    class="w-full min-h-[38px] px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white font-bold text-[12.5px] rounded-lg cursor-pointer transition-colors flex items-center justify-center gap-1.5">
                                                    ✕ Refuser
                                                </button>
                                            </form>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-surface-3">
                {{ $candidatures->links() }}
            </div>
        @endif
    </div>

@endsection
