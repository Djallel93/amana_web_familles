<!-- resources/js/components/admin/SettingsTabs.vue -->
<!--
    Onglets de l'écran Paramètres (resources/views/settings/index.blade.php)
    — ajouté le 05/09/2026, la page étant jugée trop chargée/trop de scroll
    une fois les sections Véhicules/Organisations/Adresses hôtel empilées
    sous les réglages génériques.

    Reste volontairement une simple couche d'affichage : chaque section de
    la page (Général, Itinéraires, Véhicules, Organisations, Adresses
    hôtel) continue d'exister comme du Blade classique avec ses propres
    <form> POST — ce composant ne fait que montrer/cacher les blocs marqués
    data-settings-tab="..." en dehors de son propre template, même pont DOM
    piloté par data-* que HotelAddressAutocomplete.vue (pas de
    props/emit avec le reste de la page, la page n'étant pas une SPA Vue :
    convertir tout l'écran en SPA aurait forcé à réécrire chaque
    contrôleur CRUD en JSON, pour un gain nul ici — les onglets n'ont
    besoin d'aucun état partagé entre sections).

    Onglet par défaut : calculé côté client depuis la prop 'errors'
    (partagée par Inertia, voir SettingsController::index() et
    HandleInertiaRequests::share()) plutôt que toujours "general" — si une
    soumission échoue la validation (ex. organisation en double), on
    rouvre l'onglet où l'erreur se trouve au lieu de la cacher derrière un
    autre onglet.

    Section E4 du refactor (16/09/2026, chunk settings) : ce composant
    n'est plus un îlot monté par app.ts sur #vue-settings-tabs, mais un
    enfant normal de resources/js/pages/Settings/Index.vue, qui calcule
    désormais lui-même l'onglet par défaut (même logique de préfixes que
    l'ancien @php de la Blade, portée en TypeScript) et la passe en prop
    plutôt qu'en data-default-tab. Le pont DOM data-settings-tab lui-même
    (panneaux()/appliquerVisibilite() ci-dessous) reste inchangé : ces
    blocs sont désormais rendus par Vue plutôt que par Blade, mais restent
    de vrais éléments DOM que document.querySelectorAll() trouve de la
    même façon quelle que soit leur origine.
-->
<script setup lang="ts">
import { ref, onMounted } from 'vue';

interface TabDef {
    id: string;
    label: string;
    icon: string;
}

const TABS: TabDef[] = [
    { id: 'general', label: 'Général', icon: '⚙️' },
    { id: 'itineraires', label: 'Itinéraires', icon: '🗺️' },
    { id: 'vehicules', label: 'Véhicules', icon: '🚗' },
    { id: 'organisations', label: 'Organisations', icon: '🤝' },
    { id: 'hotels', label: 'Adresses hôtel', icon: '🏨' },
];

const props = defineProps<{
    defaultTab: string;
}>();

const activeTab = ref<string>(
    TABS.some((tab) => tab.id === props.defaultTab) ? props.defaultTab : TABS[0].id,
);

function panneaux(): NodeListOf<HTMLElement> {
    return document.querySelectorAll<HTMLElement>('[data-settings-tab]');
}

function appliquerVisibilite(): void {
    panneaux().forEach((panneau) => {
        panneau.style.display = panneau.dataset.settingsTab === activeTab.value ? '' : 'none';
    });
}

function selectionner(id: string): void {
    activeTab.value = id;
    appliquerVisibilite();
}

onMounted(() => {
    appliquerVisibilite();
});
</script>

<template>
    <div class="flex flex-wrap gap-1 border-b border-surface-3 mb-6" role="tablist">
        <button v-for="tab in TABS" :key="tab.id" type="button" role="tab"
            :aria-selected="activeTab === tab.id"
            @click="selectionner(tab.id)"
            :class="activeTab === tab.id
                ? 'border-accent text-accent'
                : 'border-transparent text-ink-muted hover:text-ink'"
            class="px-3.5 py-2.5 text-sm font-semibold border-b-2 transition-colors cursor-pointer flex items-center gap-1.5 min-h-[44px]">
            <span aria-hidden="true">{{ tab.icon }}</span>
            {{ tab.label }}
        </button>
    </div>
</template>
