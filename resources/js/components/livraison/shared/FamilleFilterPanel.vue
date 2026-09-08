<!-- resources/js/components/livraison/shared/FamilleFilterPanel.vue -->
<!--
    Panneau de filtres partagé — voir le prompt du 05/09/2026 §1.6/§2.6
    ("same filter panel as Dossier Familles"). Reflète exactement les
    filtres reconnus par App\Support\FamilleFilters (extrait de
    FamillesController::baseQuery() ce même jour), utilisé à la fois par
    CampagneDetail.vue (sélection éligibilité) et ContactsQueue.vue.

    Regroupé par thème + libellés (révisé le 05/09/2026, même prompt,
    correction ultérieure) — même structure visuelle que
    familles/index.blade.php : groupes "📍 Localisation" / "🏢
    Organisation" / "🎚️ Criticité" / "Caractéristiques", chaque champ
    avec son propre <label> au-dessus (text-[10.5px] font-semibold
    text-ink-muted), pas juste des <select>/checkbox nus.

    Volontairement plus simple que familles/index.blade.php sur un point :
    pas d'autocomplétion nom/téléphone avec id_selection (suggestions
    serveur) — un champ "recherche" libre suffit ici, l'autocomplétion
    dédiée reste propre à Dossier Familles.
-->
<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import type { FamilleFiltres, Organisation, Quartier, Secteur, Ville } from './types';

const props = defineProps<{
    villes: Ville[];
    secteurs: Secteur[];
    quartiers: Quartier[];
    organisations: Organisation[];
    modelValue: FamilleFiltres;
}>();

const emit = defineEmits<{
    'update:modelValue': [FamilleFiltres];
    filtrer: [];
}>();

const filtres = reactive<FamilleFiltres>({ ...props.modelValue });

watch(filtres, () => emit('update:modelValue', { ...filtres }), { deep: true });

const CRITICITES = [0, 1, 2, 3, 4, 5];
const GROUPE_LABEL = 'text-[10px] font-bold text-ink-muted uppercase tracking-wide mb-2';
const CHAMP_LABEL = 'block text-[10.5px] font-semibold text-ink-muted mb-1';
const CHIP_LABEL = 'flex items-center gap-2 px-3 py-2 border border-ink-faint rounded-md text-[12.5px] text-ink-muted cursor-pointer select-none transition-colors has-[:checked]:border-accent has-[:checked]:bg-accent/5 has-[:checked]:text-ink has-[:checked]:font-semibold';

function toggleCriticite(valeur: number) {
    const courant = filtres.criticite ?? [];
    filtres.criticite = courant.includes(valeur)
        ? courant.filter((v) => v !== valeur)
        : [...courant, valeur];
}

function secteursFiltres() {
    return filtres.id_ville ? props.secteurs.filter((s) => s.id_ville === filtres.id_ville) : props.secteurs;
}

function quartiersFiltres() {
    if (!filtres.id_secteur) return props.quartiers;
    return props.quartiers.filter((q) => q.id_secteur === filtres.id_secteur);
}

function reinitialiser() {
    Object.assign(filtres, {
        id_ville: '', id_secteur: '', id_quartier: '', criticite: [],
        se_deplace: false, est_hotel: false, etudiant: false,
        zakat_el_fitr: false, sadaqa: false,
        id_organisation_origine: '', id_organisation_rattachee: '', recherche: '',
    });
    emit('filtrer');
}

// Repliable (08/09/2026, prompt §2.2.3/§3.4 : "like in dossier famille")
// — même pattern que familles/index.blade.php : <details>/<summary>
// natif plutôt qu'un ref + v-show, pour bénéficier gratuitement du même
// comportement (contenu simplement masqué, pas démonté — les v-model
// des champs restent actifs même repliés) sans dupliquer de logique JS.
// Badge "actifs" affiché même repliée, pour ne pas cacher silencieusement
// qu'un filtre est appliqué.
const filtresActifs = computed(() => Boolean(
    filtres.recherche || filtres.id_ville || filtres.id_secteur || filtres.id_quartier
    || (filtres.criticite ?? []).length > 0
    || filtres.se_deplace || filtres.est_hotel || filtres.etudiant
    || filtres.zakat_el_fitr || filtres.sadaqa
    || filtres.id_organisation_origine || filtres.id_organisation_rattachee,
));
</script>

