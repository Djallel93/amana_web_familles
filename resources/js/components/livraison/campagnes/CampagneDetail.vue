<!-- resources/js/components/livraison/campagnes/CampagneDetail.vue -->
<!--
    Écran détail campagne — reconstruit en Vue le 03/09/2026, voir
    resources/views/livraison/campagne-detail.blade.php.

    Corrige par rapport à la version placeholder :
      - la checklist familles ignorait la pagination de l'API (page 1
        seulement) → vraie pagination, voir shared/Paginator.vue ;
      - le résultat de génération (conflits étudiant/hôtel) était fondu
        dans une seule chaîne d'alerte → liste de conflits dédiée et
        toujours visible (section "conflitsGeneration" du template) ;
      - notifier-benevoles/generer-routes étaient de simples alert() →
        Toast + panneaux de résultat persistants ;
      - id_quartier/id_organisation étaient des inputs numériques bruts →
        selects alimentés par les référentiels passés depuis
        CampagnesController::show() (même pattern que le filtre quartier/
        organisation de familles/index.blade.php).
-->
<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useToast, useConfirm } from '@amana/shared-ui';
import { apiGet, apiPost } from '../shared/api';
import Paginator from '../shared/Paginator.vue';
import {
    CAMPAGNE_TYPES,
    normalizePaginated,
    type Campagne,
    type FamilleEligible,
    type GenererLivraisonsResultat,
    type GenererRoutesResultat,
    type Livraison,
    type NotifierBenevolesResultat,
    type Paginated,
    type Quartier,
    type RawLaravelPaginator,
} from '../shared/types';

interface Organisation {
    id: number;
    nom: string;
}

const toast = useToast();
const confirmDialog = useConfirm();

const el = document.getElementById('vue-livraison-campagne-detail')!;
const campagne = ref<Campagne>(JSON.parse(el.dataset.campagne ?? '{}'));
const quartiers = ref<Quartier[]>(JSON.parse(el.dataset.quartiers ?? '[]'));
const organisations = ref<Organisation[]>(JSON.parse(el.dataset.organisations ?? '[]'));
const urls = {
    eligibles: el.dataset.eligiblesUrl ?? '',
    genererLivraisons: el.dataset.genererLivraisonsUrl ?? '',
    notifierBenevoles: el.dataset.notifierBenevolesUrl ?? '',
    genererRoutes: el.dataset.genererRoutesUrl ?? '',
    nonCouvertes: el.dataset.nonCouvertesUrl ?? '',
};

function formatDateFr(iso: string): string {
    const [annee, mois, jour] = iso.split('T')[0].split('-');
    return `${jour}/${mois}/${annee}`;
}

// ── Filtre + checklist familles éligibles ──────────────────────────────
const filtreCriticiteMin = ref('');
const filtreIdQuartier = ref('');
const filtreIdOrganisation = ref('');

const eligibles = ref<FamilleEligible[]>([]);
const metaEligibles = ref<Paginated<FamilleEligible>['meta'] | null>(null);
const chargementEligibles = ref(true);
const erreurEligibles = ref(false);

// La sélection survit à la pagination et au changement de filtre (un
// admin peut cocher des familles sur la page 1, filtrer par quartier,
// cocher d'autres familles sur ce sous-ensemble, puis générer le tout en
// une fois) — Set d'ids plutôt qu'un tableau de lignes cochées par page.
const selectionnees = ref<Set<number>>(new Set());

async function chargerEligibles(page = 1) {
    chargementEligibles.value = true;
    erreurEligibles.value = false;

    const params = new URLSearchParams({ page: String(page) });
    if (filtreCriticiteMin.value) params.set('criticite_min', filtreCriticiteMin.value);
    if (filtreIdQuartier.value) params.set('id_quartier', filtreIdQuartier.value);
    if (filtreIdOrganisation.value) params.set('id_organisation', filtreIdOrganisation.value);

    const resultat = await apiGet<RawLaravelPaginator<FamilleEligible>>(`${urls.eligibles}?${params}`);
    chargementEligibles.value = false;

    if (!resultat.ok) {
        erreurEligibles.value = true;
        return;
    }

    const paginé = normalizePaginated(resultat.data);
    eligibles.value = paginé.data;
    metaEligibles.value = paginé.meta;
}

function toggleFamille(id: number) {
    if (selectionnees.value.has(id)) {
        selectionnees.value.delete(id);
    } else {
        selectionnees.value.add(id);
    }
    // Nouvelle référence pour que Vue détecte le changement (mutation
    // d'un Set n'est pas suivie par la réactivité par défaut).
    selectionnees.value = new Set(selectionnees.value);
}

// ── Génération des livraisons ───────────────────────────────────────────
const chargementGeneration = ref(false);
const resultatGeneration = ref<GenererLivraisonsResultat | null>(null);
const erreurGeneration = ref('');

