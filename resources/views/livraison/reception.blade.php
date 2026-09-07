{{-- resources/views/livraison/reception.blade.php --}}
@extends('layouts.app')

@section('title', 'Réception des donateurs — AMANA Familles')

@section('content')
    <div class="max-w-lg mx-auto py-8">
        <a href="{{ $urlRetour }}"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
            ← Retour à la campagne
        </a>

        <h1 class="font-heading text-xl font-semibold text-ink mb-4 text-center">Réception des donateurs</h1>

        <div class="flex gap-2 mb-6">
            <select id="select-campagne" class="flex-1 text-[13px] rounded-lg border border-surface-border px-3 py-2">
                @foreach($autresCampagnes as $c)
                    <option value="{{ route('livraison.reception.show', $c) }}" @selected($c->id === $campagne->id)>
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

        <form id="form-reception" class="space-y-4 max-w-sm mx-auto">
            @csrf
            <div class="relative">
                <input type="number" name="nombre_donateur" id="nombre_donateur" step="1" min="1" max="5000" placeholder="0"
                    class="w-full text-center text-3xl font-bold rounded-lg border border-surface-border px-3 py-4" required>
                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-ink-muted text-sm">donateurs</span>
            </div>

            <button type="submit"
                class="w-full rounded-lg bg-accent text-white text-lg font-semibold py-4 hover:opacity-90 transition-opacity">
                + Enregistrer
            </button>
        </form>

        <p id="total" class="mt-4 text-[13px] text-ink-muted text-center"></p>

        <div class="mt-10">
            <h2 class="font-heading text-[15px] font-semibold text-ink mb-3">Historique des arrivées</h2>
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left text-ink-muted border-b border-surface-border">
                        <th class="py-2 font-medium">Horodatage</th>
                        <th class="py-2 font-medium">Donateurs</th>
                        <th class="py-2 font-medium">Saisi par</th>
                        <th class="py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody id="journal-body"></tbody>
            </table>
            <p id="journal-vide" class="text-ink-muted py-4 hidden">Aucune arrivée pour l'instant.</p>
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
            const reponse = await fetch(`/livraison/reception/${idCampagne}/journal${suffixe}`, { headers: { 'Accept': 'application/json' } });
            const donnees = await reponse.json();
            afficherJournal(donnees.arrivees);
        }

        function afficherJournal(arrivees) {
            journalBody.innerHTML = '';
            journalVide.classList.toggle('hidden', arrivees.length > 0);
            for (const arrivee of arrivees) {
                const tr = document.createElement('tr');
                tr.className = 'border-b border-surface-border';
                tr.id = `arrivee-${arrivee.id}`;
                const nomSaisi = (arrivee.logge_par && typeof arrivee.logge_par === 'object')
                    ? `${arrivee.logge_par.prenom} ${arrivee.logge_par.nom}`
                    : `#${arrivee.logge_par}`;
                tr.innerHTML = `
                    <td class="py-2">${new Date(arrivee.horodatage).toLocaleString('fr-FR')}</td>
                    <td class="py-2"><span class="valeur">${arrivee.nombre_donateur}</span></td>
                    <td class="py-2 text-ink-muted">${nomSaisi}</td>
                    <td class="py-2 text-right whitespace-nowrap">
                        <button class="text-[12px] text-accent mr-2" onclick="modifierLigne(${arrivee.id})">Modifier</button>
                        <button class="text-[12px] text-rose-600" onclick="supprimerLigne(${arrivee.id})">Supprimer</button>
                    </td>`;
                journalBody.appendChild(tr);
            }
        }

        async function modifierLigne(id) {
            const ligne = document.getElementById(`arrivee-${id}`);
            const valeurActuelle = ligne.querySelector('.valeur').textContent;
            const nouvelleValeur = prompt('Nouveau nombre de donateurs :', valeurActuelle);
            if (nouvelleValeur === null || nouvelleValeur === '') return;

            const reponse = await fetch(`/livraison/reception/arrivees/${id}`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ nombre_donateur: parseInt(nouvelleValeur, 10) }),
            });
            const resultat = await reponse.json();
            if (resultat.success) chargerJournal();
        }

        async function supprimerLigne(id) {
            if (!confirm('Supprimer cette arrivée ?')) return;
            const reponse = await fetch(`/livraison/reception/arrivees/${id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            });
            const resultat = await reponse.json();
            if (resultat.success) chargerJournal();
        }

        document.getElementById('form-reception').addEventListener('submit', async function (e) {
            e.preventDefault();
            const nombre = document.getElementById('nombre_donateur');
            const reponse = await fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ nombre_donateur: parseInt(nombre.value, 10), id_campagne_journee: idJourneeSelectionnee() || null }),
            });
            const resultat = await reponse.json();

            if (resultat.success) {
                document.getElementById('total').textContent = `Total campagne : ${resultat.total_campagne} donateurs`;
                nombre.value = '';
                chargerJournal();
            } else {
                document.getElementById('total').textContent = "Erreur d'enregistrement.";
            }
            nombre.focus();
        });

        chargerJournal();
    </script>
@endsection
