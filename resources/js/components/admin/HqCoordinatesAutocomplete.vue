<!-- resources/js/components/admin/HqCoordinatesAutocomplete.vue -->
<!--
    Widget de la section "HQ par défaut" de l'écran Paramètres
    (resources/views/settings/index.blade.php) — ajouté le 05/09/2026,
    remplace les deux champs texte latitude/longitude bruts de l'ancienne
    boucle générique de réglages.

    Contrairement à HotelAddressAutocomplete.vue (qui écrit un LIBELLÉ
    d'adresse dans un champ texte), on ne veut PAS conserver l'adresse
    recherchée : seules les coordonnées comptent pour
    App\Support\RouteOptimizationConfig::coordonneesHq(), l'autocomplétion
    n'est qu'un moyen pratique de les obtenir. On demande donc le champ
    `location` (pas `formattedAddress`) via fetchFields() — c'est la
    coordonnée que Google associe déjà au lieu sélectionné dans son propre
    référentiel Places, la même autorité que si on rappelait
    GoogleGeocodingService sur l'adresse obtenue, mais sans un second
    aller-retour HTTP puisque les détails du lieu sont déjà en main après
    la sélection dans la liste déroulante.

    Les deux <input type="number"> cibles (id passés en data-*) restent
    TOUJOURS visibles et pré-remplis avec les valeurs actuelles (pour
    qu'elles soient visibles à la réouverture de l'écran, même sans
    JavaScript) et en lecture seule par défaut — la recherche Google Maps
    est le moyen premier de les renseigner. Le bouton "Saisir manuellement"
    ne fait que retirer l'attribut readonly, sans dupliquer les champs.
    Généralisé le 05/09/2026 (prompt de cette date §1.1) pour être aussi
    utilisable directement comme composant enfant (voir CampagneDetail.vue,
    HQ propre à une campagne) — props optionnelles qui, si fournies,
    priment sur la détection via #vue-hq-coordinates-autocomplete
    (comportement historique de l'écran Paramètres, inchangé si les props
    ne sont pas passées).
-->
<script setup lang="ts">
import { ref, nextTick, onMounted } from 'vue';

declare global {
    interface Window {
        google: any;
        __googleMapsLoadPromise?: Promise<void>;
    }
}

const props = defineProps<{
    googlePlacesKey?: string;
    targetLatId?: string;
    targetLngId?: string;
}>();

const googlePlacesKey = ref(props.googlePlacesKey ?? '');
const targetLatId = ref(props.targetLatId ?? '');
const targetLngId = ref(props.targetLngId ?? '');
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

function ecrireDansChamp(id: string, valeur: number): void {
    const cible = document.getElementById(id) as HTMLInputElement | null;
    if (!cible) return;

    // 6 décimales ≈ 11cm de précision, largement suffisant pour un point de
    // départ de tournée et plus lisible qu'un flottant JS brut à 15 chiffres.
    cible.value = valeur.toFixed(6);
    cible.dispatchEvent(new Event('input', { bubbles: true }));
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
        // Même correctif thème sombre que HotelAddressAutocomplete.vue/
        // DetailPanel.vue — voir commentaire détaillé là-bas.
        autocompleteElement.style.width = '100%';
        autocompleteElement.style.setProperty('color-scheme', 'light');
        autocompleteElement.style.setProperty('background-color', '#ffffff');
        autocompleteElement.style.setProperty('border', '1px solid #d6d3d1');
        autocompleteElement.style.setProperty('border-radius', '6px');
        containerRef.value.appendChild(autocompleteElement);

        autocompleteElement.addEventListener('gmp-select', async ({ placePrediction }: any) => {
            const place = placePrediction.toPlace();
            await place.fetchFields({ fields: ['location'] });

            if (place.location) {
                ecrireDansChamp(targetLatId.value, place.location.lat());
                ecrireDansChamp(targetLngId.value, place.location.lng());
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

function activerSaisieManuelle(): void {
    [targetLatId.value, targetLngId.value].forEach((id) => {
        const champ = document.getElementById(id) as HTMLInputElement | null;
        if (champ) {
            champ.readOnly = false;
            champ.classList.remove('bg-surface-2', 'text-ink-muted');
        }
    });
    document.getElementById(targetLatId.value)?.focus();
}

onMounted(() => {
    if (props.googlePlacesKey || props.targetLatId || props.targetLngId) return; // fourni par les props, rien à détecter

    const el = document.getElementById('vue-hq-coordinates-autocomplete');
    if (el) {
        googlePlacesKey.value = el.dataset.googlePlacesKey ?? '';
        targetLatId.value = el.dataset.targetLatId ?? '';
        targetLngId.value = el.dataset.targetLngId ?? '';
    }
});
</script>

<template>
    <div>
        <button type="button" @click="ouvrirRecherche" v-if="!showSearch"
            class="text-[11px] text-accent hover:text-accent-dark font-semibold transition-colors cursor-pointer bg-transparent border-0 p-0">
            🔍 Rechercher l'adresse du QG via Google Maps
        </button>
        <div v-show="showSearch" ref="containerRef" class="mt-1.5"></div>

        <button type="button" @click="activerSaisieManuelle"
            class="block mt-2 text-[11px] text-ink-muted hover:text-ink font-semibold transition-colors cursor-pointer bg-transparent border-0 p-0">
            Saisir les coordonnées manuellement
        </button>
    </div>
</template>
