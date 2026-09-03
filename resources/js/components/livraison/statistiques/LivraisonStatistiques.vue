<!-- resources/js/components/livraison/statistiques/LivraisonStatistiques.vue -->
<!--
    Statistiques live d'une campagne — reconstruit en Vue le 03/09/2026,
    voir resources/views/livraison/statistiques.blade.php. Réutilise le
    pattern chart.js d'ActiviteStatistiques.vue/FamillesStatistiques.vue
    (mêmes imports Chart.register, même idle/loading/loaded/error, mêmes
    classes de carte bg-surface rounded-xl border...) plutôt qu'une
    nouvelle bibliothèque — seule différence : BarController/BarElement au
    lieu de LineController/LineElement, la grille de statuts se prêtant
    mieux à un histogramme qu'à une série temporelle (pas de dimension
    "jour" ici, une seule campagne à la fois).

    Le bouton instantané est masqué (pas seulement désactivé) pour un
    bénévole plutôt que désactivé-avec-tooltip : la route
    statistiques.snapshot est bien accessible en lecture au groupe
    role:benevole (voir routes/web.php), donc l'écran est atteignable,
    mais l'action elle-même est refusée en 403 côté StatistiquesController
    — data-peut-snapshotter reflète ce même test (isAdmin/isGestionnaire)
    pour ne jamais présenter un bouton qui échouerait systématiquement.
-->
<script setup lang="ts">
import { ref, onUnmounted, nextTick } from 'vue';
import { useToast } from '@amana/shared-ui';
import {
    Chart,
    BarController,
    BarElement,
    LinearScale,
    CategoryScale,
    Tooltip,
    Legend,
    type ChartConfiguration,
} from 'chart.js';
import { apiGet, apiPost } from '../shared/api';
import type { Campagne, StatistiquesDonnees } from '../shared/types';

Chart.register(BarController, BarElement, LinearScale, CategoryScale, Tooltip, Legend);

const toast = useToast();

const el = document.getElementById('vue-livraison-statistiques')!;
const campagnes = ref<Campagne[]>(JSON.parse(el.dataset.campagnes ?? '[]'));
const donneesUrlTemplate = el.dataset.donneesUrlTemplate ?? '';
const snapshotUrlTemplate = el.dataset.snapshotUrlTemplate ?? '';
const peutSnapshotter = el.dataset.peutSnapshotter === '1';

const LIBELLES_LIVRAISON: Record<string, string> = {
    non_assignee: 'Non assignée', assignee: 'Assignée', en_cours: 'En cours',
    livree: 'Livrée', ignoree: 'Ignorée',
};
const LIBELLES_ROUTE: Record<string, string> = {
    planifiee: 'Planifiée', chargement: 'Chargement', en_cours: 'En cours', terminee: 'Terminée',
};

function formatDateFr(iso: string): string {
    const [annee, mois, jour] = iso.split('T')[0].split('-');
    return `${jour}/${mois}/${annee}`;
}

function urlDonnees(id: string): string {
    return donneesUrlTemplate.replace('__CAMPAGNE__', id);
}
function urlSnapshot(id: string): string {
    return snapshotUrlTemplate.replace('__CAMPAGNE__', id);
}

type LoadState = 'idle' | 'loading' | 'loaded' | 'error';
const loadState = ref<LoadState>('idle');
const campagneId = ref('');
const donnees = ref<StatistiquesDonnees | null>(null);
const snapshotEnCours = ref(false);

async function charger() {
    if (!campagneId.value) {
        donnees.value = null;
        loadState.value = 'idle';
        return;
    }

    loadState.value = 'loading';
    const resultat = await apiGet<StatistiquesDonnees>(urlDonnees(campagneId.value));

    if (!resultat.ok) {
        loadState.value = 'error';
        return;
    }

    donnees.value = resultat.data;
    loadState.value = 'loaded';
    await nextTick();
    renderCharts();
}

async function snapshotter() {
    if (!campagneId.value) return;
    snapshotEnCours.value = true;
    const resultat = await apiPost<{ success: boolean }>(urlSnapshot(campagneId.value));
    snapshotEnCours.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    toast.success('Instantané enregistré.');
    // Le tableau de comparaison historique est rendu côté Blade (données
    // déjà chargées au moment du rendu serveur, voir statistiques.blade.php)
    // — un rechargement complet est la seule façon de l'actualiser sans
    // dupliquer la requête comparaisonHistorique() côté Vue pour un
    // tableau qui n'a par ailleurs aucune interactivité.
    window.location.reload();
}

