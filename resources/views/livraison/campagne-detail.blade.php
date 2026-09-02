{{-- resources/views/livraison/campagne-detail.blade.php --}}
@extends('layouts.app')

@section('title', 'Campagne — AMANA Familles')

@section('content')
    @php
        $typeLabels = ['zakat_el_fitr' => 'Zakat el-fitr', 'collecte_alimentaire' => 'Collecte alimentaire', 'don_ponctuel' => 'Don ponctuel'];
    @endphp
    <div class="max-w-4xl mx-auto py-8" id="app" data-campagne-id="{{ $campagne->id }}">
        <a href="{{ route('livraison.campagnes.index') }}" class="text-[12px] text-ink-muted mb-2 inline-block">&larr; Campagnes</a>
        <h1 class="font-heading text-xl font-semibold text-ink mb-1">
            {{ $typeLabels[$campagne->type] ?? $campagne->type }} — {{ $campagne->date_livraison->format('d/m/Y') }}
        </h1>
        <p class="text-[13px] text-ink-muted mb-6">Statut : {{ $campagne->statut }}</p>
        <form id="csrf-holder">@csrf</form>

        <div class="flex gap-3 mb-8">
            <button type="button" onclick="notifierBenevoles()" class="text-[13px] px-4 py-2 rounded-lg border border-surface-border text-ink-muted">
                📧 Notifier les bénévoles
            </button>
            <button type="button" onclick="genererRoutes()" class="text-[13px] px-4 py-2 rounded-lg bg-accent text-white">
                🚚 Lancer le clustering / génération des routes
            </button>
        </div>
        <p id="message-routes" class="text-[13px] mb-6"></p>

        <div class="bg-surface border border-surface-border rounded-xl p-5 mb-8">
            <h2 class="text-[14px] font-medium text-ink mb-4">Sélection des familles éligibles</h2>

            <div class="grid grid-cols-3 gap-3 mb-4">
                <input type="number" id="filtre-criticite" placeholder="Criticité min" class="rounded-lg border border-surface-border px-3 py-2 text-[13px]">
                <input type="number" id="filtre-quartier" placeholder="ID quartier" class="rounded-lg border border-surface-border px-3 py-2 text-[13px]">
                <input type="number" id="filtre-organisation" placeholder="ID organisation" class="rounded-lg border border-surface-border px-3 py-2 text-[13px]">
            </div>
            <button type="button" onclick="chargerEligibles()" class="text-[13px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted mb-4">Filtrer</button>

            <div id="liste-eligibles" class="space-y-1 max-h-96 overflow-y-auto mb-4"></div>

            <button type="button" onclick="genererLivraisons()" class="text-[13px] px-4 py-2 rounded-lg bg-accent text-white">
                Générer les livraisons pour la sélection
            </button>
            <p id="message-generation" class="text-[13px] mt-3"></p>
        </div>

        <div class="bg-surface border border-surface-border rounded-xl p-5">
            <h2 class="text-[14px] font-medium text-ink mb-3">Livraisons confirmées jamais couvertes</h2>
            <div id="liste-non-couvertes" class="text-[13px] text-ink-muted"></div>
        </div>
    </div>

    <script>
        const campagneId = document.getElementById('app').dataset.campagneId;
        const csrf = document.querySelector('#csrf-holder input[name="_token"]').value;

        async function poster(url, body = {}) {
            const reponse = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body),
            });
            return reponse.json();
        }

        async function chargerEligibles() {
            const params = new URLSearchParams();
            const criticite = document.getElementById('filtre-criticite').value;
            const quartier = document.getElementById('filtre-quartier').value;
            const organisation = document.getElementById('filtre-organisation').value;
            if (criticite) params.set('criticite_min', criticite);
            if (quartier) params.set('id_quartier', quartier);
            if (organisation) params.set('id_organisation', organisation);

            const reponse = await fetch(`/livraison/campagnes/${campagneId}/eligibles?${params}`, {
                headers: { 'Accept': 'application/json' },
            });
            const resultat = await reponse.json();

            const liste = document.getElementById('liste-eligibles');
            liste.innerHTML = '';

            (resultat.data ?? []).forEach(famille => {
                const label = document.createElement('label');
                label.className = 'flex items-center gap-2 text-[13px] text-ink py-1 border-b border-surface-border';
                label.innerHTML = `
                    <input type="checkbox" class="check-famille" value="${famille.id}">
                    ${famille.prenom} ${famille.nom} — criticité ${famille.criticite ?? '—'}
                    ${famille.derniere_livraison_le ? '· dernière livraison ' + famille.derniere_livraison_le : '· jamais livrée'}
                `;
                liste.appendChild(label);
            });

            if ((resultat.data ?? []).length === 0) {
                liste.innerHTML = '<p class="text-[13px] text-ink-muted">Aucune famille éligible pour ces filtres.</p>';
            }
        }

        async function genererLivraisons() {
            const ids = [...document.querySelectorAll('.check-famille:checked')].map(el => parseInt(el.value, 10));
            const message = document.getElementById('message-generation');

            if (ids.length === 0) {
                message.textContent = 'Sélectionnez au moins une famille.';
                return;
            }

            const resultat = await poster(`/livraison/campagnes/${campagneId}/generer-livraisons`, { ids_familles: ids });

            if (resultat.success) {
                let texte = `${resultat.generees} livraison(s) générée(s), ${resultat.deja_existantes} déjà existante(s).`;
                if (resultat.conflits.length > 0) {
                    texte += ` ⚠ ${resultat.conflits.length} famille(s) en conflit étudiant/hôtel, à corriger avant génération : `
                        + resultat.conflits.map(c => c.nom).join(', ');
                }
                message.textContent = texte;
                chargerNonCouvertes();
            } else {
                message.textContent = 'Erreur : ' + JSON.stringify(resultat.errors ?? resultat.message);
            }
        }

        async function notifierBenevoles() {
            const resultat = await poster(`/livraison/campagnes/${campagneId}/notifier-benevoles`);
            document.getElementById('message-routes').textContent = resultat.success
                ? `Email envoyé à ${resultat.envoyes} bénévole(s) (${resultat.echecs} échec(s)).`
                : 'Erreur lors de la notification.';
        }

        async function genererRoutes() {
            document.getElementById('message-routes').textContent = 'Génération en cours...';
            const resultat = await poster(`/livraison/campagnes/${campagneId}/generer-routes`);

            document.getElementById('message-routes').textContent = resultat.success
                ? `${resultat.routes_creees} tournée(s) créée(s) (dont ${resultat.imposees} imposée(s)).`
                : 'Erreur : ' + resultat.message;

            chargerNonCouvertes();
        }

        async function chargerNonCouvertes() {
            const reponse = await fetch(`/livraison/campagnes/${campagneId}/non-couvertes`, { headers: { 'Accept': 'application/json' } });
            const livraisons = await reponse.json();
            const div = document.getElementById('liste-non-couvertes');

            div.innerHTML = livraisons.length === 0
                ? 'Aucune.'
                : livraisons.map(l => `${l.famille.prenom} ${l.famille.nom} — ${l.famille.adresse}`).join('<br>');
        }

        chargerEligibles();
        chargerNonCouvertes();
    </script>
@endsection
