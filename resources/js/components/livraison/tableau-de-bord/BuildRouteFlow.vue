<!-- resources/js/components/livraison/tableau-de-bord/BuildRouteFlow.vue -->
<!--
    "Construire une tournée personnalisée" — remplace les trois inputs
    numériques bruts (ID bénévole / ID véhicule / liste d'IDs livraisons
    séparés par des virgules) par un vrai flux guidé : PersonPicker +
    VehiculePicker + sélection depuis la liste non-couvertes déjà à
    l'écran (voir ShortfallPanel.vue, même source de données) plutôt que
    d'obliger l'admin à connaître des ids à l'avance.
-->
<script setup lang="ts">
import { ref, reactive } from 'vue';
import { useToast } from '@amana/shared-ui';
import { apiPost } from '../shared/api';
import PersonPicker from '../shared/PersonPicker.vue';
import VehiculePicker from '../shared/VehiculePicker.vue';
import { CRENEAUX, CRENEAU_LIBELLES, type Livraison, type PersonneResume } from '../shared/types';

const props = defineProps<{
    campagneId: string;
    nonCouvertes: Livraison[];
    url: string;
}>();

const emit = defineEmits<{ created: [] }>();

const toast = useToast();

const benevole = ref<PersonneResume | null>(null);
const idVehicule = ref<number | null>(null);
const idsLivraisons = reactive<Set<number>>(new Set());
const creneau = ref('');
const envoiEnCours = ref(false);
const erreurs = ref<Record<string, string[]>>({});

function toggleLivraison(id: number) {
    if (idsLivraisons.has(id)) idsLivraisons.delete(id);
    else idsLivraisons.add(id);
}

async function construire() {
    erreurs.value = {};

    if (!benevole.value || !idVehicule.value || idsLivraisons.size === 0) {
        toast.error('Choisissez un bénévole, un véhicule et au moins une livraison.');
        return;
    }

    envoiEnCours.value = true;
    const resultat = await apiPost<{ success: boolean }>(props.url, {
        id_benevole: benevole.value.id,
        id_vehicule_type: idVehicule.value,
        ids_livraisons: [...idsLivraisons],
        creneau: creneau.value || null,
    });
    envoiEnCours.value = false;

    if (!resultat.ok) {
        erreurs.value = resultat.errors;
        if (Object.keys(resultat.errors).length === 0) toast.error(resultat.message);
        return;
    }

    toast.success('Tournée créée.');
    benevole.value = null;
    idVehicule.value = null;
    idsLivraisons.clear();
    creneau.value = '';
    emit('created');
}
</script>

<template>
    <div class="mb-8 bg-surface border border-surface-border rounded-xl p-5">
        <h2 class="text-[14px] font-medium text-ink mb-4">Construire une tournée personnalisée</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Bénévole</label>
                <PersonPicker role="benevole" placeholder="Rechercher un bénévole…" v-model="benevole" />
            </div>
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Créneau (optionnel)</label>
                <select v-model="creneau" class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]">
                    <option value="">— Aucun —</option>
                    <option v-for="c in CRENEAUX" :key="c" :value="c">{{ CRENEAU_LIBELLES[c] }}</option>
                </select>
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-[12px] text-ink-muted mb-1.5">Véhicule</label>
            <VehiculePicker v-model="idVehicule" />
        </div>

        <div class="mb-4">
            <label class="block text-[12px] text-ink-muted mb-1.5">
                Livraisons à inclure ({{ idsLivraisons.size }} sélectionnée{{ idsLivraisons.size > 1 ? 's' : '' }})
            </label>
            <div v-if="nonCouvertes.length === 0" class="text-[12.5px] text-ink-muted">
                Aucune livraison non couverte pour cette campagne actuellement.
            </div>
            <div v-else class="max-h-48 overflow-y-auto border border-surface-border rounded-lg divide-y divide-surface-border">
                <label v-for="l in nonCouvertes" :key="l.id"
                    class="flex items-center gap-2 px-3 py-2 text-[13px] text-ink cursor-pointer select-none min-h-[2.5rem]">
                    <input type="checkbox" :checked="idsLivraisons.has(l.id)" @change="toggleLivraison(l.id)" class="w-4 h-4 accent-accent shrink-0">
                    {{ l.famille.prenom }} {{ l.famille.nom }} — {{ l.famille.adresse }}
                </label>
            </div>
            <p v-for="e in erreurs.ids_livraisons ?? []" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
        </div>

        <button type="button" :disabled="envoiEnCours" @click="construire"
            class="min-h-[2.5rem] text-[13px] px-4 py-2 rounded-lg bg-accent text-white disabled:opacity-60">
            {{ envoiEnCours ? 'Création…' : 'Créer la tournée' }}
        </button>
    </div>
</template>
