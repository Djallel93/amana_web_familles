<!-- resources/js/components/admin/ActiviteStatistiques.vue -->
<!--
    Vue racine de la page Statistiques d'activité (admin uniquement).
    Adapté de amana_web_planning : structure et logique identiques (même
    shape de données AuditStatistics), recoloré en terracotta, cartes de
    synthèse propres à Familles (dossiers créés/modifiés, webhooks envoyés
    au lieu des échanges/régénérations planning de Planning).
-->
<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch, nextTick } from 'vue';
import {
    Chart,
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    CategoryScale,
    Tooltip,
    Legend,
    Filler,
    type ChartConfiguration,
} from 'chart.js';

Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Legend, Filler);

// ── Types ─────────────────────────────────────────────────────────────────
interface PointJour {
    date: string;
    total: number;
}

interface Repartition {
    valeur: string;
    total: number;
}

interface UtilisateurActif {
    nom: string;
    total: number;
}

interface Cartes {
    totalActions: number;
    connexions: number;
    dossiersCrees: number;
    dossiersModifies: number;
    webhooksEnvoyes: number;
    utilisateursDistincts: number;
}

interface Payload {
    serieParJour: PointJour[];
    parModule: Repartition[];
    parAction: Repartition[];
    utilisateursActifs: UtilisateurActif[];
    cartes: Cartes;
}

declare global {
    interface Window {
        ActiviteStatistiquesConfig: {
            csrf: string;
            routes: { data: string };
        };
    }
}

const config = window.ActiviteStatistiquesConfig;

const LIBELLES_ACTION: Record<string, string> = {
    create: 'Création', update: 'Modification', delete: 'Suppression',
    login: 'Connexion', logout: 'Déconnexion', webhook: 'Webhook',
};

function libelleAction(action: string): string {
    return LIBELLES_ACTION[action] ?? action;
}

// ── État ──────────────────────────────────────────────────────────────────
function isoDaysAgo(n: number): string {
    const d = new Date();
    d.setDate(d.getDate() - n);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const dateFrom = ref(isoDaysAgo(29));
const dateTo = ref(isoDaysAgo(0));

const payload = ref<Payload | null>(null);

type LoadState = 'idle' | 'loading' | 'loaded' | 'error';
const loadState = ref<LoadState>('idle');

function getCsrf(): string {
    return config?.csrf
        ?? document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
        ?? '';
}

function fmtDateCourt(iso: string): string {
    return new Date(iso + 'T00:00:00').toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
}

function fmtDateLabel(iso: string): string {
    return new Date(iso + 'T00:00:00').toLocaleDateString('fr-FR', {
        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
    });
}

// ── Chargement ────────────────────────────────────────────────────────────
async function load(): Promise<void> {
    loadState.value = 'loading';
    try {
        const url = `${config.routes.data}?from=${dateFrom.value}&to=${dateTo.value}`;
        const res = await fetch(url, {
            headers: { 'X-CSRF-TOKEN': getCsrf(), 'Accept': 'application/json' },
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);

        payload.value = await res.json() as Payload;
        loadState.value = 'loaded';

        await nextTick();
        renderChart();
    } catch {
        loadState.value = 'error';
    }
}

watch([dateFrom, dateTo], () => load());
onMounted(() => load());

// ── Graphique ─────────────────────────────────────────────────────────────
const canvasRef = ref<HTMLCanvasElement | null>(null);
let chart: Chart | null = null;

function renderChart(): void {
    if (!canvasRef.value || !payload.value) return;

    chart?.destroy();

    const serie = payload.value.serieParJour;
    const labels = serie.map(p => fmtDateCourt(p.date));

    const configChart: ChartConfiguration<'line'> = {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Actions',
                data: serie.map(p => p.total),
                borderColor: '#b45309',
                backgroundColor: 'rgba(180,83,9,0.08)',
                tension: 0.3,
                fill: true,
                pointRadius: 2,
                pointHoverRadius: 5,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { beginAtZero: true, title: { display: true, text: "Nombre d'actions" } },
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (items) => {
                            const point = serie[items[0]?.dataIndex ?? 0];
                            return point ? fmtDateLabel(point.date) : '';
                        },
                    },
                },
            },
        },
    };

    chart = new Chart(canvasRef.value, configChart);
}

onUnmounted(() => chart?.destroy());
</script>

