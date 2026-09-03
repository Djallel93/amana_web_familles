{{-- resources/views/admin/verifications/index.blade.php --}}
@extends('layouts.app')

@section('title', 'Vérifications — AMANA Familles')

@section('content')

    <div class="flex flex-wrap items-center justify-between gap-4 mb-7">
        <div>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Vérification des informations</h1>
            <p class="text-[13px] text-ink-muted mt-1">
                Envoie un email aux familles au dossier <strong>validé</strong> leur demandant de confirmer que leurs informations sont toujours à jour.
            </p>
        </div>
        <form action="{{ route('admin.verifications.envoyer') }}" method="POST"
            data-confirm="Envoyer un email de vérification à toutes les familles éligibles ?">
            @csrf
            <button type="submit"
                class="inline-flex items-center gap-2 px-4 py-2.5 bg-accent hover:bg-accent-dark text-white text-[13px] font-semibold rounded-lg
                            shadow-[0_3px_12px_rgba(180,83,9,0.3)] hover:-translate-y-px active:translate-y-0 transition-all cursor-pointer min-h-[44px]">
                📧 Envoyer les vérifications
            </button>
        </form>
    </div>

    <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden">
        @if($verifications->isEmpty())
            <div class="text-center py-16 px-8">
                <div class="text-5xl mb-3 opacity-40">📧</div>
                <h3 class="font-heading text-base font-semibold text-ink mb-1.5">Aucune vérification envoyée</h3>
                <p class="text-ink-muted text-[13.5px]">Utilisez le bouton ci-dessus pour lancer un envoi vers les dossiers validés.</p>
            </div>
        @else
            {{-- Tableau desktop (≥ md) — voir carte mobile juste après --}}
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            @foreach(['Famille', 'Envoyée le', 'Expire le', 'Statut'] as $col)
                                <th class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                                    {{ $col }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($verifications as $verification)
                            <tr class="border-b border-surface-3 last:border-0 hover:bg-surface-2 transition-colors">
                                <td class="px-4 py-2.5 text-ink">
                                    {{ $verification->famille?->prenom }} {{ $verification->famille?->nom ?? '(dossier supprimé)' }}
                                </td>
                                <td class="px-4 py-2.5 text-ink-muted">{{ $verification->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-2.5 text-ink-muted">{{ $verification->expires_at->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-2.5">
                                    @if($verification->estConfirmee())
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold border bg-emerald-50 text-emerald-700 border-emerald-200">
                                            ✅ Confirmée le {{ $verification->confirmed_at->format('d/m/Y') }}
                                        </span>
                                    @elseif($verification->estExpiree())
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold border bg-gray-100 text-gray-500 border-gray-300">
                                            ⏰ Expirée
                                        </span>
                                    @else
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold border bg-amber-50 text-amber-700 border-amber-200">
                                            ⏳ En attente
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Cartes mobile (< md) — 04/09/2026 --}}
            <div class="md:hidden divide-y divide-surface-3">
                @foreach($verifications as $verification)
                    <div class="px-4 py-3.5">
                        <div class="flex items-start justify-between gap-2 mb-2">
                            <div class="min-w-0">
                                <div class="font-semibold text-[13.5px] text-ink truncate">
                                    {{ $verification->famille?->prenom }} {{ $verification->famille?->nom ?? '(dossier supprimé)' }}
                                </div>
                                <div class="text-[11.5px] text-ink-muted mt-0.5">Envoyée le {{ $verification->created_at?->format('d/m/Y H:i') }}</div>
                                <div class="text-[11.5px] text-ink-muted">Expire le {{ $verification->expires_at->format('d/m/Y H:i') }}</div>
                            </div>
                            @if($verification->estConfirmee())
                                <span class="inline-flex flex-shrink-0 px-2 py-0.5 rounded-full text-[11px] font-semibold border bg-emerald-50 text-emerald-700 border-emerald-200">
                                    ✅ Confirmée
                                </span>
                            @elseif($verification->estExpiree())
                                <span class="inline-flex flex-shrink-0 px-2 py-0.5 rounded-full text-[11px] font-semibold border bg-gray-100 text-gray-500 border-gray-300">
                                    ⏰ Expirée
                                </span>
                            @else
                                <span class="inline-flex flex-shrink-0 px-2 py-0.5 rounded-full text-[11px] font-semibold border bg-amber-50 text-amber-700 border-amber-200">
                                    ⏳ En attente
                                </span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="px-4 py-3 border-t border-surface-3">
                {{ $verifications->links() }}
            </div>
        @endif
    </div>

@endsection
