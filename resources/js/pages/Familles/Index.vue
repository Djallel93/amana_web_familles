<!-- resources/js/pages/Familles/Index.vue -->
<!--
    Page Inertia "Dossiers familles" — Section E4 du refactor (chunk 4,
    12/09/2026), remplace resources/views/familles/index.blade.php
    (supprimée dans ce même chunk, plus aucun consommateur une fois
    FamillesController::index() converti en Inertia::render()).

    ?ouvrir=<id> : remplace l'ancienne IIFE de sondage sur
    window.location.search + window.openFamilleDetail (jusqu'à 60
    tentatives à 50ms — voir l'historique Git de l'ancienne
    index.blade.php) par un simple watch() sur la prop ouvrirId.
    Fonctionne sans sondage car DetailPanel est maintenant un enfant Vue
    NORMAL de cette page (plus un îlot createApp().mount() séparé) : Vue
    monte les enfants avant le onMounted() du parent, donc
    window.openFamilleDetail est déjà assignée par DetailPanel au moment
    où ce composant tourne. Le paramètre ?ouvrir= est retiré de l'URL
    après ouverture via router.replace (Inertia), même effet que
    l'ancien window.history.replaceState mais qui reste cohérent avec le
    state Inertia (pas un History API direct qui désynchroniserait
    router.page).
-->
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, watch } from 'vue';
import FamilleFiltresBar from '../../components/familles/FamilleFiltresBar.vue';
import FamillesTable, { type FamilleLigne } from '../../components/familles/FamillesTable.vue';
import DetailPanel from '../../components/familles/DetailPanel.vue';
import ReverseSyncPanel from '../../components/familles/ReverseSyncPanel.vue';
import type { FamilleFiltres, Organisation, Quartier, Secteur, Ville } from '../../components/livraison/shared/types';

interface ListeOption {
    id: number;
    code: string;
    libelle_fr: string;
    libelle_ar: string;
    libelle_en: string;
}

interface Puce {
    label: string;
    href: string;
}

interface FamillesPaginees {
    data: FamilleLigne[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
}

const props = defineProps<{
    familles: FamillesPaginees;
    villes: Ville[];
    secteurs: Secteur[];
    quartiers: Quartier[];
    organisations: Organisation[];
    etatDossier: string;
    secteursActivite: ListeOption[];
    organismesAide: ListeOption[];
    valeursFiltres: FamilleFiltres;
    triActuel: string | null;
    directionActuelle: 'asc' | 'desc';
    filtresActifs: boolean;
    puces: Puce[];
    reinitialiserUrl: string;
    peutSyncGoogleContacts: boolean;
    googleContactsScanUrl: string;
    googleContactsAppliquerUrl: string;
    exportUrl: string;
    ouvrirId: number | null;
    showUrlTemplate: string;
    updateUrlTemplate: string;
    deverrouillerUrlTemplate: string;
    forcerDeverrouillageUrlTemplate: string;
    uploadUrlTemplate: string;
    downloadUrlTemplate: string;
    deleteDocUrlTemplate: string;
    initialGooglePlacesKey: string;
    initialGoogleEmbedKey: string;
}>();

const baseUrl = window.location.pathname;

const currentQuery = computed(() => ({
    ...props.valeursFiltres,
    tri: props.triActuel ?? undefined,
    direction: props.directionActuelle,
    per_page: props.familles.per_page,
}));

function ouvrirIdEtNettoyer(id: number) {
    window.openFamilleDetail?.(id);
    // Retire ouvrir= de l'URL une fois le panneau ouvert — replace: true,
    // preserveState: true : pas une nouvelle entrée d'historique, pas de
    // refetch (rien d'autre n'a changé côté serveur).
    const params = new URLSearchParams(window.location.search);
    params.delete('ouvrir');
    router.get(baseUrl, Object.fromEntries(params), { preserveState: true, preserveScroll: true, replace: true });
}

// onMounted (pas juste watch) : le cas "chargement initial de la page
// avec ?ouvrir= déjà dans l'URL" (lien direct, retour navigateur) doit
// aussi déclencher l'ouverture, pas seulement un changement ultérieur de
// la prop lors d'une visite Inertia suivante.
onMounted(() => {
    if (props.ouvrirId) ouvrirIdEtNettoyer(props.ouvrirId);
});

watch(() => props.ouvrirId, (id) => {
    if (id) ouvrirIdEtNettoyer(id);
});

function ouvrirSyncGoogleContacts() {
    window.openReverseSyncPanel?.();
}
</script>

<template>
    <Head title="Dossiers — AMANA Familles" />

