<!-- resources/js/components/livraison/campagnes/CampagnesIndex.vue -->
<!--
    Écran Campagnes — LISTE SEULE depuis le 03/10/2026 : le formulaire de
    création (qui vivait au-dessus de la liste) est devenu sa propre page,
    CampagneCreer.vue, atteinte par le bouton « Nouvelle campagne » en haut
    à droite (voir pages/Livraison/Campagnes.vue).

    Conservés tels quels : filtres type + date (08/09/2026 §2.1.3) et
    suppression en cascade avec aperçu des répercussions (08/09/2026
    §2.1/§2.2 — voir CampagnesController::destroy()).

    Nouveau : cliquer sur une ligne la déplie et affiche les statistiques de
    la campagne (familles, contacts, bénévoles, poids, tournées, packaging,
    livraisons). Elles sont demandées à la PREMIÈRE ouverture de chaque ligne
    (CampagnesController::apercu()) puis gardées en mémoire, plutôt que
    précalculées pour toute la liste — même principe que l'aperçu de
    suppression : N requêtes à chaque visite pour une info qu'on ne consulte
    que ligne par ligne serait du gâchis. « Modifier » devient « Ouvrir » :
    la page campagne est le hub, plus seulement une surface d'édition.
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { useConfirm, useToast } from '@amana/shared-ui';
import { apiDelete, apiGet } from '../shared/api';
import { CAMPAGNE_TYPES, type Campagne, type ApercuCampagne, type CampagneType } from '../shared/types';
import CampagneApercu from './CampagneApercu.vue';
import { CAMPAGNE_STATUT_LABELS, CAMPAGNE_TYPE_STYLES, formatDateFr } from './campagneStyles';

const props = defineProps<{
    campagnes: Campagne[];
    apercuUrlTemplate: string;
    resumeSuppressionUrlTemplate: string;
    destroyUrlTemplate: string;
}>();

// Copie locale mutable : supprimerCampagne() retire une ligne
// optimistiquement après succès.
const campagnes = ref<Campagne[]>([...props.campagnes]);

const toast = useToast();
const confirmDialog = useConfirm();

// ── Lignes dépliables + statistiques à la demande ───────────────────────
const ouvertes = ref<Set<number>>(new Set());
const apercus = ref<Record<number, ApercuCampagne>>({});
const apercusEnCours = ref<Set<number>>(new Set());
const apercusEnErreur = ref<Set<number>>(new Set());

async function chargerApercu(campagne: Campagne) {
    if (apercus.value[campagne.id] || apercusEnCours.value.has(campagne.id)) return;

    apercusEnCours.value = new Set(apercusEnCours.value).add(campagne.id);
    apercusEnErreur.value.delete(campagne.id);

    const resultat = await apiGet<ApercuCampagne>(props.apercuUrlTemplate.replace('__CAMPAGNE__', String(campagne.id)));

    const encours = new Set(apercusEnCours.value);
    encours.delete(campagne.id);
    apercusEnCours.value = encours;

    if (!resultat.ok) {
        apercusEnErreur.value = new Set(apercusEnErreur.value).add(campagne.id);
        toast.error(resultat.message);
        return;
    }

    apercus.value = { ...apercus.value, [campagne.id]: resultat.data };
}

function basculer(campagne: Campagne) {
    const suivantes = new Set(ouvertes.value);
    if (suivantes.has(campagne.id)) {
        suivantes.delete(campagne.id);
    } else {
        suivantes.add(campagne.id);
        void chargerApercu(campagne);
    }
    ouvertes.value = suivantes;
}

// ── Filtres type + date (08/09/2026, prompt §2.1.3) ─────────────────────
const filtreType = ref<CampagneType | ''>('');
const filtreDateDebut = ref('');
const filtreDateFin = ref('');

const campagnesTriees = computed(() =>
    [...campagnes.value].sort((a, b) => (a.date_livraison < b.date_livraison ? 1 : -1)),
);

const campagnesFiltrees = computed(() => campagnesTriees.value.filter((campagne) => {
    if (filtreType.value && campagne.type !== filtreType.value) return false;

    const date = campagne.date_livraison.split('T')[0];
    if (filtreDateDebut.value && date < filtreDateDebut.value) return false;
    if (filtreDateFin.value && date > filtreDateFin.value) return false;

    return true;
}));

function reinitialiserFiltres() {
    filtreType.value = '';
    filtreDateDebut.value = '';
    filtreDateFin.value = '';
}

// ── Suppression en cascade (08/09/2026, prompt §2.1/§2.2) ───────────────
// L'aperçu des répercussions est demandé à CHAQUE clic sur « Supprimer »
// (voir resumeSuppression()) — jamais précalculé pour toute la liste.
const suppressionEnCours = ref<number | null>(null);

