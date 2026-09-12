{{-- resources/views/livraison/poste-releve.blade.php --}}
{{--
    Vue partagée pesée/réception, paramétrée par $definition
    (App\Support\RelevePosteDefinition) — fusion de pesee.blade.php et
    reception.blade.php le 10/09/2026 (Section A1 du refactor), voir
    App\Http\Controllers\Livraison\PosteReleveController::show() pour ce
    qui varie entre les deux postes.
--}}
@extends('layouts.app')

@section('title', $definition->titre . ' — AMANA Familles')

@section('content')
    <div class="max-w-lg mx-auto py-8">
        {{--
            Retour (prompt du 05/09/2026 §3.1) : un compte equipe_pesee/
            equipe_reception PUR n'a PAS accès à livraison.campagnes.show
            (réservé gestionnaire, voir la matrice de droits) — retour
            vers son propre point d'entrée (choisir()) plutôt qu'un lien
            qui échouerait en 403.
        --}}
        <a href="{{ $urlRetour }}"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
            ← Retour à la campagne
        </a>

        <h1 class="font-heading text-xl font-semibold text-ink mb-4 text-center">{{ $definition->titre }}</h1>

        {{-- Sélecteurs campagne / journée (prompt §3.3) --}}
        <div class="flex gap-2 mb-6">
            <select id="select-campagne" class="flex-1 text-[13px] rounded-lg border border-surface-border px-3 py-2">
                @foreach($autresCampagnes as $c)
                    <option value="{{ route("livraison.{$definition->type}.show", $c) }}" @selected($c->id === $campagne->id)>
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

        <form id="form-releve" class="space-y-4 max-w-sm mx-auto">
            @csrf
            <div class="relative">
                <input type="number" name="{{ $definition->champ }}" id="champ-releve" step="{{ $definition->pas }}"
                    min="{{ $definition->min }}" max="{{ $definition->max }}" placeholder="{{ $definition->placeholder }}"
                    class="w-full text-center text-3xl font-bold rounded-lg border border-surface-border px-3 py-4" required>
                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-ink-muted text-sm">{{ $definition->unite }}</span>
            </div>

            <button type="submit"
                class="w-full rounded-lg bg-accent text-white text-lg font-semibold py-4 hover:opacity-90 transition-opacity">
                + Enregistrer
            </button>
        </form>

        <p id="total" class="mt-4 text-[13px] text-ink-muted text-center"></p>

        {{-- Journal (prompt §3.2) : une ligne par relevé, éditable/supprimable --}}
        <div class="mt-10">
            <h2 class="font-heading text-[15px] font-semibold text-ink mb-3">{{ $definition->titreHistorique }}</h2>
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-left text-ink-muted border-b border-surface-border">
                        <th class="py-2 font-medium">Horodatage</th>
                        <th class="py-2 font-medium">{{ $definition->libelleColonne }}</th>
                        <th class="py-2 font-medium">Saisi par</th>
                        <th class="py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody id="journal-body"></tbody>
            </table>
            <p id="journal-vide" class="text-ink-muted py-4 hidden">{{ $definition->messageVide }}</p>
        </div>
    </div>

    <script>
        const csrf = document.querySelector('input[name="_token"]').value;
        const type = @json($definition->type);
        const champ = @json($definition->champ);
        const segmentRessource = @json($definition->cleListeJson);
        const suffixeTotal = @json($definition->suffixeTotal);
        const estEntier = @json($definition->estEntier);
        const promptModification = @json($definition->promptModification);
        const confirmationSuppression = @json($definition->confirmationSuppression);
        const idCampagne = {{ $campagne->id }};
        const journalBody = document.getElementById('journal-body');
        const journalVide = document.getElementById('journal-vide');
        const selectJournee = document.getElementById('select-journee');

        function analyser(valeur) {
            return estEntier ? parseInt(valeur, 10) : parseFloat(valeur);
        }

        document.getElementById('select-campagne').addEventListener('change', (e) => window.location.href = e.target.value);
        if (selectJournee) selectJournee.addEventListener('change', chargerJournal);

        function idJourneeSelectionnee() {
            return selectJournee && selectJournee.value ? selectJournee.value : '';
        }

        async function chargerJournal() {
            const suffixe = idJourneeSelectionnee() ? `?id_campagne_journee=${idJourneeSelectionnee()}` : '';
            const reponse = await fetch(`/livraison/${type}/${idCampagne}/journal${suffixe}`, { headers: { 'Accept': 'application/json' } });
            const donnees = await reponse.json();
            afficherJournal(donnees[segmentRessource]);
        }

        function afficherJournal(releves) {
            journalBody.innerHTML = '';
            journalVide.classList.toggle('hidden', releves.length > 0);
            for (const releve of releves) {
                const tr = document.createElement('tr');
                tr.className = 'border-b border-surface-border';
                tr.id = `releve-${releve.id}`;
                const nomSaisi = (releve.logge_par && typeof releve.logge_par === 'object')
                    ? `${releve.logge_par.prenom} ${releve.logge_par.nom}`
                    : `#${releve.logge_par}`;
                tr.innerHTML = `
                    <td class="py-2">${new Date(releve.horodatage).toLocaleString('fr-FR')}</td>
                    <td class="py-2"><span class="valeur">${releve[champ]}</span></td>
                    <td class="py-2 text-ink-muted">${nomSaisi}</td>
                    <td class="py-2 text-right whitespace-nowrap">
                        <button class="text-[12px] text-accent mr-2" onclick="modifierLigne(${releve.id})">Modifier</button>
                        <button class="text-[12px] text-rose-600" onclick="supprimerLigne(${releve.id})">Supprimer</button>
                    </td>`;
                journalBody.appendChild(tr);
            }
        }

        async function modifierLigne(id) {
            const ligne = document.getElementById(`releve-${id}`);
            const valeurActuelle = ligne.querySelector('.valeur').textContent;
            const nouvelleValeur = prompt(promptModification, valeurActuelle);
            if (nouvelleValeur === null || nouvelleValeur === '') return;

            const reponse = await fetch(`/livraison/${type}/${segmentRessource}/${id}`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ [champ]: analyser(nouvelleValeur) }),
            });
            const resultat = await reponse.json();
            if (resultat.success) chargerJournal();
        }

        async function supprimerLigne(id) {
            if (!confirm(confirmationSuppression)) return;
            const reponse = await fetch(`/livraison/${type}/${segmentRessource}/${id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            });
            const resultat = await reponse.json();
            if (resultat.success) chargerJournal();
        }

        document.getElementById('form-releve').addEventListener('submit', async function (e) {
            e.preventDefault();
            const champInput = document.getElementById('champ-releve');
            const reponse = await fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ [champ]: analyser(champInput.value), id_campagne_journee: idJourneeSelectionnee() || null }),
            });
            const resultat = await reponse.json();

            if (resultat.success) {
                document.getElementById('total').textContent = `Total campagne : ${resultat.total_campagne} ${suffixeTotal}`;
                champInput.value = '';
                chargerJournal();
            } else {
                document.getElementById('total').textContent = "Erreur d'enregistrement.";
            }
            champInput.focus();
        });

        chargerJournal();
    </script>
@endsection