    <div class="flex flex-wrap items-center justify-between gap-4 mb-5">
        <div>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Dossiers familles</h1>
            <p class="text-[13px] text-ink-muted mt-1">
                {{ familles.total }} dossier{{ familles.total !== 1 ? 's' : '' }}
                <template v-if="filtresActifs">(filtré{{ familles.total !== 1 ? 's' : '' }})</template>
            </p>
        </div>
    </div>

    <FamilleFiltresBar
        :villes="villes"
        :secteurs="secteurs"
        :quartiers="quartiers"
        :organisations="organisations"
        :valeurs-filtres="valeursFiltres"
        :avec-statut="true"
        :avec-autocompletion="true"
        :ouvert-par-defaut="true"
        :base-url="baseUrl"
        :per-page="familles.per_page"
    />

    <div class="flex flex-wrap items-start justify-between gap-2 mb-5">
        <div class="flex-1 min-w-0">
            <div v-if="puces.length" class="flex flex-wrap items-center gap-2 px-3 py-2.5 rounded-lg bg-accent/5 border border-accent/20 animate-fade-in-up">
                <span class="text-[10.5px] text-accent-dark uppercase tracking-wide font-bold flex items-center gap-1">🔎 Filtres actifs</span>
                <Link v-for="puce in puces" :key="puce.label" :href="puce.href"
                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-accent/15 text-accent-dark text-[11.5px] font-semibold no-underline hover:bg-accent/25 active:scale-95 transition-all">
                    {{ puce.label }}
                    <span class="text-[10px]">✕</span>
                </Link>
                <Link :href="reinitialiserUrl" class="ml-auto text-[11px] text-ink-muted hover:text-accent-dark font-semibold no-underline transition-colors">
                    Tout réinitialiser
                </Link>
            </div>
        </div>
        <!-- Sync retour Google Contacts (décision du 14/08/2026) — voir
             ReverseSyncPanel.vue. Réservé gestionnaire+ : même contrôle
             qu'auparavant (auth()->user()->isAdmin()||isGestionnaire()),
             résolu côté serveur dans peutSyncGoogleContacts plutôt que
             réévalué ici. -->
        <button v-if="peutSyncGoogleContacts" type="button" @click="ouvrirSyncGoogleContacts"
            class="inline-flex items-center gap-1.5 px-3 py-2 border border-surface-border bg-surface hover:bg-surface-2 text-ink text-[12.5px] font-semibold rounded-lg transition-colors active:scale-95 flex-shrink-0">
            🔄 Sync retour Google Contacts
        </button>
        <a :href="exportUrl"
            class="inline-flex items-center gap-1.5 px-3 py-2 border border-surface-border bg-surface hover:bg-surface-2 text-ink text-[12.5px] font-semibold rounded-lg transition-colors active:scale-95 no-underline flex-shrink-0">
            ⬇️ Exporter CSV
        </a>
    </div>

    <FamillesTable
        :familles="familles"
        :base-url="baseUrl"
        :current-query="currentQuery"
        :tri-actuel="triActuel"
        :direction-actuelle="directionActuelle"
        vide-icone="🏠"
        vide-titre="Aucun dossier"
        :a-filtres-actifs="filtresActifs"
        vide-message-base="Aucune famille enregistrée pour l'instant. L'import des dossiers existants est une étape à venir."
        vide-message-filtre="Aucun résultat pour ces filtres. Essayez d'élargir vos critères ou"
        :vide-lien-reinitialisation="{ texte: 'réinitialisez-les', href: reinitialiserUrl }"
    />

    <DetailPanel
        :show-url-template="showUrlTemplate"
        :update-url-template="updateUrlTemplate"
        :deverrouiller-url-template="deverrouillerUrlTemplate"
        :forcer-deverrouillage-url-template="forcerDeverrouillageUrlTemplate"
        :upload-url-template="uploadUrlTemplate"
        :download-url-template="downloadUrlTemplate"
        :delete-doc-url-template="deleteDocUrlTemplate"
        :initial-google-places-key="initialGooglePlacesKey"
        :initial-google-embed-key="initialGoogleEmbedKey"
        :initial-secteurs-activite-disponibles="secteursActivite"
        :initial-organismes-aide-disponibles="organismesAide"
    />

    <ReverseSyncPanel v-if="peutSyncGoogleContacts" :scan-url="googleContactsScanUrl" :appliquer-url="googleContactsAppliquerUrl" />
</template>
