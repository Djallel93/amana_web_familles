{{-- resources/views/livraison/campagnes.blade.php --}}
@extends('layouts.app')

@section('title', 'Campagnes — AMANA Familles')

@section('content')
    <div class="max-w-3xl mx-auto py-8">
        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Campagnes</h1>
        <form id="csrf-holder">@csrf</form>

        <div class="bg-surface border border-surface-border rounded-xl p-5 mb-8">
            <h2 class="text-[14px] font-medium text-ink mb-4">Nouvelle campagne</h2>
            <form id="form-campagne" class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Type</label>
                    <select name="type" class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px]">
                        <option value="zakat_el_fitr">Zakat el-fitr</option>
                        <option value="collecte_alimentaire">Collecte alimentaire</option>
                        <option value="don_ponctuel">Don ponctuel</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Date de livraison</label>
                    <input type="date" name="date_livraison" class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px]" required>
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Poids moyen / personne (kg)</label>
                    <input type="number" step="0.1" name="poids_moyen_kg" class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px]" required>
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Poids moyen / personne — hôtel (kg, optionnel)</label>
                    <input type="number" step="0.1" name="poids_moyen_hotel_kg" class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px]">
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Poids moyen / personne — étudiant (kg, optionnel)</label>
                    <input type="number" step="0.1" name="poids_moyen_etudiant_kg" class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px]">
                </div>
                <div class="col-span-2">
                    <button type="submit" class="rounded-lg bg-accent text-white text-[14px] font-medium px-4 py-2">Créer la campagne</button>
                </div>
            </form>
        </div>

        <h2 class="text-[14px] font-medium text-ink mb-3">Campagnes existantes</h2>
        <div class="space-y-2">
            @forelse($campagnes as $campagne)
                <a href="{{ route('livraison.campagnes.show', $campagne) }}"
                    class="block bg-surface border border-surface-border rounded-xl p-4 hover:border-accent transition-colors">
                    <div class="flex items-center justify-between">
                        <span class="text-[14px] font-medium text-ink">
                            {{ ['zakat_el_fitr' => 'Zakat el-fitr', 'collecte_alimentaire' => 'Collecte alimentaire', 'don_ponctuel' => 'Don ponctuel'][$campagne->type] ?? $campagne->type }}
                            — {{ $campagne->date_livraison->format('d/m/Y') }}
                        </span>
                        <span class="text-[12px] text-ink-muted">{{ $campagne->statut }}</span>
                    </div>
                </a>
            @empty
                <p class="text-[14px] text-ink-muted">Aucune campagne pour le moment.</p>
            @endforelse
        </div>
    </div>

    <script>
        const csrf = document.querySelector('#csrf-holder input[name="_token"]').value;

        document.getElementById('form-campagne').addEventListener('submit', async function (e) {
            e.preventDefault();
            const donnees = Object.fromEntries(new FormData(e.target).entries());

            const reponse = await fetch('{{ route('livraison.campagnes.store') }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(donnees),
            });
            const resultat = await reponse.json();

            if (resultat.success) {
                window.location.href = `/livraison/campagnes/${resultat.campagne.id}`;
            } else {
                alert('Erreur : ' + JSON.stringify(resultat.errors ?? resultat.message));
            }
        });
    </script>
@endsection