<template>
    <details class="group bg-surface border border-surface-border rounded-xl p-4 mb-4" open>
        <summary class="cursor-pointer list-none flex items-center justify-between select-none -mx-1 -my-1 px-1 py-1 mb-2 rounded-lg hover:bg-surface-2 transition-colors">
            <span class="text-[13px] font-bold text-ink flex items-center gap-1.5">
                🔎 Filtres
                <span v-if="filtresActifs" class="px-1.5 py-0.5 rounded-full bg-accent/10 text-accent-dark text-[10px] font-bold">actifs</span>
            </span>
            <span class="text-ink-muted text-[13px] transition-transform duration-200 group-open:rotate-180">▾</span>
        </summary>

        <div class="mb-4">
            <label :class="CHAMP_LABEL">🔎 Recherche (nom, téléphone…)</label>
            <input v-model="filtres.recherche" type="text" placeholder="Rechercher…"
                class="w-full sm:w-96 rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
        </div>

        <div class="mb-4">
            <div :class="GROUPE_LABEL">📍 Localisation</div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label :class="CHAMP_LABEL">🏙️ Ville</label>
                    <select v-model="filtres.id_ville" @change="filtres.id_secteur = ''; filtres.id_quartier = ''"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                        <option value="">Toutes</option>
                        <option v-for="v in villes" :key="v.id" :value="v.id">{{ v.nom }}</option>
                    </select>
                </div>
                <div>
                    <label :class="CHAMP_LABEL">Secteur</label>
                    <select v-model="filtres.id_secteur" @change="filtres.id_quartier = ''"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                        <option value="">Tous</option>
                        <option v-for="s in secteursFiltres()" :key="s.id" :value="s.id">{{ s.nom }}</option>
                    </select>
                </div>
                <div>
                    <label :class="CHAMP_LABEL">📌 Quartier</label>
                    <select v-model="filtres.id_quartier"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                        <option value="">Tous</option>
                        <option v-for="q in quartiersFiltres()" :key="q.id" :value="q.id">{{ q.nom }}</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <div :class="GROUPE_LABEL">🏢 Organisation</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label :class="CHAMP_LABEL">D'origine</label>
                    <select v-model="filtres.id_organisation_origine"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                        <option value="">Toutes</option>
                        <option v-for="o in organisations" :key="o.id" :value="o.id">{{ o.nom }}</option>
                    </select>
                </div>
                <div>
                    <label :class="CHAMP_LABEL">🔗 Rattachée</label>
                    <select v-model="filtres.id_organisation_rattachee"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                        <option value="">Toutes</option>
                        <option v-for="o in organisations" :key="o.id" :value="o.id">{{ o.nom }}</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <label :class="[CHAMP_LABEL, 'mb-1.5']">🎚️ Criticité</label>
            <div class="flex items-center gap-1.5">
                <label v-for="c in CRITICITES" :key="c"
                    class="flex items-center justify-center w-7 h-7 rounded-full border text-[12px] cursor-pointer select-none"
                    :class="(filtres.criticite ?? []).includes(c) ? 'border-accent bg-accent/10 text-ink font-semibold' : 'border-surface-border text-ink-muted'">
                    <input type="checkbox" class="sr-only" :checked="(filtres.criticite ?? []).includes(c)" @change="toggleCriticite(c)">
                    {{ c }}
                </label>
            </div>
        </div>

        <div class="mb-4">
            <label :class="[CHAMP_LABEL, 'mb-1.5']">Caractéristiques</label>
            <div class="flex flex-wrap gap-2">
                <label :class="CHIP_LABEL">
                    <input type="checkbox" v-model="filtres.se_deplace" class="w-3.5 h-3.5 accent-accent"> Se déplace
                </label>
                <label :class="CHIP_LABEL">
                    <input type="checkbox" v-model="filtres.est_hotel" class="w-3.5 h-3.5 accent-accent"> Hôtel
                </label>
                <label :class="CHIP_LABEL">
                    <input type="checkbox" v-model="filtres.etudiant" class="w-3.5 h-3.5 accent-accent"> Étudiant
                </label>
                <label :class="CHIP_LABEL">
                    <input type="checkbox" v-model="filtres.zakat_el_fitr" class="w-3.5 h-3.5 accent-accent"> Zakat el-fitr
                </label>
                <label :class="CHIP_LABEL">
                    <input type="checkbox" v-model="filtres.sadaqa" class="w-3.5 h-3.5 accent-accent"> Sadaqa
                </label>
            </div>
        </div>

        <div class="flex gap-2">
            <button type="button" @click="emit('filtrer')"
                class="min-h-[2.25rem] text-[13px] px-4 py-1.5 rounded-lg bg-accent text-white">
                Filtrer
            </button>
            <button type="button" @click="reinitialiser"
                class="min-h-[2.25rem] text-[13px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted">
                Réinitialiser
            </button>
        </div>
    </details>
</template>
