{{-- resources/views/livraison/pesee.blade.php --}}
@extends('layouts.app')

@section('title', 'Pesée des dons — AMANA Familles')

@section('content')
    <div class="max-w-lg mx-auto py-8">
        {{--
            Retour (prompt du 05/09/2026 §3.1) : équipe_pesee n'a PAS accès
            à livraison.campagnes.show (réservé gestionnaire, voir la
            matrice de droits) — retour vers son propre point d'entrée
            (choisir()) plutôt qu'un lien qui échouerait en 403.
        --}}
        <a href="{{ $urlRetour }}"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
            ← Retour à la campagne
        </a>

        <h1 class="font-heading text-xl font-semibold text-ink mb-4 text-center">Pesée des dons</h1>

        {{-- Sélecteurs campagne / journée (prompt §3.3) --}}
        <div class="flex gap-2 mb-6">
            <select id="select-campagne" class="flex-1 text-[13px] rounded-lg border border-surface-border px-3 py-2">
                @foreach($autresCampagnes as $c)
                    <option value="{{ route('livraison.pesee.show', $c) }}" @selected($c->id === $campagne->id)>
                        {{ ucfirst($c->type) }} — {{ $c->date_livraison->format('d/m/Y') }}
                    </option>
                @endforeach
            </select>
            @if($campagne->journees->count() > 1)
                <select id="select-journee" class="flex-1 text-[13px] rounded-lg border border-surface-border px-3 py-2">
                    <option value="">Toutes les journées</option>
                    @foreach($campagne->journees as $j)
                        <option value="{{ $j->id }}">{{ $j->label ?? $j->date->format('d/m') }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        <form id="form-pesee" class="space-y-4 max-w-sm mx-auto">
            @csrf
            <div class="relative">
                <input type="number" name="poids_kg" id="poids_kg" step="0.1" min="0.1" max="2000" placeholder="0.0"
                    class="w-full text-center text-3xl font-bold rounded-lg border border-surface-border px-3 py-4" required>
                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-ink-muted text-sm">kg</span>
            </div>

            <button type="submit"
                class="w-full rounded-lg bg-accent text-white text-lg font-semibold py-4 hover:opacity-90 transition-opacity">
                + Enregistrer
            </button>
        </form>

        <p id="total" class="mt-4 text-[13px] text-ink-muted text-center"></p>

        {{-- Journal (prompt §3.2) : une ligne par relevé, éditable/supprimable --}}
        <div class="mt-10">
            <h2 class="font-heading text-[15px] font-semibold text-ink mb-3">Historique des pesées</h2>
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left text-ink-muted border-b border-surface-border">
                        <th class="py-2 font-medium">Horodatage</th>
                        <th class="py-2 font-medium">Poids (kg)</th>
                        <th class="py-2 font-medium">Saisi par</th>
                        <th class="py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody id="journal-body"></tbody>
            </table>
            <p id="journal-vide" class="text-ink-muted py-4 hidden">Aucun relevé pour l'instant.</p>
        </div>
    </div>

    <script>
        const csrf = document.querySelector('input[name="_token"]').value;
        const idCampagne = {{ $campagne->id }};
        const journalBody = document.getElementById('journal-body');
        const journalVide = document.getElementById('journal-vide');
        const selectJournee = document.getElementById('select-journee');

        document.getElementById('select-campagne').addEventListener('change', (e) => window.location.href = e.target.value);
        if (selectJournee) selectJournee.addEventListener('change', chargerJournal);

        function idJourneeSelectionnee() {
            return selectJournee && selectJournee.value ? selectJournee.value : '';
        }

        async function chargerJournal() {
            const suffixe = idJourneeSelectionnee() ? `?id_campagne_journee=${idJourneeSelectionnee()}` : '';
            const reponse = await fetch(`/livraison/pesee/${idCampagne}/journal${suffixe}`, { headers: { 'Accept': 'application/json' } });
            const donnees = await reponse.json();
            afficherJournal(donnees.dons);
        }

        function afficherJournal(dons) {
            journalBody.innerHTML = '';
            journalVide.classList.toggle('hidden', dons.length > 0);
            for (const don of dons) {
                const tr = document.createElement('tr');
                tr.className = 'border-b border-surface-border';
                tr.id = `don-${don.id}`;
                const nomSaisi = (don.logge_par && typeof don.logge_par === 'object')
                    ? `${don.logge_par.prenom} ${don.logge_par.nom}`
                    : `#${don.logge_par}`;
                tr.innerHTML = `
                    <td class="py-2">${new Date(don.horodatage).toLocaleString('fr-FR')}</td>
                    <td class="py-2"><span class="valeur">${don.poids_kg}</span></td>
                    <td class="py-2 text-ink-muted">${nomSaisi}</td>
                    <td class="py-2 text-right whitespace-nowrap">
                        <button class="text-[12px] text-accent mr-2" onclick="modifierLigne(${don.id})">Modifier</button>
                        <button class="text-[12px] text-rose-600" onclick="supprimerLigne(${don.id})">Supprimer</button>
                    </td>`;
                journalBody.appendChild(tr);
            }
        }

        async function modifierLigne(id) {
            const ligne = document.getElementById(`don-${id}`);
            const valeurActuelle = ligne.querySelector('.valeur').textContent;
            const nouvelleValeur = prompt('Nouveau poids (kg) :', valeurActuelle);
            if (nouvelleValeur === null || nouvelleValeur === '') return;

            const reponse = await fetch(`/livraison/pesee/dons/${id}`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ poids_kg: parseFloat(nouvelleValeur) }),
            });
            const resultat = await reponse.json();
            if (resultat.success) chargerJournal();
        }

        async function supprimerLigne(id) {
            if (!confirm('Supprimer ce relevé ?')) return;
            const reponse = await fetch(`/livraison/pesee/dons/${id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            });
            const resultat = await reponse.json();
            if (resultat.success) chargerJournal();
        }

        document.getElementById('form-pesee').addEventListener('submit', async function (e) {
            e.preventDefault();
            const poids = document.getElementById('poids_kg');
            const reponse = await fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ poids_kg: parseFloat(poids.value), id_campagne_journee: idJourneeSelectionnee() || null }),
            });
            const resultat = await reponse.json();

            if (resultat.success) {
                document.getElementById('total').textContent = `Total campagne : ${resultat.total_campagne} kg`;
                poids.value = '';
                chargerJournal();
            } else {
                document.getElementById('total').textContent = "Erreur d'enregistrement.";
            }
            poids.focus();
        });

        chargerJournal();
    </script>
@endsection
