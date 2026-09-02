{{-- resources/views/livraison/statistiques.blade.php --}}
@extends('layouts.app')

@section('title', 'Statistiques livraison — AMANA Familles')

@section('content')
    <div class="max-w-3xl mx-auto py-8">
        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Statistiques livraison</h1>
        <form id="csrf-holder">@csrf</form>

        <select id="filtre-campagne" class="rounded-lg border border-surface-border px-3 py-2 text-[13px] mb-6">
            <option value="">— Choisir une campagne —</option>
            @foreach($campagnes as $campagne)
                <option value="{{ $campagne->id }}">{{ $campagne->date_livraison->format('d/m/Y') }} — {{ $campagne->type }}</option>
            @endforeach
        </select>

        <div id="stats-live" class="hidden bg-surface border border-surface-border rounded-xl p-5 mb-8">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-[14px] font-medium text-ink">Statistiques en direct</h2>
                <button id="btn-snapshot" onclick="snapshotter()" class="text-[12px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted">
                    📸 Enregistrer un instantané
                </button>
            </div>
            <div id="grille-stats" class="grid grid-cols-2 gap-4 text-[13px]"></div>
        </div>

        <div class="bg-surface border border-surface-border rounded-xl p-5">
            <h2 class="text-[14px] font-medium text-ink mb-4">Comparaison historique</h2>
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left text-ink-muted border-b border-surface-border">
                        <th class="py-2">Campagne</th>
                        <th>Ménages</th>
                        <th>Poids collecté</th>
                        <th>Taux livraison</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($historique as $snapshot)
                        <tr class="border-b border-surface-border">
                            <td class="py-2">{{ $snapshot->campagne->date_livraison->format('d/m/Y') }} — {{ $snapshot->campagne->type }}</td>
                            <td>{{ $snapshot->donnees['nombre_menages'] ?? '—' }}</td>
                            <td>{{ $snapshot->donnees['poids_collecte_kg'] ?? '—' }} kg</td>
                            <td>{{ isset($snapshot->donnees['taux_livraison']) ? round($snapshot->donnees['taux_livraison'] * 100) . '%' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-ink-muted">Aucun instantané enregistré pour le moment.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        const csrfToken = document.querySelector('#csrf-holder input[name="_token"]').value;
        let campagneCourante = null;

        document.getElementById('filtre-campagne').addEventListener('change', async function () {
            campagneCourante = this.value;
            if (!campagneCourante) {
                document.getElementById('stats-live').classList.add('hidden');
                return;
            }

            const reponse = await fetch(`/livraison/statistiques/${campagneCourante}/donnees`, { headers: { 'Accept': 'application/json' } });
            const stats = await reponse.json();

            const grille = document.getElementById('grille-stats');
            grille.innerHTML = `
                <div><span class="text-ink-muted">Ménages (donateurs)</span><br><strong>${stats.nombre_menages}</strong></div>
                <div><span class="text-ink-muted">Poids collecté</span><br><strong>${stats.poids_collecte_kg} kg</strong></div>
                <div><span class="text-ink-muted">Livraisons totales</span><br><strong>${stats.livraisons_total}</strong></div>
                <div><span class="text-ink-muted">Livrées</span><br><strong>${stats.livraisons_par_statut.livree ?? 0}</strong></div>
                <div><span class="text-ink-muted">Poids livré</span><br><strong>${stats.poids_livre_kg} kg</strong></div>
                <div><span class="text-ink-muted">Tournées</span><br><strong>${stats.routes_total}</strong></div>
                <div><span class="text-ink-muted">Distance totale</span><br><strong>${stats.distance_totale_km.toFixed(1)} km</strong></div>
                <div><span class="text-ink-muted">Taux de livraison</span><br><strong>${Math.round(stats.taux_livraison * 100)}%</strong></div>
            `;

            document.getElementById('stats-live').classList.remove('hidden');
        });

        async function snapshotter() {
            const reponse = await fetch(`/livraison/statistiques/${campagneCourante}/snapshot`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            });
            const resultat = await reponse.json();
            alert(resultat.success ? 'Instantané enregistré.' : (resultat.message ?? 'Erreur.'));
            if (resultat.success) window.location.reload();
        }
    </script>
@endsection