// ── Graphiques ────────────────────────────────────────────────────────────
const canvasLivraisons = ref<HTMLCanvasElement | null>(null);
const canvasRoutes = ref<HTMLCanvasElement | null>(null);
let chartLivraisons: Chart | null = null;
let chartRoutes: Chart | null = null;

function graphiqueRepartition(libelles: Record<string, string>, donnees: Record<string, number>, couleur: string): ChartConfiguration<'bar'> {
    const cles = Object.keys(donnees);
    return {
        type: 'bar',
        data: {
            labels: cles.map((c) => libelles[c] ?? c),
            datasets: [{ data: cles.map((c) => donnees[c]), backgroundColor: couleur }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        },
    };
}

function renderCharts(): void {
    if (!donnees.value) return;

    chartLivraisons?.destroy();
    chartRoutes?.destroy();

    if (canvasLivraisons.value) {
        chartLivraisons = new Chart(canvasLivraisons.value, graphiqueRepartition(LIBELLES_LIVRAISON, donnees.value.livraisons_par_statut, '#b45309'));
    }
    if (canvasRoutes.value) {
        chartRoutes = new Chart(canvasRoutes.value, graphiqueRepartition(LIBELLES_ROUTE, donnees.value.routes_par_statut, '#0f766e'));
    }
}

onUnmounted(() => {
    chartLivraisons?.destroy();
    chartRoutes?.destroy();
});
</script>

<template>
    <div class="flex flex-col gap-5">
        <div class="flex flex-col sm:flex-row sm:items-center gap-3">
            <select v-model="campagneId" @change="charger"
                class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.5rem]">
                <option value="">— Choisir une campagne —</option>
                <option v-for="c in campagnes" :key="c.id" :value="String(c.id)">
                    {{ formatDateFr(c.date_livraison) }} — {{ c.type }}
                </option>
            </select>
        </div>

        <div v-if="loadState === 'loading'" class="text-center py-10 text-[13.5px] text-ink-muted">
            Chargement des statistiques…
        </div>
        <div v-else-if="loadState === 'error'" class="text-center py-8 text-rose-600 text-[13px]">
            Impossible de charger les statistiques pour cette campagne.
        </div>

        <template v-else-if="loadState === 'loaded' && donnees">
            <div class="flex items-center justify-end">
                <button v-if="peutSnapshotter" type="button" :disabled="snapshotEnCours" @click="snapshotter"
                    class="min-h-[2.25rem] text-[12px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted disabled:opacity-60">
                    📸 {{ snapshotEnCours ? 'Enregistrement…' : 'Enregistrer un instantané' }}
                </button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm px-4 py-4">
                    <div class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] mb-1">Ménages (donateurs)</div>
                    <div class="text-xl font-heading font-semibold text-ink">{{ donnees.nombre_menages }}</div>
                </div>
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm px-4 py-4">
                    <div class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] mb-1">Poids collecté</div>
                    <div class="text-xl font-heading font-semibold text-ink">{{ donnees.poids_collecte_kg }} kg</div>
                </div>
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm px-4 py-4">
                    <div class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] mb-1">Poids livré</div>
                    <div class="text-xl font-heading font-semibold text-ink">{{ donnees.poids_livre_kg }} kg</div>
                </div>
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm px-4 py-4">
                    <div class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] mb-1">Tournées</div>
                    <div class="text-xl font-heading font-semibold text-ink">{{ donnees.routes_total }}</div>
                </div>
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm px-4 py-4">
                    <div class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] mb-1">Distance totale</div>
                    <div class="text-xl font-heading font-semibold text-ink">{{ donnees.distance_totale_km.toFixed(1) }} km</div>
                </div>
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm px-4 py-4">
                    <div class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] mb-1">Taux de livraison</div>
                    <div class="text-xl font-heading font-semibold text-ink">{{ Math.round(donnees.taux_livraison * 100) }}%</div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4">
                    <p class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.4px] mb-3">
                        Livraisons par statut ({{ donnees.livraisons_total }} au total)
                    </p>
                    <div class="relative h-[220px]">
                        <canvas ref="canvasLivraisons"></canvas>
                    </div>
                </div>
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4">
                    <p class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.4px] mb-3">Tournées par statut</p>
                    <div class="relative h-[220px]">
                        <canvas ref="canvasRoutes"></canvas>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
