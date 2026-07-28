<!-- resources/js/components/familles/FamillesStatistiques.vue -->
<!--
    Vue racine de la page Statistiques dossiers familles (section 8.2 du
    prompt de migration). Pas de plage de dates ici (contrairement à
    Activité) : ces stats portent sur l'état ACTUEL des dossiers.

    5 visualisations : répartition par statut (barres), éligibilité
    (anneau), distribution de criticité (barres), répartition par ville
    (barres horizontales), évolution du foyer sur 12 mois (courbes).
-->
<script setup lang="ts">
import { ref, onMounted, onUnmounted, nextTick } from 'vue';
import {
    Chart,
    BarController,
    LineController,
    DoughnutController,
    BarElement,
    LineElement,
    PointElement,
    ArcElement,
    LinearScale,
    CategoryScale,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js';

Chart.register(
    BarController, LineController, DoughnutController,
    BarElement, LineElement, PointElement, ArcElement,
    LinearScale, CategoryScale, Tooltip, Legend, Filler,
);

interface Cartes {
    totalFamilles: number;
    totalAdultes: number;
    totalEnfants: number;
    criticiteMoyenne: number;
    documentsIdentiteManquants: number;
}

interface Donnees {
    parEtatDossier: { valeur: string; total: number }[];
    eligibilite: { zakatElFitr: number; sadaqa: number; aucune: number };
    parCriticite: { valeur: number; total: number }[];
    parVille: { valeur: string; total: number }[];
    seDeplace: { seDeplace: number; neSeDeplacePas: number };
    evolutionFoyer: { mois: string; adultes: number; enfants: number; nouveauxDossiers: number }[];
    cartes: Cartes;
}

declare global {
    interface Window {
        FamillesStatistiquesConfig: { csrf: string; routes: { data: string } };
    }
}

const donnees = ref<Donnees | null>(null);
type LoadState = 'idle' | 'loading' | 'loaded' | 'error';
const loadState = ref<LoadState>('idle');

const COULEUR_ACCENT = '#b45309';
const COULEUR_ACCENT_CLAIR = '#d97706';
const PALETTE = ['#b45309', '#d97706', '#f59e0b', '#fbbf24', '#fcd34d', '#78350f'];

const canvasEtat = ref<HTMLCanvasElement | null>(null);
const canvasEligibilite = ref<HTMLCanvasElement | null>(null);
const canvasCriticite = ref<HTMLCanvasElement | null>(null);
const canvasVille = ref<HTMLCanvasElement | null>(null);
const canvasEvolution = ref<HTMLCanvasElement | null>(null);
let charts: Chart[] = [];

function fmtMoisLabel(iso: string): string {
    const [annee, mois] = iso.split('-');
    return new Date(Number(annee), Number(mois) - 1, 1).toLocaleDateString('fr-FR', { month: 'short', year: '2-digit' });
}

async function charger(): Promise<void> {
    loadState.value = 'loading';
    try {
        const url = window.FamillesStatistiquesConfig?.routes?.data ?? '/familles/statistiques/data';
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error();
        donnees.value = await res.json();
        loadState.value = 'loaded';
        await nextTick();
        dessinerGraphiques();
    } catch (e) {
        loadState.value = 'error';
    }
}

function detruireGraphiques(): void {
    charts.forEach((c) => c.destroy());
    charts = [];
}

function dessinerGraphiques(): void {
    detruireGraphiques();
    if (!donnees.value) return;
    const d = donnees.value;

    if (canvasEtat.value) {
        charts.push(new Chart(canvasEtat.value, {
            type: 'bar',
            data: {
                labels: d.parEtatDossier.map((e) => e.valeur),
                datasets: [{ data: d.parEtatDossier.map((e) => e.total), backgroundColor: COULEUR_ACCENT, borderRadius: 4 }],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        }));
    }

    if (canvasEligibilite.value) {
        charts.push(new Chart(canvasEligibilite.value, {
            type: 'doughnut',
            data: {
                labels: ['Zakat El Fitr', 'Sadaqa', 'Aucune'],
                datasets: [{
                    data: [d.eligibilite.zakatElFitr, d.eligibilite.sadaqa, d.eligibilite.aucune],
                    backgroundColor: [PALETTE[0], PALETTE[2], '#e5e7eb'],
                }],
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } },
        }));
    }

    if (canvasCriticite.value) {
        charts.push(new Chart(canvasCriticite.value, {
            type: 'bar',
            data: {
                labels: d.parCriticite.map((c) => `${c.valeur}`),
                datasets: [{
                    data: d.parCriticite.map((c) => c.total),
                    backgroundColor: d.parCriticite.map((c) => c.valeur >= 4 ? '#e11d48' : c.valeur >= 2 ? COULEUR_ACCENT_CLAIR : '#d1d5db'),
                    borderRadius: 4,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        }));
    }

    if (canvasVille.value) {
        const top = d.parVille.slice(0, 8);
        charts.push(new Chart(canvasVille.value, {
            type: 'bar',
            data: {
                labels: top.map((v) => v.valeur),
                datasets: [{ data: top.map((v) => v.total), backgroundColor: COULEUR_ACCENT, borderRadius: 4 }],
            },
            options: {
                indexAxis: 'y',
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        }));
    }

    if (canvasEvolution.value) {
        charts.push(new Chart(canvasEvolution.value, {
            type: 'line',
            data: {
                labels: d.evolutionFoyer.map((m) => fmtMoisLabel(m.mois)),
                datasets: [
                    { label: 'Adultes', data: d.evolutionFoyer.map((m) => m.adultes), borderColor: COULEUR_ACCENT, backgroundColor: 'rgba(180,83,9,0.1)', fill: true, tension: 0.3 },
                    { label: 'Enfants', data: d.evolutionFoyer.map((m) => m.enfants), borderColor: '#78350f', backgroundColor: 'rgba(120,53,15,0.08)', fill: true, tension: 0.3 },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        }));
    }
}

onMounted(charger);
onUnmounted(detruireGraphiques);
</script>

<template>
    <div v-if="loadState === 'loading'" class="text-center py-16 text-ink-muted text-[13.5px]">Chargement des statistiques…</div>
    <div v-else-if="loadState === 'error'" class="text-center py-16 text-rose-600 text-[13.5px]">Impossible de charger les statistiques.</div>

    <div v-else-if="donnees" class="space-y-6">

        <!-- Cartes -->
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4">
                <div class="text-2xl font-bold text-ink">{{ donnees.cartes.totalFamilles }}</div>
                <div class="text-[11px] text-ink-muted uppercase tracking-wide font-semibold mt-1">Dossiers</div>
            </div>
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4">
                <div class="text-2xl font-bold text-ink">{{ donnees.cartes.totalAdultes }}</div>
                <div class="text-[11px] text-ink-muted uppercase tracking-wide font-semibold mt-1">Adultes</div>
            </div>
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4">
                <div class="text-2xl font-bold text-ink">{{ donnees.cartes.totalEnfants }}</div>
                <div class="text-[11px] text-ink-muted uppercase tracking-wide font-semibold mt-1">Enfants</div>
            </div>
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4">
                <div class="text-2xl font-bold text-accent">{{ donnees.cartes.criticiteMoyenne }}/5</div>
                <div class="text-[11px] text-ink-muted uppercase tracking-wide font-semibold mt-1">Criticité moy.</div>
            </div>
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4">
                <div class="text-2xl font-bold" :class="donnees.cartes.documentsIdentiteManquants > 0 ? 'text-rose-600' : 'text-ink'">{{ donnees.cartes.documentsIdentiteManquants }}</div>
                <div class="text-[11px] text-ink-muted uppercase tracking-wide font-semibold mt-1">Identité manquante</div>
            </div>
        </div>

        <!-- Graphiques -->
        <div class="grid lg:grid-cols-2 gap-5">
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-5">
                <h3 class="text-[12.5px] font-bold text-ink mb-3">Répartition par statut</h3>
                <div class="h-56"><canvas ref="canvasEtat"></canvas></div>
            </div>
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-5">
                <h3 class="text-[12.5px] font-bold text-ink mb-3">Éligibilité</h3>
                <div class="h-56"><canvas ref="canvasEligibilite"></canvas></div>
            </div>
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-5">
                <h3 class="text-[12.5px] font-bold text-ink mb-3">Distribution de criticité</h3>
                <div class="h-56"><canvas ref="canvasCriticite"></canvas></div>
            </div>
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-5">
                <h3 class="text-[12.5px] font-bold text-ink mb-3">Répartition par ville</h3>
                <div class="h-56"><canvas ref="canvasVille"></canvas></div>
            </div>
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-5 lg:col-span-2">
                <h3 class="text-[12.5px] font-bold text-ink mb-3">Évolution du foyer (12 derniers mois)</h3>
                <div class="h-64"><canvas ref="canvasEvolution"></canvas></div>
            </div>
        </div>

        <!-- Se déplace -->
        <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-5">
            <h3 class="text-[12.5px] font-bold text-ink mb-3">Mobilité</h3>
            <div class="flex items-center gap-6 text-[13px]">
                <span class="text-ink">🚶 Se déplace : <strong>{{ donnees.seDeplace.seDeplace }}</strong></span>
                <span class="text-ink-muted">Ne se déplace pas : <strong>{{ donnees.seDeplace.neSeDeplacePas }}</strong></span>
            </div>
        </div>

    </div>
</template>
