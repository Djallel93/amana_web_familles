<!-- resources/js/components/admin/HotelAddressAutocomplete.vue -->
<!--
    Widget d'appoint pour le formulaire "Ajouter une adresse hôtel" de
    l'écran Paramètres (resources/views/settings/index.blade.php) — ajouté
    le 30/08/2026. Ne rend PAS le champ texte manuel (qui reste un <input>
    Blade classique, name="adresse", pour rester un formulaire POST
    standard sans dépendance JS) : ce composant expose seulement un bouton
    "Rechercher via Google Maps" qui affiche le widget d'autocomplétion et
    écrit le libellé choisi dans ce champ externe via son id — même pont
    DOM que MobileSidebar.vue/DetailPanel.vue (composant Vue isolé piloté
    par data-*, pas de props/emit avec le reste de la page Blade).

    Contrairement à DetailPanel.vue/IntakeForm.vue (qui reconstruisent
    l'adresse à partir de addressComponents pour séparer rue/code postal/
    ville), ici on veut le libellé COMPLET tel qu'affiché par Google,
    éventuellement préfixé du nom de l'établissement pour les lieux (POI)
    comme un hôtel — c'est justement ce format que le référentiel
    hotel_addresses est censé pouvoir contenir (voir migration
    create_hotel_addresses_table) : formattedAddress seul pour une adresse
    de rue, "Nom de l'établissement, formattedAddress" quand Google
    identifie un lieu nommé (displayName distinct de l'adresse elle-même).
-->
<script setup lang="ts">
import { ref, nextTick, onMounted } from 'vue';

declare global {
    interface Window {
        google: any;
        __googleMapsLoadPromise?: Promise<void>;
    }
}

const googlePlacesKey = ref('');
const targetInputId = ref('');
const showSearch = ref(false);
const containerRef = ref<HTMLDivElement | null>(null);
let autocompleteElement: any = null;

function loadGoogleMapsScript(apiKey: string): Promise<void> {
    if (window.__googleMapsLoadPromise) return window.__googleMapsLoadPromise;

    window.__googleMapsLoadPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&v=weekly&language=fr`;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('google_maps_load_failed'));
        document.head.appendChild(script);
    });

    return window.__googleMapsLoadPromise;
}

async function initAutocomplete(): Promise<void> {
    if (!googlePlacesKey.value || !containerRef.value || autocompleteElement) return;

    try {
        await loadGoogleMapsScript(googlePlacesKey.value);
        const { PlaceAutocompleteElement } = await window.google.maps.importLibrary('places');

        autocompleteElement = new PlaceAutocompleteElement({
            includedRegionCodes: ['fr'],
            requestedLanguage: 'fr',
        });
        // Même correctif thème sombre que DetailPanel.vue/IntakeForm.vue —
        // voir commentaire détaillé là-bas.
        autocompleteElement.style.width = '100%';
        autocompleteElement.style.setProperty('color-scheme', 'light');
        autocompleteElement.style.setProperty('background-color', '#ffffff');
        autocompleteElement.style.setProperty('border', '1px solid #d6d3d1');
        autocompleteElement.style.setProperty('border-radius', '6px');
        containerRef.value.appendChild(autocompleteElement);

        autocompleteElement.addEventListener('gmp-select', async ({ placePrediction }: any) => {
            const place = placePrediction.toPlace();
            await place.fetchFields({ fields: ['formattedAddress', 'displayName', 'types'] });

            // "establishment" (ou une sous-catégorie de lieu, ex. "lodging")
            // dans place.types signale un POI nommé plutôt qu'une simple
            // adresse de rue — dans ce cas seulement, on préfixe le nom de
            // l'établissement, pour ne pas obtenir "12 Rue X, 12 Rue X" sur
            // une adresse de rue ordinaire (displayName vaut alors la rue
            // elle-même côté API Google).
            const estUnLieu = (place.types ?? []).includes('establishment');
            const libelle = estUnLieu && place.displayName
                ? `${place.displayName}, ${place.formattedAddress}`
                : place.formattedAddress;

            const cible = document.getElementById(targetInputId.value) as HTMLInputElement | null;
            if (cible && libelle) {
                cible.value = libelle;
                cible.dispatchEvent(new Event('input', { bubbles: true }));
                cible.focus();
            }

            showSearch.value = false;
            autocompleteElement = null;
        });
    } catch {
        showSearch.value = false;
    }
}

async function ouvrirRecherche(): Promise<void> {
    showSearch.value = true;
    autocompleteElement = null;
    await nextTick();
    await initAutocomplete();
}

onMounted(() => {
    const el = document.getElementById('vue-hotel-address-autocomplete');
    if (el) {
        googlePlacesKey.value = el.dataset.googlePlacesKey ?? '';
        targetInputId.value = el.dataset.targetInputId ?? '';
    }
});
</script>

<template>
    <div>
        <button type="button" @click="ouvrirRecherche" v-if="!showSearch"
            class="text-[11px] text-accent hover:text-accent-dark font-semibold transition-colors cursor-pointer bg-transparent border-0 p-0">
            🔍 Rechercher via Google Maps
        </button>
        <div v-show="showSearch" ref="containerRef" class="mt-1.5"></div>
    </div>
</template>
