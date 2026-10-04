<!-- resources/js/components/intake/OrganisationSelect.vue -->
<!--
    Liste déroulante d'organisation du formulaire public (01/10/2026, prompt
    de cette date §4.1 : « organisation drop down moche »). Remplace le
    <select> natif — rendu différent selon le navigateur, impossible à
    chercher — par une combobox stylée : champ déclencheur, panneau avec
    recherche (sans accents ni casse) et liste à défiler.

    Volontairement LOCALE à amana_web_familles (décision du 01/10/2026) :
    pas de promotion dans amana_shared_ui tant qu'un second écran n'en a pas
    besoin. Indépendante de ce paquet pour le style : le formulaire public
    porte son propre habillage et s'affiche aussi en arabe (dir="rtl") — d'où
    les classes logiques (ms-/ps-/text-start/start-0) plutôt que gauche/droite.

    Accessibilité : motif combobox/listbox ARIA, navigation clavier (↑ ↓
    Entrée Échap), fermeture au clic extérieur.
-->
<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";

interface Option {
    id: number;
    nom: string;
}

const props = withDefaults(
    defineProps<{
        modelValue: number | null;
        options: Option[];
        placeholder?: string;
        searchPlaceholder?: string;
        emptyMessage?: string;
        invalid?: boolean;
    }>(),
    {
        placeholder: "Sélectionner…",
        searchPlaceholder: "Rechercher…",
        emptyMessage: "Aucun résultat",
        invalid: false,
    },
);

const emit = defineEmits<{ "update:modelValue": [value: number] }>();

const racine = ref<HTMLElement | null>(null);
const champRecherche = ref<HTMLInputElement | null>(null);
const ouvert = ref(false);
const recherche = ref("");
const actif = ref(0);

function normaliser(texte: string): string {
    return texte
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .toLowerCase();
}

const selection = computed(() => props.options.find((o) => o.id === props.modelValue) ?? null);

const filtrees = computed(() => {
    const terme = normaliser(recherche.value.trim());
    return terme === "" ? props.options : props.options.filter((o) => normaliser(o.nom).includes(terme));
});

watch(filtrees, () => {
    actif.value = 0;
});

async function ouvrir() {
    ouvert.value = true;
    recherche.value = "";
    // Place la ligne active sur la sélection courante pour que ↑↓ parte de là.
    actif.value = Math.max(
        0,
        props.options.findIndex((o) => o.id === props.modelValue),
    );
    await nextTick();
    champRecherche.value?.focus();
}

function fermer() {
    ouvert.value = false;
}

function basculer() {
    if (ouvert.value) fermer();
    else void ouvrir();
}

function choisir(option: Option) {
    emit("update:modelValue", option.id);
    fermer();
}

function onClavier(e: KeyboardEvent) {
    if (e.key === "ArrowDown") {
        e.preventDefault();
        actif.value = Math.min(actif.value + 1, filtrees.value.length - 1);
    } else if (e.key === "ArrowUp") {
        e.preventDefault();
        actif.value = Math.max(actif.value - 1, 0);
    } else if (e.key === "Enter") {
        e.preventDefault();
        const option = filtrees.value[actif.value];
        if (option) choisir(option);
    } else if (e.key === "Escape") {
        fermer();
    }
}

function surClicExterieur(e: MouseEvent) {
    if (ouvert.value && racine.value && !racine.value.contains(e.target as Node)) fermer();
}

onMounted(() => document.addEventListener("mousedown", surClicExterieur));
onBeforeUnmount(() => document.removeEventListener("mousedown", surClicExterieur));
</script>

<template>
    <div ref="racine" class="relative">
        <button
            type="button"
            role="combobox"
            :aria-expanded="ouvert"
            aria-haspopup="listbox"
            class="w-full flex items-center justify-between gap-2 px-3 py-2.5 border rounded-md text-[14px] bg-surface-2 text-start outline-none transition-colors focus:border-accent"
            :class="[invalid ? 'border-rose-400' : ouvert ? 'border-accent' : 'border-ink-faint']"
            @click="basculer"
        >
            <span class="truncate" :class="selection ? 'text-ink font-medium' : 'text-ink-muted'">
                {{ selection ? selection.nom : placeholder }}
            </span>
            <span
                class="shrink-0 text-ink-muted text-[12px] transition-transform duration-200"
                :class="ouvert ? 'rotate-180' : ''"
                aria-hidden="true"
                >▾</span
            >
        </button>

        <div
            v-if="ouvert"
            class="absolute z-30 mt-1 w-full bg-surface border border-ink-faint rounded-lg shadow-lg overflow-hidden"
        >
            <div class="p-2 border-b border-ink-faint">
                <input
                    ref="champRecherche"
                    v-model="recherche"
                    type="text"
                    :placeholder="searchPlaceholder"
                    autocomplete="off"
                    aria-label="Rechercher"
                    class="w-full px-2.5 py-1.5 border border-ink-faint rounded-md text-[13px] bg-surface-2 outline-none focus:border-accent"
                    @keydown="onClavier"
                />
            </div>
            <ul role="listbox" class="max-h-56 overflow-y-auto py-1">
                <li
                    v-for="(option, index) in filtrees"
                    :key="option.id"
                    role="option"
                    :aria-selected="option.id === modelValue"
                    class="flex items-center justify-between gap-2 px-3 py-2 text-[13.5px] cursor-pointer text-start"
                    :class="[
                        index === actif ? 'bg-accent/10' : '',
                        option.id === modelValue ? 'font-semibold text-accent-dark' : 'text-ink',
                    ]"
                    @mouseenter="actif = index"
                    @click="choisir(option)"
                >
                    <span class="truncate">{{ option.nom }}</span>
                    <span v-if="option.id === modelValue" aria-hidden="true">✓</span>
                </li>
                <li v-if="filtrees.length === 0" class="px-3 py-3 text-[12.5px] text-ink-muted text-center">
                    {{ emptyMessage }}
                </li>
            </ul>
        </div>
    </div>
</template>