async function genererLivraisons() {
    if (selectionnees.value.size === 0) {
        erreurGeneration.value = 'Sélectionnez au moins une famille.';
        return;
    }

    chargementGeneration.value = true;
    erreurGeneration.value = '';
    resultatGeneration.value = null;

    const resultat = await apiPost<GenererLivraisonsResultat>(urls.genererLivraisons, {
        ids_familles: [...selectionnees.value],
    });

    chargementGeneration.value = false;

    if (!resultat.ok) {
        erreurGeneration.value = resultat.message;
        return;
    }

    resultatGeneration.value = resultat.data;
    selectionnees.value = new Set();
    toast.success(`${resultat.data.generees} livraison(s) générée(s).`);
    chargerNonCouvertes();
}

// ── Notification bénévoles ──────────────────────────────────────────────
const chargementNotif = ref(false);
const resultatNotif = ref<NotifierBenevolesResultat | null>(null);

async function notifierBenevoles() {
    chargementNotif.value = true;
    resultatNotif.value = null;

    const resultat = await apiPost<NotifierBenevolesResultat>(urls.notifierBenevoles);
    chargementNotif.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    resultatNotif.value = resultat.data;
    toast.success(`Email envoyé à ${resultat.data.envoyes} bénévole(s).`);
}

// ── Génération des routes ───────────────────────────────────────────────
const chargementRoutes = ref(false);
const resultatRoutes = ref<GenererRoutesResultat | null>(null);
const erreurRoutes = ref('');

async function genererRoutes() {
    // Opération lourde (clustering + TSP) qui peut recréer/déplacer des
    // tournées déjà notifiées à des bénévoles — confirmation avant
    // lancement plutôt qu'un simple bouton, contrairement à la version
    // placeholder.
    const confirmed = await confirmDialog.ask({
        title: 'Lancer la génération des routes',
        message: 'Le clustering et l\'assignation des tournées vont être (re)calculés pour cette campagne. Continuer ?',
        confirmLabel: 'Lancer',
    });
    if (!confirmed) return;

    chargementRoutes.value = true;
    erreurRoutes.value = '';
    resultatRoutes.value = null;

    const resultat = await apiPost<GenererRoutesResultat>(urls.genererRoutes);
    chargementRoutes.value = false;

    if (!resultat.ok) {
        erreurRoutes.value = resultat.message;
        toast.error(resultat.message);
        chargerNonCouvertes();
        return;
    }

    resultatRoutes.value = resultat.data;
    toast.success(`${resultat.data.routes_creees} tournée(s) créée(s).`);
    chargerNonCouvertes();
}

// ── Livraisons confirmées jamais couvertes ─────────────────────────────
const nonCouvertes = ref<Livraison[]>([]);
const chargementNonCouvertes = ref(true);
const erreurNonCouvertes = ref(false);

async function chargerNonCouvertes() {
    chargementNonCouvertes.value = true;
    erreurNonCouvertes.value = false;

    const resultat = await apiGet<Livraison[]>(urls.nonCouvertes);
    chargementNonCouvertes.value = false;

    if (!resultat.ok) {
        erreurNonCouvertes.value = true;
        return;
    }

    nonCouvertes.value = resultat.data;
}

onMounted(() => {
    chargerEligibles(1);
    chargerNonCouvertes();
});
</script>

