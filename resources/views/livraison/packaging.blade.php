{{-- resources/views/livraison/packaging.blade.php --}}
@extends('layouts.app')

@section('title', 'Packaging — AMANA Familles')

@section('content')
    <div class="max-w-3xl mx-auto py-8">
        <a href="{{ $urlRetour }}"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90">
            ← Retour à la campagne
        </a>

        <div class="flex items-center justify-between mb-4">
            <h1 class="font-heading text-xl font-semibold text-ink">Packaging — file de priorité</h1>
            <a href="{{ route('livraison.packaging.feuille-preparation', $campagne) }}" target="_blank"
                class="text-[13px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted">
                🖨️ Feuille de préparation
            </a>
        </div>

        {{-- Sélecteurs campagne / journée (prompt §5.4) --}}
        <div class="flex gap-2 mb-4">
            <select id="select-campagne" class="flex-1 text-[13px] rounded-lg border border-surface-border px-3 py-2">
                @foreach($autresCampagnes as $c)
                    <option value="{{ route('livraison.packaging.index', $c) }}" @selected($c->id === $campagne->id)>
                        {{ ucfirst($c->type) }} — {{ $c->date_livraison->format('d/m/Y') }}
                    </option>
                @endforeach
            </select>
            @if($campagne->journees->count() > 1)
                <select id="select-journee" class="flex-1 text-[13px] rounded-lg border border-surface-border px-3 py-2"
                    onchange="location.href = this.value ? `{{ route('livraison.packaging.index', $campagne) }}?id_campagne_journee=${this.value}` : `{{ route('livraison.packaging.index', $campagne) }}`">
                    <option value="">Toutes les journées</option>
                    @foreach($campagne->journees as $j)
                        <option value="{{ $j->id }}" @selected($idCampagneJourneeSelectionnee === $j->id)>{{ $j->label ?? $j->date->format('d/m') }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        {{--
            Cartes statistiques + filtre restantes/en cours/terminées
            (08/09/2026, prompt de cette date §6.2/§6.3 ; "En cours"
            ajouté le 09/09/2026, prompt de cette date §4) — les
            comptages viennent de PackagingController::index() (portée
            campagne/journée SEULE, pas affectés par ce filtre, voir son
            docblock).
        --}}
        <div class="grid grid-cols-3 gap-3 mb-4">
            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3">
                <p class="text-[11px] text-emerald-700 uppercase tracking-wide">Terminées</p>
                <p class="text-[20px] font-semibold text-emerald-700">{{ $stats['terminees'] }}</p>
            </div>
            <div class="bg-amber-50 border border-amber-100 rounded-xl p-3">
                <p class="text-[11px] text-amber-700 uppercase tracking-wide">En cours</p>
                <p class="text-[20px] font-semibold text-amber-700">{{ $stats['en_cours'] }}</p>
            </div>
            <div class="bg-stone-50 border border-surface-border rounded-xl p-3">
                <p class="text-[11px] text-ink-muted uppercase tracking-wide">Restantes</p>
                <p class="text-[20px] font-semibold text-ink">{{ $stats['restantes'] }}</p>
            </div>
        </div>
        <div class="flex gap-2 mb-6" id="filtre-conditionnement">
            @foreach(['toutes' => 'Toutes', 'restantes' => 'Restantes', 'en_cours' => 'En cours', 'terminees' => 'Terminées'] as $valeur => $libelle)
                <button type="button" data-valeur="{{ $valeur }}"
                    onclick="appliquerFiltreConditionnement('{{ $valeur }}')"
                    class="text-[12.5px] px-3 py-1.5 rounded-lg border {{ $filtreConditionnement === $valeur ? 'bg-accent text-white border-accent' : 'border-surface-border text-ink-muted' }}">
                    {{ $libelle }}
                </button>
            @endforeach
        </div>

        {{--
            Poids moyen par type de livraison (prompt du 05/09/2026 §5.2) —
            mise à jour + recalcul manuel scopé aux livraisons pas encore
            conditionnées (voir CampagnesController::mettreAJourPoidsMoyen()/
            recalculerPoids()). Repliable : ce n'est pas un réglage qu'on
            touche à chaque visite de l'écran.
        --}}
        <details class="mb-6 bg-surface border border-surface-border rounded-xl">
            <summary class="cursor-pointer px-4 py-3 text-[13px] font-medium text-ink">⚖️ Poids moyen par type de livraison</summary>
            <div class="px-4 pb-4">
                <form id="form-poids-moyen" class="grid grid-cols-3 gap-3 mb-3">
                    @csrf
                    <label class="text-[12px] text-ink-muted">Normal (kg)
                        <input type="number" step="0.1" min="0" name="poids_moyen_kg" value="{{ $campagne->poids_moyen_kg }}"
                            class="mt-1 w-full text-[13px] rounded-lg border border-surface-border px-2 py-1.5">
                    </label>
                    <label class="text-[12px] text-ink-muted">Hôtel (kg)
                        <input type="number" step="0.1" min="0" name="poids_moyen_hotel_kg" value="{{ $campagne->poids_moyen_hotel_kg }}"
                            class="mt-1 w-full text-[13px] rounded-lg border border-surface-border px-2 py-1.5">
                    </label>
                    <label class="text-[12px] text-ink-muted">Étudiant (kg)
                        <input type="number" step="0.1" min="0" name="poids_moyen_etudiant_kg" value="{{ $campagne->poids_moyen_etudiant_kg }}"
                            class="mt-1 w-full text-[13px] rounded-lg border border-surface-border px-2 py-1.5">
                    </label>
                </form>
                <div class="flex items-center gap-2 mb-4">
                    <button type="button" onclick="enregistrerPoidsMoyen()" class="text-[12px] px-3 py-1.5 rounded-lg bg-accent text-white">Enregistrer</button>
                    <button type="button" onclick="recalculerPoids()" class="text-[12px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted"
                        title="Recalcule le poids des livraisons pas encore conditionnées avec les valeurs ci-dessus">
                        Recalculer les livraisons non conditionnées
                    </button>
                    <span id="poids-moyen-message" class="text-[12px] text-ink-muted"></span>
                </div>

                <p class="text-[12px] font-medium text-ink-muted mb-1">Historique des modifications</p>
                <ul id="historique-poids" class="text-[12px] text-ink-muted space-y-1">
                    @forelse($campagne->poidsMoyenHistorique as $h)
                        <li>{{ $h->horodatage->format('d/m/Y H:i') }} — {{ ucfirst($h->type) }} : {{ $h->ancienne_valeur }} → {{ $h->nouvelle_valeur }} kg
                            ({{ $h->loggePar->prenom ?? '' }} {{ $h->loggePar->nom ?? '' }})</li>
                    @empty
                        <li>Aucune modification enregistrée.</li>
                    @endforelse
                </ul>
            </div>
        </details>

        <form id="csrf-holder">@csrf</form>

        <div class="space-y-3" id="liste-livraisons">
            @forelse($livraisons as $livraison)
                {{--
                    Teinte légère étudiant/hôtel en plus des badges
                    (05/09/2026, prompt §4.5) — hôtel prioritaire si une
                    famille est (en théorie jamais) les deux à la fois,
                    simple choix arbitraire plutôt que de mélanger les
                    teintes.
                --}}
                <div class="border border-surface-border rounded-xl p-4
                    {{ $livraison->famille->est_hotel ? 'bg-amber-50' : ($livraison->famille->etudiant ? 'bg-sky-50' : 'bg-surface') }}"
                    id="livraison-{{ $livraison->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            {{--
                                Case "famille entière" (prompt §5.3) : reste
                                cliquable en permanence (voir toggleFamille()
                                ci-dessous) — cocher tous les colis la coche
                                automatiquement, la décocher déclenche une
                                confirmation avant d'annuler le
                                conditionnement complet. Grisée visuellement
                                (opacité) tant que tous les colis ne sont pas
                                prêts plutôt que l'attribut HTML `disabled`
                                (05/09/2026 : plus fiable après un
                                changement d'état dynamique en JS).
                            --}}
                            <input type="checkbox" class="mt-1 case-famille" data-id-livraison="{{ $livraison->id }}"
                                {{ $livraison->statut_conditionnement === 'prete' ? 'checked' : '' }}
                                onchange="toggleFamille({{ $livraison->id }}, this.checked)">
                            <div>
                                {{--
                                    Pas de nom/téléphone ici (05/09/2026,
                                    prompt §4.4) : l'équipe packaging n'a
                                    besoin que de l'id, du nombre
                                    d'adultes/enfants et des besoins
                                    spéciaux pour préparer les colis — pas
                                    de l'identité de la famille.
                                --}}
                                <p class="text-[14px] font-medium text-ink">
                                    Famille #{{ $livraison->famille->id }}
                                    <span class="text-[12px] text-ink-muted">
                                        — {{ $livraison->famille->nombre_adulte }} adulte(s), {{ $livraison->famille->nombre_enfant }} enfant(s)
                                    </span>
                                </p>
                                <div class="flex gap-1.5 mt-1">
                                    @if($livraison->famille->etudiant)
                                        <span class="text-[11px] px-2 py-0.5 rounded-full bg-sky-100 text-sky-700">Étudiant</span>
                                    @endif
                                    @if($livraison->famille->est_hotel)
                                        <span class="text-[11px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">Hôtel</span>
                                    @endif
                                    {{--
                                        Badge de statut (08/09/2026, prompt de
                                        cette date §6.1) — mis à jour aussi en
                                        JS par synchroniserCaseFamille(), qui
                                        reste la source de vérité de l'état
                                        affiché après un toggle sans recharger
                                        la page.
                                    --}}
                                    <span class="statut-conditionnement text-[11px] px-2 py-0.5 rounded-full {{ $livraison->statut_conditionnement === 'prete' ? 'bg-emerald-100 text-emerald-700' : 'bg-stone-100 text-ink-muted' }}"
                                        data-id-livraison="{{ $livraison->id }}">
                                        {{ $livraison->statut_conditionnement === 'prete' ? 'Terminée' : 'Restante' }}
                                    </span>
                                </div>
                                @if($livraison->note_besoins_speciaux)
                                    <p class="text-[12px] text-rose-600 mt-1">⚠ {{ $livraison->note_besoins_speciaux }}</p>
                                @endif

                                {{-- Un colis = une personne du foyer (prompt §5.3) --}}
                                <div class="flex flex-wrap gap-2 mt-2">
                                    @foreach($livraison->colis as $colis)
                                        <label class="inline-flex items-center gap-1.5 text-[12px] px-2 py-1 rounded-lg border border-surface-border bg-white">
                                            <input type="checkbox" class="case-colis" data-id-colis="{{ $colis->id }}" data-id-livraison="{{ $livraison->id }}"
                                                {{ $colis->statut === 'pret' ? 'checked' : '' }}
                                                onchange="toggleColis({{ $colis->id }}, {{ $livraison->id }}, this.checked)">
                                            Colis {{ $colis->numero }}/{{ $livraison->nombre_personnes }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-[14px] text-ink-muted">Aucune livraison en attente de conditionnement.</p>
            @endforelse
        </div>
    </div>

    <script>
        const csrf = document.querySelector('#csrf-holder input[name="_token"]').value;

        document.getElementById('select-campagne').addEventListener('change', (e) => window.location.href = e.target.value);

        async function toggleColis(idColis, idLivraison, coche) {
            const checkbox = document.querySelector(`.case-colis[data-id-colis="${idColis}"]`);
            const reponse = await fetch(`/livraison/packaging/colis/${idColis}/statut`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ statut: coche ? 'pret' : 'a_preparer' }),
            });
            let resultat;
            try {
                resultat = await reponse.json();
            } catch {
                resultat = { success: false };
            }
            if (!resultat.success) {
                // Échec serveur : on annule le changement visuel plutôt que
                // de laisser la case dans un état qui ne correspond plus à
                // ce qui est réellement enregistré.
                if (checkbox) checkbox.checked = !coche;
                alert("Erreur lors de l'enregistrement de ce colis.");
                return;
            }

            synchroniserCaseFamille(idLivraison);
        }

        /**
         * Recalcule et applique l'état de la case "famille entière" à
         * partir de l'état RÉEL des cases colis dans le DOM — factorisé
         * (05/09/2026) et rappelé au chargement de la page en plus
         * qu'après chaque toggle, pour ne jamais dépendre d'un seul point
         * d'entrée. N'utilise plus l'attribut `disabled` (05/09/2026 :
         * signalé comme ne se réactivant pas de façon fiable après une
         * bascule dynamique en JS dans certains navigateurs) — la case
         * reste toujours cliquable, le grisé est purement visuel
         * (opacité) et toute tentative de cochage prématuré est annulée
         * dans toggleFamille() ci-dessous.
         */
        function synchroniserCaseFamille(idLivraison) {
            const caseFamille = document.querySelector(`.case-famille[data-id-livraison="${idLivraison}"]`);
            const tousLesColis = document.querySelectorAll(`.case-colis[data-id-livraison="${idLivraison}"]`);
            if (!caseFamille || tousLesColis.length === 0) return;

            const tousPrets = Array.from(tousLesColis).every((c) => c.checked);
            caseFamille.checked = tousPrets;
            caseFamille.classList.toggle('opacity-40', !tousPrets);
            caseFamille.title = tousPrets ? '' : 'Cochez d\'abord tous les colis de cette famille';

            // Badge de statut (08/09/2026, prompt de cette date §6.1) —
            // la ligne reste dans le DOM (voir index() : plus de filtre
            // statut_conditionnement côté serveur), seul ce badge change.
            const badge = document.querySelector(`.statut-conditionnement[data-id-livraison="${idLivraison}"]`);
            if (badge) {
                badge.textContent = tousPrets ? 'Terminée' : 'Restante';
                badge.classList.toggle('bg-emerald-100', tousPrets);
                badge.classList.toggle('text-emerald-700', tousPrets);
                badge.classList.toggle('bg-stone-100', !tousPrets);
                badge.classList.toggle('text-ink-muted', !tousPrets);
            }
        }

        // Filtre restantes/terminées (08/09/2026, prompt de cette date
        // §6.2) — conserve id_campagne_journee s'il est déjà présent dans
        // l'URL.
        function appliquerFiltreConditionnement(valeur) {
            const url = new URL(window.location.href);
            if (valeur === 'toutes') url.searchParams.delete('filtre_conditionnement');
            else url.searchParams.set('filtre_conditionnement', valeur);
            window.location.href = url.toString();
        }

        /**
         * Cocher : n'est censé arriver qu'automatiquement une fois tous
         * les colis prêts (voir synchroniserCaseFamille()) — un clic
         * manuel prématuré est annulé ici plutôt que laissé passer.
         * Décocher : demande confirmation puis appelle annuler() (prompt
         * du 05/09/2026 §5.3 : "get a confirmation screen before
         * validating uncheck").
         */
        async function toggleFamille(idLivraison, coche) {
            const caseFamille = document.querySelector(`.case-famille[data-id-livraison="${idLivraison}"]`);
            const tousLesColis = document.querySelectorAll(`.case-colis[data-id-livraison="${idLivraison}"]`);
            const tousPrets = Array.from(tousLesColis).every((c) => c.checked);

            if (coche) {
                if (!tousPrets && caseFamille) caseFamille.checked = false;
                return;
            }

            if (!confirm("Reprendre les colis de cette famille pour correction ? L'équipe chargement sera avertie si la tournée était déjà prête à charger.")) {
                if (caseFamille) caseFamille.checked = true;
                return;
            }

            const reponse = await fetch(`/livraison/packaging/${idLivraison}/annuler`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ confirme: true }),
            });
            const resultat = await reponse.json();
            if (!resultat.success) {
                alert(resultat.message ?? "Erreur lors de l'annulation.");
                if (caseFamille) caseFamille.checked = true;
                return;
            }

            document.querySelectorAll(`.case-colis[data-id-livraison="${idLivraison}"]`).forEach((c) => c.checked = false);
            synchroniserCaseFamille(idLivraison);
        }

        // Synchronise l'état visuel de chaque case famille au chargement —
        // couvre aussi le cas où le rendu Blade initial (checked/non
        // checked selon statut_conditionnement) ne correspondrait pas
        // exactement à l'état colis par colis.
        document.querySelectorAll('.case-famille').forEach((c) => synchroniserCaseFamille(c.dataset.idLivraison));

        async function enregistrerPoidsMoyen() {
            const form = document.getElementById('form-poids-moyen');
            const donnees = Object.fromEntries(new FormData(form).entries());
            delete donnees._token;

            const reponse = await fetch(`{{ route('livraison.campagnes.poids-moyen', $campagne) }}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify(donnees),
            });
            const resultat = await reponse.json();
            const message = document.getElementById('poids-moyen-message');
            if (resultat.success) {
                message.textContent = 'Enregistré.';
                if (resultat.historique) {
                    const liste = document.getElementById('historique-poids');
                    liste.innerHTML = resultat.historique.map((h) =>
                        `<li>${new Date(h.horodatage).toLocaleString('fr-FR')} — ${h.type} : ${h.ancienne_valeur} → ${h.nouvelle_valeur} kg (${h.logge_par?.prenom ?? ''} ${h.logge_par?.nom ?? ''})</li>`
                    ).join('') || '<li>Aucune modification enregistrée.</li>';
                }
            } else {
                message.textContent = "Erreur d'enregistrement.";
            }
        }

        async function recalculerPoids() {
            if (!confirm('Recalculer le poids des livraisons pas encore conditionnées avec les valeurs actuelles ?')) return;

            const reponse = await fetch(`{{ route('livraison.campagnes.recalculer-poids', $campagne) }}`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            });
            const resultat = await reponse.json();
            document.getElementById('poids-moyen-message').textContent = resultat.success
                ? `${resultat.nombre_livraisons_recalculees} livraison(s) recalculée(s).`
                : "Erreur lors du recalcul.";
        }
    </script>
@endsection
