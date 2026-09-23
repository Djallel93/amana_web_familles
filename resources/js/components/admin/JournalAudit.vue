<!-- resources/js/components/admin/JournalAudit.vue -->
<!--
    Vue racine de la page Journal d'audit (admin uniquement) — voir
    Amana\Shared\Http\Controllers\AuditLogController (amana_shared) : cette
    page Blade (resources/views/admin/journal/index.blade.php, package
    amana-shared) reste un îlot classique (mountIfPresent('vue-journal-audit',
    ...) dans app.ts), pas une page Inertia — même famille que
    ActiviteStatistiques.vue ci-à-côté, dont ce composant reprend
    l'architecture (window.XConfig, getCsrf(), fetch() brut, machine à
    états loadState) plutôt qu'une nouvelle convention.

    Filtres (module/action/utilisateur/plage de dates) envoyés en query
    string à GET /admin/journal/data (AuditLogController::data(), déjà
    scopé à l'application courante côté serveur). Diff avant/après replié
    par défaut par ligne (potentiellement volumineux) plutôt qu'affiché
    en permanence.
-->
<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';

// ── Types ─────────────────────────────────────────────────────────────────
interface Personne {
    id: number;
    nom: string;
}

interface EntreeJournal {
    id: number;
    date: string;
    utilisateur: string;
    action: string;
    module: string;
    entityId: number | string | null;
    before: unknown;
    after: unknown;
    ipAddress: string | null;
    userAgent: string | null;
}

interface Reponse {
    data: EntreeJournal[];
    meta: { current_page: number; last_page: number; total: number };
}

declare global {
    interface Window {
        JournalAuditConfig: {
            csrf: string;
            routes: { data: string };
            modules: string[];
            actions: string[];
            personnes: Personne[];
        };
    }
}

const config = window.JournalAuditConfig;

// Mêmes libellés que ActiviteStatistiques.vue (LIBELLES_ACTION) — le jeu
// d'actions possibles vient de config('amana-shared.audit.actions'), commun
// aux deux écrans ; pas de fichier partagé pour ces libellés côté JS (les
// deux composants restent des îlots indépendants), dupliqué sciemment
// plutôt que d'introduire un import croisé pour six libellés.
const LIBELLES_ACTION: Record<string, string> = {
    create: 'Création', update: 'Modification', delete: 'Suppression', generate: 'Génération',
    login: 'Connexion', logout: 'Déconnexion', webhook: 'Webhook',
};
function libelleAction(action: string): string {
    return LIBELLES_ACTION[action] ?? action;
}
function libelleModule(module: string): string {
    return module.replace(/[-_]/g, ' ').replace(/^./, (c) => c.toUpperCase());
}

function getCsrf(): string {
    return config?.csrf
        ?? document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
        ?? '';
}

// ── Filtres ───────────────────────────────────────────────────────────────
const filtres = reactive({
    module: '',
    action: '',
    user_id: '',
    from: '',
    to: '',
});

function reinitialiserFiltres(): void {
    filtres.module = '';
    filtres.action = '';
    filtres.user_id = '';
    filtres.from = '';
    filtres.to = '';
}

const filtresActifs = computed(() =>
    Object.values(filtres).some((v) => v !== ''),
);

// ── Chargement ────────────────────────────────────────────────────────────
type LoadState = 'idle' | 'loading' | 'loaded' | 'error';
const loadState = ref<LoadState>('idle');
const entrees = ref<EntreeJournal[]>([]);
const meta = ref<{ current_page: number; last_page: number; total: number }>({
    current_page: 1, last_page: 1, total: 0,
});
const page = ref(1);

// Une seule ligne dépliée à la fois (id de l'entrée) — évite un tableau
// encombré de diffs JSON ouverts simultanément.
const ligneDepliee = ref<number | null>(null);

function construireUrl(): string {
    const params = new URLSearchParams({ page: String(page.value) });
    if (filtres.module) params.set('module', filtres.module);
    if (filtres.action) params.set('action', filtres.action);
    if (filtres.user_id) params.set('user_id', filtres.user_id);
    if (filtres.from) params.set('from', filtres.from);
    if (filtres.to) params.set('to', filtres.to);

    return `${config.routes.data}?${params.toString()}`;
}

async function charger(): Promise<void> {
    loadState.value = 'loading';
    try {
        const reponse = await fetch(construireUrl(), {
            headers: { 'X-CSRF-TOKEN': getCsrf(), 'Accept': 'application/json' },
        });
        if (!reponse.ok) throw new Error('HTTP ' + reponse.status);

        const resultat = await reponse.json() as Reponse;
        entrees.value = resultat.data;
        meta.value = resultat.meta;
        loadState.value = 'loaded';
    } catch {
        loadState.value = 'error';
    }
}

// Tout changement de filtre revient à la page 1 — sans ça, un filtre
// pourrait laisser l'écran sur une page désormais hors bornes.
watch(filtres, () => {
    page.value = 1;
    charger();
});
watch(page, () => charger());

charger();

function pagePrecedente(): void {
    if (meta.value.current_page > 1) page.value = meta.value.current_page - 1;
}
function pageSuivante(): void {
    if (meta.value.current_page < meta.value.last_page) page.value = meta.value.current_page + 1;
}

function basculerLigne(id: number): void {
    ligneDepliee.value = ligneDepliee.value === id ? null : id;
}

function formaterDiff(valeur: unknown): string {
    if (valeur === null || valeur === undefined) return '—';
    return JSON.stringify(valeur, null, 2);
}
</script>

<template>
    <div class="flex flex-col gap-5">

        <!-- Filtres -->
        <div class="bg-surface rounded-xl border border-surface-border shadow-sm px-5 py-4 flex flex-wrap items-end gap-3">
            <div class="flex flex-col gap-1">
                <label for="jrn_module" class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.4px]">Module</label>
                <select id="jrn_module" v-model="filtres.module"
                    class="px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-[13px] text-ink bg-surface-2 outline-none transition
                           focus:border-accent focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]">
                    <option value="">Tous</option>
                    <option v-for="m in config.modules" :key="m" :value="m">{{ libelleModule(m) }}</option>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label for="jrn_action" class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.4px]">Action</label>
                <select id="jrn_action" v-model="filtres.action"
                    class="px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-[13px] text-ink bg-surface-2 outline-none transition
                           focus:border-accent focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]">
                    <option value="">Toutes</option>
                    <option v-for="a in config.actions" :key="a" :value="a">{{ libelleAction(a) }}</option>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label for="jrn_user" class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.4px]">Utilisateur</label>
                <select id="jrn_user" v-model="filtres.user_id"
                    class="px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-[13px] text-ink bg-surface-2 outline-none transition
                           focus:border-accent focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)] min-w-[180px]">
                    <option value="">Tous</option>
                    <option v-for="p in config.personnes" :key="p.id" :value="String(p.id)">{{ p.nom }}</option>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label for="jrn_from" class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.4px]">Du</label>
                <input id="jrn_from" type="date" v-model="filtres.from" :max="filtres.to || undefined"
                    class="px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-[13px] text-ink bg-surface-2 outline-none transition
                           focus:border-accent focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]">
            </div>

            <div class="flex flex-col gap-1">
                <label for="jrn_to" class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.4px]">Au</label>
                <input id="jrn_to" type="date" v-model="filtres.to" :min="filtres.from || undefined"
                    class="px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-[13px] text-ink bg-surface-2 outline-none transition
                           focus:border-accent focus:shadow-[0_0_0_3px_rgba(180,83,9,0.2)]">
            </div>

            <button v-if="filtresActifs" type="button" @click="reinitialiserFiltres"
                class="px-3 py-2 text-[12.5px] font-semibold text-ink-muted hover:text-ink border border-surface-border rounded-lg transition-colors cursor-pointer">
                ✕ Réinitialiser
            </button>
        </div>

        <div v-if="loadState === 'loading' && entrees.length === 0" class="text-center py-10 text-[13.5px] text-ink-muted">
            ⏳ Chargement du journal…
        </div>
        <div v-else-if="loadState === 'error'" class="text-center py-8 text-rose-600 text-[13px]">
            ❌ Erreur lors du chargement du journal.
        </div>

        <template v-else>
            <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th v-for="col in ['Date', 'Utilisateur', 'Module', 'Action', 'Entité', 'IP', '']" :key="col"
                                class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                                {{ col }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="entrees.length === 0">
                            <td colspan="7" class="px-4 py-6 text-ink-muted text-center">Aucune entrée pour ces filtres.</td>
                        </tr>
                        <template v-for="entree in entrees" :key="entree.id">
                            <tr class="border-b border-surface-3 last:border-0">
                                <td class="px-4 py-2.5 text-ink-muted whitespace-nowrap">{{ entree.date }}</td>
                                <td class="px-4 py-2.5 text-ink">{{ entree.utilisateur }}</td>
                                <td class="px-4 py-2.5 text-ink-muted">{{ libelleModule(entree.module) }}</td>
                                <td class="px-4 py-2.5 text-ink">{{ libelleAction(entree.action) }}</td>
                                <td class="px-4 py-2.5 text-ink-muted font-mono text-xs">{{ entree.entityId ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-ink-muted font-mono text-xs">{{ entree.ipAddress ?? '—' }}</td>
                                <td class="px-2 py-2.5 text-right">
                                    <button type="button" @click="basculerLigne(entree.id)"
                                        class="px-2.5 py-1 text-[11.5px] font-semibold text-accent hover:text-accent-dark cursor-pointer whitespace-nowrap">
                                        {{ ligneDepliee === entree.id ? 'Masquer' : 'Détail' }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="ligneDepliee === entree.id" class="border-b border-surface-3 last:border-0">
                                <td colspan="7" class="px-4 py-3 bg-surface-2">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                                        <div>
                                            <p class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.4px] mb-1">Avant</p>
                                            <pre class="text-[11.5px] bg-surface border border-surface-border rounded-lg p-2.5 overflow-x-auto whitespace-pre-wrap break-words">{{ formaterDiff(entree.before) }}</pre>
                                        </div>
                                        <div>
                                            <p class="text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.4px] mb-1">Après</p>
                                            <pre class="text-[11.5px] bg-surface border border-surface-border rounded-lg p-2.5 overflow-x-auto whitespace-pre-wrap break-words">{{ formaterDiff(entree.after) }}</pre>
                                        </div>
                                    </div>
                                    <p class="text-[11.5px] text-ink-muted break-all">
                                        <span class="font-bold uppercase tracking-[0.4px] mr-1">User-agent :</span>{{ entree.userAgent ?? '—' }}
                                    </p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div v-if="meta.total > 0" class="flex items-center justify-between text-[12.5px] text-ink-muted px-1">
                <span>{{ meta.total }} entrée(s) — page {{ meta.current_page }}/{{ meta.last_page }}</span>
                <div class="flex gap-2">
                    <button type="button" :disabled="meta.current_page <= 1" @click="pagePrecedente"
                        class="px-3 py-1.5 border border-surface-border rounded-lg font-semibold transition-colors
                               enabled:hover:bg-surface-2 enabled:cursor-pointer disabled:opacity-40">
                        ← Précédent
                    </button>
                    <button type="button" :disabled="meta.current_page >= meta.last_page" @click="pageSuivante"
                        class="px-3 py-1.5 border border-surface-border rounded-lg font-semibold transition-colors
                               enabled:hover:bg-surface-2 enabled:cursor-pointer disabled:opacity-40">
                        Suivant →
                    </button>
                </div>
            </div>
        </template>
    </div>
</template>
