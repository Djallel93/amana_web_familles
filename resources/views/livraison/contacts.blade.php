{{-- resources/views/livraison/contacts.blade.php --}}
@extends('layouts.app')

@section('title', 'Suivi des contacts — AMANA Familles')

@section('content')
    <div class="max-w-3xl mx-auto py-8">
        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Suivi des contacts</h1>
        <form id="csrf-holder">@csrf</form>

        <div class="flex items-center gap-3 mb-4">
            <select id="filtre-campagne" class="rounded-lg border border-surface-border px-3 py-2 text-[13px]">
                <option value="">Toutes les campagnes</option>
                @foreach($campagnes as $campagne)
                    <option value="{{ $campagne->id }}">{{ $campagne->date_livraison->format('d/m/Y') }} — {{ $campagne->type }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 text-[13px] text-ink-muted">
                <input type="checkbox" id="filtre-mine"> Assignées à moi
            </label>
            <button type="button" onclick="chargerFile()" class="text-[13px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted">Filtrer</button>
        </div>

        <div id="file-contacts" class="space-y-3"></div>
    </div>

    <script>
        const csrfToken = document.querySelector('#csrf-holder input[name="_token"]').value;

        async function posterDonnees(url, body = {}) {
            const reponse = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body),
            });
            return reponse.json();
        }

        async function chargerFile() {
            const params = new URLSearchParams();
            const campagne = document.getElementById('filtre-campagne').value;
            const mine = document.getElementById('filtre-mine').checked;
            if (campagne) params.set('id_campagne', campagne);
            if (mine) params.set('mine', '1');

            const reponse = await fetch(`/livraison/contacts/file?${params}`, { headers: { 'Accept': 'application/json' } });
            const resultat = await reponse.json();
            const conteneur = document.getElementById('file-contacts');
            conteneur.innerHTML = '';

            (resultat.data ?? []).forEach(l => {
                const bloc = document.createElement('div');
                bloc.className = 'bg-surface border border-surface-border rounded-xl p-4';
                bloc.id = `livraison-${l.id}`;
                bloc.innerHTML = `
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-[14px] font-medium text-ink">${l.famille.prenom} ${l.famille.nom}</span>
                        <span class="text-[12px] text-ink-muted">${l.statut_contact}</span>
                    </div>
                    <p class="text-[12px] text-ink-muted mb-2">${l.famille.telephone ?? ''} · ${l.famille.email ?? "pas d'email"}</p>
                    <div class="flex gap-2 mb-2">
                        <input type="number" placeholder="ID gestionnaire" class="assignee-input rounded-lg border border-surface-border px-2 py-1 text-[12px] w-32">
                        <button onclick="assigner(${l.id})" class="text-[12px] px-3 py-1 rounded-lg border border-surface-border text-ink-muted">Assigner</button>
                    </div>
                    <details>
                        <summary class="text-[12px] text-accent cursor-pointer">Saisie téléphonique</summary>
                        <div class="mt-2 space-y-2">
                            <input type="text" class="champ-adresse w-full rounded-lg border border-surface-border px-2 py-1 text-[12px]" placeholder="Adresse">
                            <div class="grid grid-cols-2 gap-2">
                                <input type="text" class="champ-cp rounded-lg border border-surface-border px-2 py-1 text-[12px]" placeholder="Code postal">
                                <input type="text" class="champ-ville rounded-lg border border-surface-border px-2 py-1 text-[12px]" placeholder="Ville">
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <input type="number" class="champ-adultes rounded-lg border border-surface-border px-2 py-1 text-[12px]" placeholder="Adultes">
                                <input type="number" class="champ-enfants rounded-lg border border-surface-border px-2 py-1 text-[12px]" placeholder="Enfants">
                            </div>
                            <select class="champ-statut w-full rounded-lg border border-surface-border px-2 py-1 text-[12px]">
                                <option value="contacte">Contacté</option>
                                <option value="injoignable">Injoignable</option>
                                <option value="confirme">Confirmé</option>
                            </select>
                            <div class="grid grid-cols-3 gap-1 champ-creneaux">
                                ${['08-10', '10-12', '12-14', '14-16', '16-18', '18-19'].map(c => `
                                    <label class="flex items-center gap-1 text-[11px] text-ink-muted">
                                        <input type="checkbox" class="creneau-check" value="${c}"> ${c}
                                    </label>
                                `).join('')}
                            </div>
                            <button onclick="contacterManuel(${l.id})" class="text-[12px] px-3 py-1 rounded-lg bg-accent text-white">Enregistrer</button>
                        </div>
                    </details>
                `;
                conteneur.appendChild(bloc);
            });

            if ((resultat.data ?? []).length === 0) {
                conteneur.innerHTML = '<p class="text-[14px] text-ink-muted">Aucune livraison en attente de contact.</p>';
            }
        }

        async function assigner(id) {
            const bloc = document.getElementById(`livraison-${id}`);
            const idPersonne = parseInt(bloc.querySelector('.assignee-input').value, 10);
            const resultat = await posterDonnees(`/livraison/contacts/${id}/assigner`, { id_personne_assignee: idPersonne });
            alert(resultat.success ? 'Assigné.' : (resultat.message ?? 'Erreur.'));
        }

        async function contacterManuel(id) {
            const bloc = document.getElementById(`livraison-${id}`);
            const statut = bloc.querySelector('.champ-statut').value;
            const donnees = { statut_contact: statut };

            if (statut === 'confirme') {
                donnees.adresse_confirmee = bloc.querySelector('.champ-adresse').value;
                donnees.code_postal_confirme = bloc.querySelector('.champ-cp').value;
                donnees.ville_confirmee = bloc.querySelector('.champ-ville').value;
                donnees.nombre_adulte_confirme = parseInt(bloc.querySelector('.champ-adultes').value, 10);
                donnees.nombre_enfant_confirme = parseInt(bloc.querySelector('.champ-enfants').value, 10);
                donnees.creneaux = [...bloc.querySelectorAll('.creneau-check:checked')].map(el => el.value);
            }

            const resultat = await posterDonnees(`/livraison/contacts/${id}/contacter-manuel`, donnees);
            if (resultat.success) {
                chargerFile();
            } else {
                alert('Erreur : ' + JSON.stringify(resultat.errors));
            }
        }

        chargerFile();
    </script>
@endsection
