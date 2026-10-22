<!-- resources/js/components/admin/SettingsTabs.vue -->
<!--
    Onglets de l'écran Paramètres. Refonte d'octobre 2026 : composant CONTRÔLÉ (v-model) — l'onglet
    actif vit dans Settings/Index.vue, qui affiche/masque ses panneaux avec v-show. L'ancien pont DOM
    (querySelectorAll('[data-settings-tab]') + style.display posé dans onMounted) ne survivait pas à un
    nouveau rendu de la page, et l'onglet repartait sur « Général » après chaque enregistrement.
-->
<script setup lang="ts">
interface OngletParametres {
    id: string;
    label: string;
    icon: string;
}

const ONGLETS_PARAMETRES: OngletParametres[] = [
    { id: "general", label: "Général", icon: "⚙️" },
    { id: "itineraires", label: "Itinéraires", icon: "🗺️" },
    { id: "vehicules", label: "Véhicules", icon: "🚗" },
    { id: "organisations", label: "Organisations", icon: "🤝" },
    { id: "hotels", label: "Adresses hôtel", icon: "🏨" },
];

defineProps<{ modelValue: string }>();
const emit = defineEmits<{ "update:modelValue": [id: string] }>();
</script>

<template>
    <div class="flex flex-wrap gap-1 border-b border-surface-3 mb-6" role="tablist">
        <button
            v-for="tab in ONGLETS_PARAMETRES"
            :key="tab.id"
            type="button"
            role="tab"
            :aria-selected="modelValue === tab.id"
            @click="emit('update:modelValue', tab.id)"
            :class="
                modelValue === tab.id ? 'border-accent text-accent' : 'border-transparent text-ink-muted hover:text-ink'
            "
            class="px-3.5 py-2.5 text-sm font-semibold border-b-2 transition-colors cursor-pointer flex items-center gap-1.5 min-h-[44px]"
        >
            <span aria-hidden="true">{{ tab.icon }}</span>
            {{ tab.label }}
        </button>
    </div>
</template>
