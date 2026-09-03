<!-- resources/js/components/livraison/tableau-de-bord/LiveBoard.vue -->
<!--
    Tableau de bord live — reconstruit en Vue le 03/09/2026, voir
    resources/views/livraison/tableau-de-bord.blade.php. Écran le plus
    dense des quatre (voir le commentaire qui occupait ce fichier Blade
    avant ce patch, qui expliquait pourquoi il était resté en JS simple
    jusqu'ici) : orchestrateur unique qui centralise le fetch
    incidents/routes/non-couvertes et les repasse en props aux panneaux,
    plutôt que chaque panneau ne fetch pour son propre compte — nécessaire
    ici parce qu'une seule mutation (résoudre un incident, construire une
    tournée personnalisée) peut invalider les trois listes à la fois.
-->
<script setup lang="ts">
import { ref, computed } from 'vue';
import { apiGet } from '../shared/api';
import type { Campagne, Livraison, RawLaravelPaginator, RouteIncident, RouteLivraison } from '../shared/types';
import IncidentsPanel from './IncidentsPanel.vue';
import RoutesPanel from './RoutesPanel.vue';
import ShortfallPanel from './ShortfallPanel.vue';
import BuildRouteFlow from './BuildRouteFlow.vue';

const el = document.getElementById('vue-livraison-tableau-de-bord')!;
const campagnes = ref<Campagne[]>(JSON.parse(el.dataset.campagnes ?? '[]'));
const urls = JSON.parse(el.dataset.urls ?? '{}') as Record<string, string>;

function formatDateFr(iso: string): string {
    const [annee, mois, jour] = iso.split('T')[0].split('-');
    return `${jour}/${mois}/${annee}`;
}

const campagneId = ref('');

// URLs campagne-scopées : __CAMPAGNE__ substitué une fois l'id connu,
// mémorisées pour être repassées telles quelles aux panneaux enfants
// (voir data-urls dans la Blade, même technique que urls.deleteDoc dans
// DetailPanel.vue).
const urlsCampagne = computed(() => {
    const id = campagneId.value;
    const remplacer = (gabarit: string) => gabarit.replace('__CAMPAGNE__', id);
    return {
        incidents: remplacer(urls.incidents ?? ''),
        routes: remplacer(urls.routes ?? ''),
        nonCouvertes: remplacer(urls.nonCouvertes ?? ''),
        routesPersonnalisees: remplacer(urls.routesPersonnalisees ?? ''),
    };
});

const incidents = ref<RouteIncident[]>([]);
const chargementIncidents = ref(false);
const erreurIncidents = ref(false);

const routes = ref<RouteLivraison[]>([]);
const chargementRoutes = ref(false);
const erreurRoutes = ref(false);

const nonCouvertes = ref<Livraison[]>([]);
const chargementNonCouvertes = ref(false);
const erreurNonCouvertes = ref(false);

async function chargerIncidents() {
    chargementIncidents.value = true;
    erreurIncidents.value = false;
    const resultat = await apiGet<RouteIncident[]>(urlsCampagne.value.incidents);
    chargementIncidents.value = false;
    if (!resultat.ok) { erreurIncidents.value = true; return; }
    incidents.value = resultat.data;
}

async function chargerRoutes() {
    chargementRoutes.value = true;
    erreurRoutes.value = false;
    const resultat = await apiGet<RouteLivraison[]>(urlsCampagne.value.routes);
    chargementRoutes.value = false;
    if (!resultat.ok) { erreurRoutes.value = true; return; }
    routes.value = resultat.data;
}

async function chargerNonCouvertes() {
    chargementNonCouvertes.value = true;
    erreurNonCouvertes.value = false;
    // nonCouvertes() n'est pas paginé côté contrôleur (response()->json()
    // sur une Collection, pas un paginator) — contrairement à eligibles()/
    // file() — donc apiGet<Livraison[]> directement, pas de
    // normalizePaginated ici.
    const resultat = await apiGet<Livraison[]>(urlsCampagne.value.nonCouvertes);
    chargementNonCouvertes.value = false;
    if (!resultat.ok) { erreurNonCouvertes.value = true; return; }
    nonCouvertes.value = resultat.data;
}

function chargerTout() {
    chargerIncidents();
    chargerRoutes();
    chargerNonCouvertes();
}

function onCampagneChange() {
    if (campagneId.value) chargerTout();
}
</script>

<template>
    <div>
        <select v-model="campagneId" @change="onCampagneChange"
            class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.5rem] mb-6">
            <option value="">— Choisir une campagne —</option>
            <option v-for="c in campagnes" :key="c.id" :value="String(c.id)">
                {{ formatDateFr(c.date_livraison) }} — {{ c.type }}
            </option>
        </select>

        <div v-if="campagneId">
            <IncidentsPanel :incidents="incidents" :chargement="chargementIncidents" :erreur="erreurIncidents"
                :url-resoudre="urls.incidentResoudre ?? ''" @changed="chargerTout" />

            <BuildRouteFlow :campagne-id="campagneId" :non-couvertes="nonCouvertes"
                :url="urlsCampagne.routesPersonnalisees" @created="chargerTout" />

            <h2 class="text-[14px] font-medium text-ink mb-3">Tournées</h2>
            <RoutesPanel :routes="routes" :chargement="chargementRoutes" :erreur="erreurRoutes"
                :non-couvertes="nonCouvertes"
                :url-ajouter="urls.routeAjouter ?? ''" :url-retirer="urls.routeRetirer ?? ''"
                :url-reassigner="urls.routeReassigner ?? ''" :url-diviser="urls.routeDiviser ?? ''"
                @changed="chargerTout" />

            <ShortfallPanel :livraisons="nonCouvertes" :chargement="chargementNonCouvertes" :erreur="erreurNonCouvertes" />
        </div>
    </div>
</template>