<template>
    <div class="flex flex-col gap-5">

        <!-- Plage de dates -->
        <div class="bg-surface rounded-xl border border-surface-border shadow-sm px-5 py-4 flex flex-wrap items-center gap-3">
            <label for="act_from" class="text-xs font-bold text-ink tracking-[0.2px]">📅 Du</label>
            <input id="act_from" type="date" v-model="dateFrom" :max="dateTo"
                class="px-3.5 py-2.5 border-[1.5px] border-ink-faint rounded-lg text-base font-body text-ink bg-surface-2 outline-none transition
                       focus:border-accent focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]">
            <label for="act_to" class="text-xs font-bold text-ink tracking-[0.2px]">Au</label>
            <input id="act_to" type="date" v-model="dateTo" :min="dateFrom"
                class="px-3.5 py-2.5 border-[1.5px] border-ink-faint rounded-lg text-base font-body text-ink bg-surface-2 outline-none transition
                       focus:border-accent focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]">
        </div>

        <div v-if="loadState === 'loading'" class="text-center py-10 text-[13.5px] text-ink-muted">
            ⏳ Chargement des statistiques…
        </div>
        <div v-else-if="loadState === 'error'" class="text-center py-8 text-rose-600 text-[13px]">
            ❌ Erreur lors du chargement des statistiques.
        </div>

        <template v-else-if="loadState === 'loaded' && payload">

            <!-- Cartes de synthèse -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm px-4 py-4">
                    <div class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] mb-1">📊 Total actions</div>
                    <div class="text-xl font-heading font-semibold text-ink">{{ payload.cartes.totalActions }}</div>
                </div>
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm px-4 py-4">
                    <div class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] mb-1">🔑 Connexions</div>
                    <div class="text-xl font-heading font-semibold text-ink">{{ payload.cartes.connexions }}</div>
                </div>
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm px-4 py-4">
                    <div class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] mb-1">👤 Utilisateurs actifs</div>
                    <div class="text-xl font-heading font-semibold text-ink">{{ payload.cartes.utilisateursDistincts }}</div>
                </div>
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm px-4 py-4">
                    <div class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] mb-1">🏠 Dossiers créés</div>
                    <div class="text-xl font-heading font-semibold text-ink">{{ payload.cartes.dossiersCrees }}</div>
                </div>
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm px-4 py-4">
                    <div class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] mb-1">✏️ Dossiers modifiés</div>
                    <div class="text-xl font-heading font-semibold text-ink">{{ payload.cartes.dossiersModifies }}</div>
                </div>
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm px-4 py-4">
                    <div class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] mb-1">📡 Webhooks envoyés</div>
                    <div class="text-xl font-heading font-semibold text-ink">{{ payload.cartes.webhooksEnvoyes }}</div>
                </div>
            </div>

            <!-- Graphique -->
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-5">
                <div class="relative h-[300px]">
                    <canvas ref="canvasRef"></canvas>
                </div>
            </div>

            <!-- Répartitions + utilisateurs actifs -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4">
                    <p class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.4px] mb-3">Par module</p>
                    <div v-if="!payload.parModule.length" class="text-[12.5px] text-ink-muted">Aucune donnée.</div>
                    <div v-for="r in payload.parModule" :key="r.valeur" class="flex items-center justify-between py-1 text-[13px]">
                        <span class="text-ink">{{ r.valeur }}</span>
                        <span class="font-semibold text-ink-muted">{{ r.total }}</span>
                    </div>
                </div>

                <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4">
                    <p class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.4px] mb-3">Par action</p>
                    <div v-if="!payload.parAction.length" class="text-[12.5px] text-ink-muted">Aucune donnée.</div>
                    <div v-for="r in payload.parAction" :key="r.valeur" class="flex items-center justify-between py-1 text-[13px]">
                        <span class="text-ink">{{ libelleAction(r.valeur) }}</span>
                        <span class="font-semibold text-ink-muted">{{ r.total }}</span>
                    </div>
                </div>

                <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4">
                    <p class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.4px] mb-3">Utilisateurs les plus actifs</p>
                    <div v-if="!payload.utilisateursActifs.length" class="text-[12.5px] text-ink-muted">Aucune donnée.</div>
                    <div v-for="u in payload.utilisateursActifs" :key="u.nom" class="flex items-center justify-between py-1 text-[13px]">
                        <span class="text-ink">{{ u.nom }}</span>
                        <span class="font-semibold text-ink-muted">{{ u.total }}</span>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
