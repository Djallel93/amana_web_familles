{{-- resources/views/livraison/packaging.blade.php --}}
@extends('layouts.app')

@section('title', 'Packaging — AMANA Familles')

@section('content')
    <div class="max-w-3xl mx-auto py-8">
        <div class="flex items-center justify-between mb-6">
            <h1 class="font-heading text-xl font-semibold text-ink">Packaging — file de priorité</h1>
            <a href="{{ route('livraison.packaging.feuille-preparation', $campagne) }}" target="_blank"
                class="text-[13px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted">
                🖨️ Feuille de préparation
            </a>
        </div>

        <form id="csrf-holder">@csrf</form>

        <div class="space-y-3">
            @forelse($livraisons as $livraison)
                <div class="bg-surface border border-surface-border rounded-xl p-4 flex items-center justify-between" id="livraison-{{ $livraison->id }}">
                    <div>
                        <p class="text-[14px] font-medium text-ink">
                            {{ $livraison->famille->prenom }} {{ $livraison->famille->nom }}
                            <span class="text-[12px] text-ink-muted">— {{ $livraison->nombre_personnes }} pers.</span>
                        </p>
                        <div class="flex gap-1.5 mt-1">
                            @if($livraison->famille->etudiant)
                                <span class="text-[11px] px-2 py-0.5 rounded-full bg-sky-100 text-sky-700">Étudiant</span>
                            @endif
                            @if($livraison->famille->est_hotel)
                                <span class="text-[11px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">Hôtel</span>
                            @endif
                            @if($livraison->famille->nombre_enfant > 0)
                                <span class="text-[11px] px-2 py-0.5 rounded-full bg-violet-100 text-violet-700">{{ $livraison->famille->nombre_enfant }} enfant(s)</span>
                            @endif
                        </div>
                        @if($livraison->note_besoins_speciaux)
                            <p class="text-[12px] text-rose-600 mt-1">⚠ {{ $livraison->note_besoins_speciaux }}</p>
                        @endif
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <a href="{{ route('livraison.packaging.etiquettes', $livraison) }}" target="_blank"
                            class="text-[12px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted">🖨️ Étiquettes</a>
                        <button type="button" onclick="marquerPret({{ $livraison->id }})"
                            class="text-[12px] px-3 py-1.5 rounded-lg bg-accent text-white">Prêt ✓</button>
                    </div>
                </div>
            @empty
                <p class="text-[14px] text-ink-muted">Aucune livraison en attente de conditionnement.</p>
            @endforelse
        </div>
    </div>

    <script>
        const csrf = document.querySelector('#csrf-holder input[name="_token"]').value;

        async function marquerPret(id) {
            const reponse = await fetch(`/livraison/packaging/${id}/pret`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            });
            const resultat = await reponse.json();
            if (resultat.success) {
                document.getElementById(`livraison-${id}`).remove();
            }
        }
    </script>
@endsection