<template>
    <div>
        <h1 class="font-heading text-xl font-semibold text-ink mb-1">
            {{ CAMPAGNE_TYPES[campagne.type] ?? campagne.type }} — {{ formatDateFr(campagne.date_livraison) }}
        </h1>
        <p class="text-[13px] text-ink-muted mb-6">Statut : {{ campagne.statut }}</p>

        <div class="flex flex-col sm:flex-row gap-3 mb-3">
            <button type="button" :disabled="chargementNotif" @click="notifierBenevoles"
                class="min-h-[2.5rem] text-[13px] px-4 py-2 rounded-lg border border-surface-border text-ink-muted disabled:opacity-60">
                📧 {{ chargementNotif ? 'Envoi…' : 'Notifier les bénévoles' }}
            </button>
            <button type="button" :disabled="chargementRoutes" @click="genererRoutes"
                class="min-h-[2.5rem] text-[13px] px-4 py-2 rounded-lg bg-accent text-white disabled:opacity-60">
                🚚 {{ chargementRoutes ? 'Génération…' : 'Lancer le clustering / génération des routes' }}
            </button>
        </div>
        <p v-if="resultatNotif" class="text-[13px] text-ink-muted mb-2">
            {{ resultatNotif.envoyes }} email(s) envoyé(s), {{ resultatNotif.echecs }} échec(s).
        </p>
        <p v-if="resultatRoutes" class="text-[13px] text-ink-muted mb-2">
            {{ resultatRoutes.routes_creees }} tournée(s) créée(s), dont {{ resultatRoutes.imposees }} imposée(s).
        </p>
        <p v-if="erreurRoutes" class="text-[13px] text-rose-600 mb-6">{{ erreurRoutes }}</p>

        <div class="bg-surface border border-surface-border rounded-xl p-5 mb-8">
            <h2 class="text-[14px] font-medium text-ink mb-4">Sélection des familles éligibles</h2>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                <input v-model="filtreCriticiteMin" type="number" placeholder="Criticité min"
                    class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                <select v-model="filtreIdQuartier" class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                    <option value="">Tous les quartiers</option>
                    <option v-for="q in quartiers" :key="q.id" :value="q.id">{{ q.nom }}</option>
                </select>
                <select v-model="filtreIdOrganisation" class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                    <option value="">Toutes les organisations</option>
                    <option v-for="o in organisations" :key="o.id" :value="o.id">{{ o.nom }}</option>
                </select>
            </div>
            <button type="button" @click="chargerEligibles(1)"
                class="min-h-[2.25rem] text-[13px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted mb-4">
                Filtrer
            </button>

            <div class="max-h-96 overflow-y-auto mb-3 border border-surface-border rounded-lg divide-y divide-surface-border">
                <p v-if="chargementEligibles" class="text-[13px] text-ink-muted px-3 py-3">Chargement…</p>
                <p v-else-if="erreurEligibles" class="text-[13px] text-rose-600 px-3 py-3">Impossible de charger les familles éligibles.</p>
                <p v-else-if="eligibles.length === 0" class="text-[13px] text-ink-muted px-3 py-3">Aucune famille éligible pour ces filtres.</p>
                <label v-for="famille in eligibles" :key="famille.id"
                    class="flex items-center gap-2 text-[13px] text-ink px-3 py-2.5 cursor-pointer select-none min-h-[2.5rem]">
                    <input type="checkbox" :checked="selectionnees.has(famille.id)" @change="toggleFamille(famille.id)" class="w-4 h-4 accent-accent shrink-0">
                    <span class="flex-1">
                        {{ famille.prenom }} {{ famille.nom }} — criticité {{ famille.criticite ?? '—' }}
                        · {{ famille.derniere_livraison_le ? 'dernière livraison ' + formatDateFr(famille.derniere_livraison_le) : 'jamais livrée' }}
                    </span>
                </label>
            </div>
            <Paginator v-if="metaEligibles" :meta="metaEligibles" @change="chargerEligibles" />

            <p class="text-[12px] text-ink-muted mt-3 mb-2">{{ selectionnees.size }} famille(s) sélectionnée(s)</p>
            <button type="button" :disabled="chargementGeneration" @click="genererLivraisons"
                class="min-h-[2.5rem] text-[13px] px-4 py-2 rounded-lg bg-accent text-white disabled:opacity-60">
                {{ chargementGeneration ? 'Génération…' : 'Générer les livraisons pour la sélection' }}
            </button>
            <p v-if="erreurGeneration" class="text-[13px] text-rose-600 mt-3">{{ erreurGeneration }}</p>

            <div v-if="resultatGeneration" class="mt-4 pt-4 border-t border-surface-border">
                <p class="text-[13px] text-ink">
                    {{ resultatGeneration.generees }} livraison(s) générée(s), {{ resultatGeneration.deja_existantes }} déjà existante(s).
                </p>
                <!-- Liste des conflits étudiant/hôtel dans sa propre section
                     visible, plutôt que fondue dans le texte d'un seul
                     message comme dans la version placeholder — voir
                     docblock de fichier. -->
                <div v-if="resultatGeneration.conflits.length > 0" class="mt-2 bg-rose-50 border border-rose-200 rounded-lg p-3">
                    <p class="text-[12.5px] font-medium text-rose-700 mb-1.5">
                        ⚠ {{ resultatGeneration.conflits.length }} famille(s) en conflit étudiant/hôtel, à corriger avant génération :
                    </p>
                    <ul class="text-[12.5px] text-rose-700 space-y-0.5 list-disc list-inside">
                        <li v-for="conflit in resultatGeneration.conflits" :key="conflit.id">{{ conflit.nom }}</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="bg-surface border border-surface-border rounded-xl p-5">
            <h2 class="text-[14px] font-medium text-ink mb-3">Livraisons confirmées jamais couvertes</h2>
            <p v-if="chargementNonCouvertes" class="text-[13px] text-ink-muted">Chargement…</p>
            <p v-else-if="erreurNonCouvertes" class="text-[13px] text-rose-600">Impossible de charger cette liste.</p>
            <p v-else-if="nonCouvertes.length === 0" class="text-[13px] text-ink-muted">Aucune.</p>
            <ul v-else class="text-[13px] text-ink space-y-1">
                <li v-for="livraison in nonCouvertes" :key="livraison.id">
                    {{ livraison.famille.prenom }} {{ livraison.famille.nom }} — {{ livraison.famille.adresse }}
                </li>
            </ul>
        </div>
    </div>
</template>
