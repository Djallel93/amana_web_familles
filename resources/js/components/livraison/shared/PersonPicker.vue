<!-- resources/js/components/livraison/shared/PersonPicker.vue -->
<!--
    Picker de recherche personne — remplace les inputs "ID gestionnaire" /
    "ID bénévole" de la version placeholder (voir contacts.blade.php et
    tableau-de-bord.blade.php d'origine) par une vraie recherche nom/
    prénom, sur GET /livraison/personnes/recherche (voir
    Admin\Livraison\PickersController::personnes()).

    Usage : composant "inline" (pas de <Modal> ici) qui s'ouvre en
    dropdown sous un champ de recherche — plus léger qu'une modale pour un
    picker utilisé plusieurs fois par écran (chaque ligne de la file de
    contact, chaque tournée du tableau de bord). Les flux qui l'utilisent
    depuis une modale de confirmation (BuildRouteFlow.vue) l'enveloppent
    dans leur propre <Modal>.

    Mobile : le dropdown est en position absolute avec max-h + overflow-y
    plutôt qu'une largeur fixe, pour rester utilisable dans les cartes
    étroites de la file de contact sur petit écran ; chaque résultat a une
    hauteur tactile ≥40px (py-2.5 sur un texte 13px).
-->
<script setup lang="ts">
import { ref, watch, onBeforeUnmount } from 'vue';
import { apiGet, buildQuery } from './api';
import type { PersonneResume } from './types';

const props = defineProps<{
    /** Filtre de rôle minimum, ex. 'benevole' ou 'gestionnaire' (voir Personne::hasAtLeastRole()). */
    role?: string;
    placeholder?: string;
    /** Personne déjà sélectionnée (affichage initial), le cas échéant. */
    modelValue?: PersonneResume | null;
}>();

const emit = defineEmits<{
    'update:modelValue': [personne: PersonneResume | null];
}>();

const terme = ref('');
const resultats = ref<PersonneResume[]>([]);
const ouvert = ref(false);
const chargement = ref(false);
const erreur = ref(false);
let debounceId: ReturnType<typeof setTimeout> | undefined;

watch(terme, (valeur) => {
    if (debounceId) clearTimeout(debounceId);
    if (valeur.trim().length < 2) {
        resultats.value = [];
        ouvert.value = false;
        return;
    }
    debounceId = setTimeout(() => rechercher(valeur), 300);
});

async function rechercher(valeur: string) {
    chargement.value = true;
    erreur.value = false;
    const url = '/livraison/personnes/recherche' + buildQuery({ q: valeur, role: props.role });
    const resultat = await apiGet<PersonneResume[]>(url);
    chargement.value = false;

    if (!resultat.ok) {
        erreur.value = true;
        resultats.value = [];
        return;
    }

    resultats.value = resultat.data;
    ouvert.value = true;
}

function choisir(personne: PersonneResume) {
    emit('update:modelValue', personne);
    terme.value = '';
    resultats.value = [];
    ouvert.value = false;
}

function effacerSelection() {
    emit('update:modelValue', null);
}

onBeforeUnmount(() => {
    if (debounceId) clearTimeout(debounceId);
});
</script>

<template>
    <div class="relative">
        <div v-if="modelValue" class="flex items-center justify-between gap-2 px-3 py-2 border border-accent bg-accent/5 rounded-lg text-[13px]">
            <span class="text-ink font-medium truncate">{{ modelValue.prenom }} {{ modelValue.nom }}</span>
            <button type="button" @click="effacerSelection"
                class="text-ink-muted hover:text-ink text-[12px] shrink-0 min-h-[2rem] px-2"
                aria-label="Changer de personne">
                Changer
            </button>
        </div>

        <input v-else v-model="terme" type="text" :placeholder="placeholder ?? 'Rechercher par nom…'"
            class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]"
            @focus="() => { if (resultats.length) ouvert = true; }">

        <div v-if="ouvert && !modelValue"
            class="absolute z-10 mt-1 w-full max-h-56 overflow-y-auto bg-surface border border-surface-border rounded-lg shadow-md">
            <p v-if="chargement" class="px-3 py-2.5 text-[12.5px] text-ink-muted">Recherche…</p>
            <p v-else-if="erreur" class="px-3 py-2.5 text-[12.5px] text-rose-600">Recherche indisponible, réessayez.</p>
            <p v-else-if="resultats.length === 0" class="px-3 py-2.5 text-[12.5px] text-ink-muted">Aucun résultat.</p>
            <button v-for="personne in resultats" :key="personne.id" type="button" @click="choisir(personne)"
                class="block w-full text-left px-3 py-2.5 text-[13px] text-ink hover:bg-surface-2 transition-colors">
                {{ personne.prenom }} {{ personne.nom }}
            </button>
        </div>
    </div>
</template>
