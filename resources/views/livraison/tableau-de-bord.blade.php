{{-- resources/views/livraison/tableau-de-bord.blade.php --}}
{{--
    Écran le plus dense du domaine livraison — routes, incidents,
    mutabilité. Reste volontairement en JS simple (pas Vue) pour cohérence
    avec le reste des écrans livraison déjà livrés, malgré la densité —
    voir les Patches 2-4 pour le même choix. Réutilise l'endpoint
    non-couvertes() pour peupler les sélecteurs de livraisons disponibles
    (add/construction personnalisée) : simplification acceptée le
    01/09/2026, plutôt que d'ajouter un endpoint dédié "toutes les
    livraisons non assignées" — en pratique la quasi-totalité des
    livraisons non assignées à ce stade sont déjà confirmées.
--}}
@extends('layouts.app')

@section('title', 'Tableau de bord livraison — AMANA Familles')

@section('content')
    <div class="max-w-5xl mx-auto py-8">
        <h1 class="font-heading text-xl font-semibold text-ink mb-6">Tableau de bord livraison</h1>
        <form id="csrf-holder">@csrf</form>

        <select id="filtre-campagne" class="rounded-lg border border-surface-border px-3 py-2 text-[13px] mb-6">
            <option value="">— Choisir une campagne —</option>
            @foreach($campagnes as $campagne)
                <option value="{{ $campagne->id }}">{{ $campagne->date_livraison->format('d/m/Y') }} — {{ $campagne->type }}</option>
            @endforeach
        </select>

        <div id="contenu" class="hidden">
            <div class="mb-8">
                <h2 class="text-[14px] font-medium text-rose-600 mb-3">⚠ Incidents ouverts</h2>
                <div id="liste-incidents" class="space-y-2"></div>
            </div>

            <div class="mb-8 bg-surface border border-surface-border rounded-xl p-5">
                <h2 class="text-[14px] font-medium text-ink mb-3">Construire une tournée personnalisée</h2>
                <div class="grid grid-cols-3 gap-2 mb-2">
                    <input type="number" id="custom-benevole" placeholder="ID bénévole" class="rounded-lg border border-surface-border px-2 py-1 text-[13px]">
                    <input type="number" id="custom-vehicule" placeholder="ID type véhicule" class="rounded-lg border border-surface-border px-2 py-1 text-[13px]">
                    <input type="text" id="custom-livraisons" placeholder="IDs livraisons (ex: 3,7,12)" class="rounded-lg border border-surface-border px-2 py-1 text-[13px]">
                </div>
                <button onclick="construireTourneePersonnalisee()" class="text-[13px] px-3 py-1.5 rounded-lg bg-accent text-white">Créer la tournée</button>
                <p class="text-[11px] text-ink-muted mt-2">Voir la liste des livraisons non couvertes ci-dessous pour les IDs disponibles.</p>
            </div>

            <h2 class="text-[14px] font-medium text-ink mb-3">Tournées</h2>
            <div id="liste-routes" class="space-y-3 mb-8"></div>

            <div class="bg-surface border border-surface-border rounded-xl p-5">
                <h2 class="text-[14px] font-medium text-ink mb-3">Livraisons confirmées jamais couvertes</h2>
                <div id="liste-non-couvertes" class="text-[13px] text-ink-muted"></div>
            </div>
        </div>
    </div>

    <script>
        const csrfToken = document.querySelector('#csrf-holder input[name="_token"]').value;
        let campagneCourante = null;

        async function posterDonnees(url, body = {}, methode = 'POST') {
            const reponse = await fetch(url, {
                method: methode,
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body),
            });
            return reponse.json();
        }

        document.getElementById('filtre-campagne').addEventListener('change', function () {
            campagneCourante = this.value;
            if (campagneCourante) {
                document.getElementById('contenu').classList.remove('hidden');
                chargerTout();
            } else {
                document.getElementById('contenu').classList.add('hidden');
            }
        });

        function chargerTout() {
            chargerIncidents();
            chargerRoutes();
            chargerNonCouvertes();
        }

        async function chargerIncidents() {
            const reponse = await fetch(`/livraison/campagnes/${campagneCourante}/incidents`, { headers: { 'Accept': 'application/json' } });
            const incidents = await reponse.json();
            const div = document.getElementById('liste-incidents');

            div.innerHTML = incidents.length === 0 ? '<p class="text-[13px] text-ink-muted">Aucun incident ouvert.</p>' : '';

            incidents.forEach(i => {
                const bloc = document.createElement('div');
                bloc.className = 'bg-rose-50 border border-rose-200 rounded-xl p-3 flex items-center justify-between';
                bloc.innerHTML = `
                    <span class="text-[13px] text-rose-700">
                        ${i.type} — tournée #${i.id_route} (${i.route?.benevole?.prenom ?? ''} ${i.route?.benevole?.nom ?? ''})
                        ${i.notes ? '· ' + i.notes : ''}
                    </span>
                    <button class="text-[12px] px-3 py-1 rounded-lg bg-white border border-rose-300 text-rose-700">Résoudre</button>
                `;
                bloc.querySelector('button').addEventListener('click', async () => {
                    const r = await posterDonnees(`/livraison/incidents/${i.id}/resoudre`);
                    alert(r.success ? 'Résolu.' + (r.routes_creees !== undefined ? ` ${r.routes_creees} nouvelle(s) tournée(s), ${r.non_couvertes} non couverte(s).` : '') : (r.message ?? 'Erreur.'));
                    chargerTout();
                });
                div.appendChild(bloc);
            });
        }

        async function chargerRoutes() {
            const reponse = await fetch(`/livraison/campagnes/${campagneCourante}/routes`, { headers: { 'Accept': 'application/json' } });
            const routes = await reponse.json();
            const div = document.getElementById('liste-routes');
            div.innerHTML = routes.length === 0 ? '<p class="text-[13px] text-ink-muted">Aucune tournée générée.</p>' : '';

            routes.forEach(route => {
                const bloc = document.createElement('div');
                bloc.className = 'bg-surface border border-surface-border rounded-xl p-4';
                bloc.innerHTML = `
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-[14px] font-medium text-ink">
                            #${route.id} — ${route.benevole?.prenom ?? ''} ${route.benevole?.nom ?? ''}
                            (${route.creneau ?? 'imposée'}, ${route.statut})
                        </span>
                        <div class="flex gap-1">
                            <button class="btn-diviser text-[11px] px-2 py-1 rounded-lg border border-surface-border text-ink-muted">Scinder</button>
                        </div>
                    </div>
                    <ul class="text-[12px] text-ink-muted space-y-1 mb-2">
                        ${route.etapes.map(e => `
                            <li class="flex items-center justify-between">
                                <span>${e.ordre}. ${e.livraison?.famille?.prenom ?? ''} ${e.livraison?.famille?.nom ?? ''} (${e.statut})</span>
                                <button class="btn-retirer text-[11px] text-rose-600" data-etape="${e.id}">retirer</button>
                            </li>
                        `).join('')}
                    </ul>
                    <div class="flex gap-2 items-center">
                        <input type="number" class="input-ajouter rounded-lg border border-surface-border px-2 py-1 text-[12px] w-32" placeholder="ID livraison à ajouter">
                        <button class="btn-ajouter text-[12px] px-2 py-1 rounded-lg border border-surface-border text-ink-muted">Ajouter</button>
                        <input type="number" class="input-reassign-benevole rounded-lg border border-surface-border px-2 py-1 text-[12px] w-28" placeholder="Nvl bénévole">
                        <input type="number" class="input-reassign-vehicule rounded-lg border border-surface-border px-2 py-1 text-[12px] w-28" placeholder="Nvl véhicule">
                        <button class="btn-reassigner text-[12px] px-2 py-1 rounded-lg border border-surface-border text-ink-muted">Réassigner</button>
                    </div>
                `;

                bloc.querySelector('.btn-diviser').addEventListener('click', async () => {
                    const r = await posterDonnees(`/livraison/routes/${route.id}/diviser`);
                    alert(r.success ? 'Tournée scindée.' : r.message);
                    chargerRoutes();
                });

                bloc.querySelectorAll('.btn-retirer').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        const r = await posterDonnees(`/livraison/routes/${route.id}/etapes/${btn.dataset.etape}`, {}, 'DELETE');
                        alert(r.success ? 'Retirée.' : r.message);
                        chargerRoutes();
                    });
                });

                bloc.querySelector('.btn-ajouter').addEventListener('click', async () => {
                    const idLivraison = parseInt(bloc.querySelector('.input-ajouter').value, 10);
                    const r = await posterDonnees(`/livraison/routes/${route.id}/ajouter-livraison`, { id_livraison: idLivraison });
                    alert(r.success ? 'Ajoutée.' : (r.message ?? JSON.stringify(r.errors)));
                    chargerRoutes();
                });

                bloc.querySelector('.btn-reassigner').addEventListener('click', async () => {
                    const idBenevole = parseInt(bloc.querySelector('.input-reassign-benevole').value, 10);
                    const idVehicule = parseInt(bloc.querySelector('.input-reassign-vehicule').value, 10);
                    const r = await posterDonnees(`/livraison/routes/${route.id}/reassigner`, { id_benevole: idBenevole, id_vehicule_type: idVehicule });
                    alert(r.success ? 'Réassignée.' : JSON.stringify(r.errors));
                    chargerRoutes();
                });

                div.appendChild(bloc);
            });
        }

        async function chargerNonCouvertes() {
            const reponse = await fetch(`/livraison/campagnes/${campagneCourante}/non-couvertes`, { headers: { 'Accept': 'application/json' } });
            const livraisons = await reponse.json();
            const div = document.getElementById('liste-non-couvertes');

            div.innerHTML = livraisons.length === 0
                ? 'Aucune.'
                : livraisons.map(l => `#${l.id} — ${l.famille.prenom} ${l.famille.nom} (${l.famille.adresse})`).join('<br>');
        }

        async function construireTourneePersonnalisee() {
            const idBenevole = parseInt(document.getElementById('custom-benevole').value, 10);
            const idVehicule = parseInt(document.getElementById('custom-vehicule').value, 10);
            const idsLivraisons = document.getElementById('custom-livraisons').value
                .split(',').map(s => parseInt(s.trim(), 10)).filter(n => !isNaN(n));

            const r = await posterDonnees(`/livraison/campagnes/${campagneCourante}/routes-personnalisees`, {
                id_benevole: idBenevole,
                id_vehicule_type: idVehicule,
                ids_livraisons: idsLivraisons,
            });

            alert(r.success ? 'Tournée créée.' : (r.message ?? JSON.stringify(r.errors)));
            chargerTout();
        }
    </script>
@endsection
