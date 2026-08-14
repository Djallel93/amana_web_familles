<!-- resources/js/components/familles/FamillesStatistiques.vue -->
<!--
    Vue racine de la page Statistiques dossiers familles (section 8.2 du
    prompt de migration). Pas de plage de dates ici (contrairement à
    Activité) : ces stats portent sur l'état ACTUEL des dossiers.

    5 visualisations : répartition par statut (barres), éligibilité
    (anneau), répartition par quartier (barres horizontales, remplace la
    distribution de criticité le 13/08/2026), répartition par ville
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
    aTraiterPriorite: number;
}

interface Donnees {
    parEtatDossier: { valeur: string; total: number }[];
    eligibilite: { zakatElFitr: number; sadaqa: number; aucune: number };
    parQuartier: { valeur: string; total: number }[];
    parVille: { valeur: string; total: number }[];
    seDeplace: { seDeplace: number; neSeDeplacePas: number };
    etudiant: { etudiant: number; nonEtudiant: number };
    estHotel: { estHotel: number; nonHotel: number };
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

/**
 * Couleurs lues depuis les variables CSS --color-accent* (voir
 * public/css/custom.css + tailwind.config.js) plutôt que dupliquées en hex
 * dans ce fichier — c'est justement cette duplication qui avait laissé
 * traîner une palette ambre copiée-collée d'un autre app AMANA jusqu'au
 * 13/08/2026 ("la page n'utilise pas le thème tailwind.config.js"). Un seul
 * jeton par teinte (RGB "R G B", format déjà utilisé par
 * amana-shared.css pour surface/ink) que Chart.js reconstruit en chaîne
 * rgb()/rgba() exploitable — ni les classes Tailwind ni les valeurs
 * hex ne changent plus qu'à un seul endroit si le thème évolue un jour.
 */
function lireVariableCouleur(nom: string, repli: string): string {
    const brute = getComputedStyle(document.documentElement).getPropertyValue(nom).trim();
    return brute || repli;
}

function versRgb(rgbEspace: string, alpha?: number): string {
    const [r, g, b] = rgbEspace.split(/\s+/);
    return alpha === undefined ? `rgb(${r}, ${g}, ${b})` : `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

const canvasEtat = ref<HTMLCanvasElement | null>(null);
const canvasEligibilite = ref<HTMLCanvasElement | null>(null);
const canvasQuartier = ref<HTMLCanvasElement | null>(null);
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

    // Recalculées à chaque tracé (coût négligeable) plutôt qu'une fois à
    // l'import du module : garantit que getComputedStyle() lit bien les
    // variables une fois les feuilles de style effectivement appliquées.
    const accentRgb = lireVariableCouleur('--color-accent', '15 118 110');
    const accentDarkRgb = lireVariableCouleur('--color-accent-dark', '13 148 136');
    const accentLightRgb = lireVariableCouleur('--color-accent-light', '20 184 166');
    const COULEUR_ACCENT = versRgb(accentRgb);
    const COULEUR_ACCENT_DARK = versRgb(accentDarkRgb);
    const COULEUR_ACCENT_CLAIR = versRgb(accentLightRgb);
    const COULEUR_ACCENT_FAIBLE = versRgb(accentRgb, 0.1);
    const COULEUR_ACCENT_DARK_FAIBLE = versRgb(accentDarkRgb, 0.1);

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
                    backgroundColor: [COULEUR_ACCENT, COULEUR_ACCENT_CLAIR, '#e5e7eb'],
                }],
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } },
        }));
    }

    // Remplace l'ancienne "Distribution de criticité" le 13/08/2026 (la
    // carte "Criticité moyenne" couvrait déjà l'essentiel, la répartition
    // géographique est plus actionnable) — 10 quartiers les plus
    // représentés déjà triés côté FamilleStatistics::repartitionParQuartier().
    if (canvasQuartier.value) {
        charts.push(new Chart(canvasQuartier.value, {
            type: 'bar',
            data: {
                labels: d.parQuartier.map((q) => q.valeur),
                datasets: [{ data: d.parQuartier.map((q) => q.total), backgroundColor: COULEUR_ACCENT_DARK, borderRadius: 4 }],
            },
            options: {
                indexAxis: 'y',
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
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
                    { label: 'Adultes', data: d.evolutionFoyer.map((m) => m.adultes), borderColor: COULEUR_ACCENT, backgroundColor: COULEUR_ACCENT_FAIBLE, fill: true, tension: 0.3 },
                    { label: 'Enfants', data: d.evolutionFoyer.map((m) => m.enfants), borderColor: COULEUR_ACCENT_DARK, backgroundColor: COULEUR_ACCENT_DARK_FAIBLE, fill: true, tension: 0.3 },
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

        <!-- Cartes — icônes ré-introduites le 13/08/2026 (retirées par
             erreur lors de la migration du bandeau KPI de
             familles/index.blade.php, alors que ce dernier en avait) : même
             traitement rond + emoji que l'ancien bandeau, pour toutes les
             cartes et pas seulement celle migrée. -->
        <div class="grid grid-cols-2 lg:grid-cols-6 gap-3">
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center text-lg flex-shrink-0">🏠</div>
                <div class="min-w-0">
                    <div class="text-[20px] font-heading font-semibold text-ink leading-none">{{ donnees.cartes.totalFamilles }}</div>
                    <div class="text-[10.5px] text-ink-muted uppercase tracking-wide mt-1">Dossiers</div>
                </div>
            </div>
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center text-lg flex-shrink-0">🧑</div>
                <div class="min-w-0">
                    <div class="text-[20px] font-heading font-semibold text-ink leading-none">{{ donnees.cartes.totalAdultes }}</div>
                    <div class="text-[10.5px] text-ink-muted uppercase tracking-wide mt-1">Adultes</div>
                </div>
            </div>
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center text-lg flex-shrink-0">🧒</div>
                <div class="min-w-0">
                    <div class="text-[20px] font-heading font-semibold text-ink leading-none">{{ donnees.cartes.totalEnfants }}</div>
                    <div class="text-[10.5px] text-ink-muted uppercase tracking-wide mt-1">Enfants</div>
                </div>
            </div>
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center text-lg flex-shrink-0">🎚️</div>
                <div class="min-w-0">
                    <div class="text-[20px] font-heading font-semibold text-accent leading-none">{{ donnees.cartes.criticiteMoyenne }}<span class="text-[13px] text-ink-muted font-medium">/5</span></div>
                    <div class="text-[10.5px] text-ink-muted uppercase tracking-wide mt-1">Criticité moy.</div>
                </div>
            </div>
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full flex items-center justify-center text-lg flex-shrink-0" :class="donnees.cartes.documentsIdentiteManquants > 0 ? 'bg-rose-100' : 'bg-emerald-100'">🪪</div>
                <div class="min-w-0">
                    <div class="text-[20px] font-heading font-semibold leading-none" :class="donnees.cartes.documentsIdentiteManquants > 0 ? 'text-rose-600' : 'text-ink'">{{ donnees.cartes.documentsIdentiteManquants }}</div>
                    <div class="text-[10.5px] text-ink-muted uppercase tracking-wide mt-1">Identité manquante</div>
                </div>
            </div>
            <!-- Migrée depuis le bandeau KPI de familles/index.blade.php le
                 13/08/2026 (seule carte de ce bandeau sans équivalent déjà
                 présent ici) -->
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 flex items-center gap-3">
                <div class="w-10 h-10 rounded-full flex items-center justify-center text-lg flex-shrink-0" :class="donnees.cartes.aTraiterPriorite > 0 ? 'bg-rose-100' : 'bg-emerald-100'">
                    {{ donnees.cartes.aTraiterPriorite > 0 ? '⚠️' : '✅' }}
                </div>
                <div class="min-w-0">
                    <div class="text-[20px] font-heading font-semibold leading-none" :class="donnees.cartes.aTraiterPriorite > 0 ? 'text-rose-600' : 'text-ink'">{{ donnees.cartes.aTraiterPriorite }}</div>
                    <div class="text-[10.5px] text-ink-muted uppercase tracking-wide mt-1">À traiter en priorité</div>
                </div>
            </div>
        </div>

        <!-- Caractéristiques — remplace le panneau "Mobilité" du bas le
             13/08/2026 (même style de carte que ci-dessus plutôt qu'un
             panneau texte à part, + étudiant/hôtel qui n'existaient pas
             encore ici). Seul le décompte "vrai" est affiché — demande
             explicite du 13/08/2026 ("display only number were true") ;
             nonEtudiant/neSeDeplacePas/nonHotel restent dans la réponse
             JSON si un usage futur en a besoin (ex. un graphique), juste
             pas rendus ici. -->
        <div>
            <h3 class="text-[11px] font-bold text-ink-muted uppercase tracking-wide mb-2">Caractéristiques</h3>
            <div class="grid grid-cols-3 gap-3">
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center text-lg flex-shrink-0">🚶</div>
                    <div class="min-w-0">
                        <div class="text-[20px] font-heading font-semibold text-ink leading-none">{{ donnees.seDeplace.seDeplace }}</div>
                        <div class="text-[10.5px] text-ink-muted uppercase tracking-wide mt-1">Se déplace</div>
                    </div>
                </div>
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center text-lg flex-shrink-0">🎓</div>
                    <div class="min-w-0">
                        <div class="text-[20px] font-heading font-semibold text-ink leading-none">{{ donnees.etudiant.etudiant }}</div>
                        <div class="text-[10.5px] text-ink-muted uppercase tracking-wide mt-1">Étudiant</div>
                    </div>
                </div>
                <div class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center text-lg flex-shrink-0">🏨</div>
                    <div class="min-w-0">
                        <div class="text-[20px] font-heading font-semibold text-ink leading-none">{{ donnees.estHotel.estHotel }}</div>
                        <div class="text-[10.5px] text-ink-muted uppercase tracking-wide mt-1">Hôtel</div>
                    </div>
                </div>
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
                <h3 class="text-[12.5px] font-bold text-ink mb-3">Répartition par quartier</h3>
                <div class="h-56"><canvas ref="canvasQuartier"></canvas></div>
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

    </div>
</template>