async function supprimerCampagne(campagne: Campagne) {
    suppressionEnCours.value = campagne.id;

    const resume = await apiGet<{
        journees: number; livraisons: number; routes: number;
        donations: number; arrivees: number; equipe_membres: number;
    }>(props.resumeSuppressionUrlTemplate.replace('__CAMPAGNE__', String(campagne.id)));

    if (!resume.ok) {
        toast.error(resume.message);
        suppressionEnCours.value = null;
        return;
    }

    const r = resume.data;
    const confirmed = await confirmDialog.ask({
        title: 'Supprimer cette campagne ?',
        message: `Cette campagne compte ${r.journees} journée(s), ${r.livraisons} livraison(s), `
            + `${r.routes} tournée(s), ${r.arrivees} arrivée(s) et ${r.donations} donation(s) enregistrée(s), `
            + `ainsi que ${r.equipe_membres} affectation(s) d'équipe. Tout sera supprimé définitivement `
            + `et irréversiblement, sans possibilité de récupération.`,
        confirmLabel: 'Supprimer définitivement',
        danger: true,
    });

    if (!confirmed) {
        suppressionEnCours.value = null;
        return;
    }

    const resultat = await apiDelete<{ success: boolean }>(props.destroyUrlTemplate.replace('__CAMPAGNE__', String(campagne.id)));
    suppressionEnCours.value = null;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    campagnes.value = campagnes.value.filter((c) => c.id !== campagne.id);
    toast.success('Campagne supprimée.');
}
</script>

<template>
    <div>
        <!-- Filtres type + date (08/09/2026, prompt §2.1.3) -->
        <div class="flex flex-wrap items-end gap-3 mb-4">
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Type</label>
                <select v-model="filtreType" class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                    <option value="">Tous</option>
                    <option v-for="(label, code) in CAMPAGNE_TYPES" :key="code" :value="code">{{ label }}</option>
                </select>
            </div>
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Du</label>
                <input v-model="filtreDateDebut" type="date"
                    class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
            </div>
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Au</label>
                <input v-model="filtreDateFin" type="date"
                    class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
            </div>
            <button v-if="filtreType || filtreDateDebut || filtreDateFin" type="button" @click="reinitialiserFiltres"
                class="text-[12.5px] text-ink-muted underline min-h-[2.25rem]">
                Réinitialiser
            </button>
        </div>

        <div class="space-y-2">
            <div v-for="campagne in campagnesFiltrees" :key="campagne.id"
                class="bg-surface border rounded-xl transition-colors"
                :class="ouvertes.has(campagne.id) ? 'border-accent' : 'border-surface-border hover:border-accent'">
                <!-- En-tête cliquable : div role=button plutôt que <button>, car il contient d'autres boutons -->
                <div role="button" tabindex="0" :aria-expanded="ouvertes.has(campagne.id)"
                    class="flex items-center justify-between gap-3 p-4 cursor-pointer select-none"
                    @click="basculer(campagne)" @keydown.enter.prevent="basculer(campagne)" @keydown.space.prevent="basculer(campagne)">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="text-ink-muted text-[13px] transition-transform duration-200 shrink-0"
                            :class="ouvertes.has(campagne.id) ? 'rotate-180' : ''">▾</span>
                        <span class="shrink-0 text-[11px] font-medium px-2 py-0.5 rounded-full border"
                            :class="CAMPAGNE_TYPE_STYLES[campagne.type]?.pastille">
                            {{ CAMPAGNE_TYPES[campagne.type] ?? campagne.type }}
                        </span>
                        <span class="text-[14px] font-medium text-ink truncate">{{ formatDateFr(campagne.date_livraison) }}</span>
                        <span class="text-[12px] text-ink-muted shrink-0">{{ CAMPAGNE_STATUT_LABELS[campagne.statut] ?? campagne.statut }}</span>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <a :href="`/livraison/campagnes/${campagne.id}`" @click.stop
                            class="text-[12.5px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted hover:bg-stone-50">
                            Ouvrir
                        </a>
                        <button type="button" :disabled="suppressionEnCours === campagne.id"
                            @click.stop="supprimerCampagne(campagne)"
                            class="text-[12.5px] px-3 py-1.5 rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50 disabled:opacity-60">
                            {{ suppressionEnCours === campagne.id ? 'Suppression…' : 'Supprimer' }}
                        </button>
                    </div>
                </div>

                <!-- Statistiques (chargées à la première ouverture) -->
                <div v-if="ouvertes.has(campagne.id)" class="border-t border-surface-border px-4 py-4">
                    <p v-if="apercusEnCours.has(campagne.id)" class="text-[13px] text-ink-muted">Chargement des statistiques…</p>

                    <p v-else-if="apercusEnErreur.has(campagne.id)" class="text-[13px] text-rose-600">
                        Impossible de charger les statistiques.
                        <button type="button" class="underline" @click="chargerApercu(campagne)">Réessayer</button>
                    </p>

                    <CampagneApercu v-else-if="apercus[campagne.id]" :apercu="apercus[campagne.id]" />
                </div>
            </div>
            <p v-if="campagnesFiltrees.length === 0" class="text-[14px] text-ink-muted">Aucune campagne pour ces filtres.</p>
        </div>
    </div>
</template>
