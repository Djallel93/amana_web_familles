<!-- resources/js/components/livraison/tableau-de-bord/LiveBoard.vue -->
<!--
    Suivi livraison — reconstruit en Vue le 03/09/2026, renommé depuis
    "Tableau de bord" le 07/09/2026 (prompt de cette date §6), voir
    resources/views/livraison/suivi-livraison.blade.php. Écran le plus
    dense des quatre (voir le commentaire qui occupait ce fichier Blade
    avant ce patch, qui expliquait pourquoi il était resté en JS simple
    jusqu'ici) : orchestrateur unique qui centralise le fetch
    incidents/routes/non-couvertes/statistiques et les repasse en props
    aux panneaux, plutôt que chaque panneau ne fetch pour son propre
    compte — nécessaire ici parce qu'une seule mutation (résoudre un
    incident, construire une tournée personnalisée) peut invalider
    plusieurs listes à la fois.

    Révisé le 09/09/2026 (prompt de cette date §5.2.4) : cartes
    statistiques ajoutées en haut, même patron que ContactsQueue.vue/
    packaging.blade.php ; quartiers/villes/secteurs/organisations chargés
    ici (même référentiel que CampagneDetail.vue) pour le
    FamilleFilterPanel de BuildRouteFlow.vue (§5.1.3).
-->
<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { apiGet } from '../shared/api';
import type { Campagne, Livraison, Organisation, Quartier, RouteIncident, RouteLivraison, Secteur, SuiviLivraisonStatistiques, Ville } from '../shared/types';
import IncidentsPanel from './IncidentsPanel.vue';
import RoutesPanel from './RoutesPanel.vue';
import ShortfallPanel from './ShortfallPanel.vue';
import BuildRouteFlow from './BuildRouteFlow.vue';

const el = document.getElementById('vue-livraison-suivi-livraison')!;
const campagnes = ref<Campagne[]>(JSON.parse(el.dataset.campagnes ?? '[]'));
const quartiers = ref<Quartier[]>(JSON.parse(el.dataset.quartiers ?? '[]'));
const villes = ref<Ville[]>(JSON.parse(el.dataset.villes ?? '[]'));
const secteurs = ref<Secteur[]>(JSON.parse(el.dataset.secteurs ?? '[]'));
const organisations = ref<Organisation[]>(JSON.parse(el.dataset.organisations ?? '[]'));
const urls = JSON.parse(el.dataset.urls ?? '{}') as Record<string, string>;

function formatDateFr(iso: string): string {
    const [annee, mois, jour] = iso.split('T')[0].split('-');
    return `${jour}/${mois}/${annee}`;
}

// Présélectionné quand on arrive depuis CampagneDetail.vue (07/09/2026,
// prompt §6) — voir data-campagne-id dans suivi-livraison.blade.php.
const campagneId = ref(el.dataset.campagneId ?? '');

// URLs campagne-scopées : __CAMPAGNE__ substitué une fois l'id connu,
// mémorisées pour être repassées telles quelles aux panneaux enfants
// (voir data-urls dans la Blade, même technique que urls.deleteDoc dans
// DetailPanel.vue). routeSupprimer/etapeStatut restent des gabarits
// __ID__/__ETAPE__ passés bruts à RoutesPanel.vue, qui fait lui-même le
// remplacement (même patron que routeAjouter/routeRetirer existants).
const urlsCampagne = computed(() => {
    const id = campagneId.value;
    const remplacer = (gabarit: string) => gabarit.replace('__CAMPAGNE__', id);
    return {
        incidents: remplacer(urls.incidents ?? ''),
        routes: remplacer(urls.routes ?? ''),
        nonCouvertes: remplacer(urls.nonCouvertes ?? ''),
        nonCouvertesTableau: remplacer(urls.nonCouvertesTableau ?? ''),
        statistiques: remplacer(urls.statistiques ?? ''),
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

// Cartes statistiques (09/09/2026, prompt §5.2.4).
const stats = ref<SuiviLivraisonStatistiques | null>(null);

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

async function chargerStatistiques() {
    const resultat = await apiGet<SuiviLivraisonStatistiques>(urlsCampagne.value.statistiques);
    stats.value = resultat.ok ? resultat.data : null;
}

function chargerTout() {
    chargerIncidents();
    chargerRoutes();
    chargerNonCouvertes();
    chargerStatistiques();
}

function onCampagneChange() {
    if (campagneId.value) chargerTout();
}

onMounted(() => {
    if (campagneId.value) chargerTout();
});
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
            <!--
                Cartes statistiques (09/09/2026, prompt §5.2.4 : "Add
                statistique cards at the top like the rest of the pages")
                — même patron que ContactsQueue.vue/packaging.blade.php.
            -->
            <div v-if="stats" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                <div class="bg-surface border border-surface-border rounded-xl p-3">
                    <p class="text-[11px] text-ink-muted uppercase tracking-wide">Tournées actives</p>
                    <p class="text-[20px] font-semibold text-ink">{{ stats.tournees_actives }}</p>
                </div>
                <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3">
                    <p class="text-[11px] text-emerald-700 uppercase tracking-wide">Tournées terminées</p>
                    <p class="text-[20px] font-semibold text-emerald-700">{{ stats.tournees_terminees }}</p>
                </div>
                <div class="bg-rose-50 border border-rose-100 rounded-xl p-3">
                    <p class="text-[11px] text-rose-700 uppercase tracking-wide">Tournées annulées</p>
                    <p class="text-[20px] font-semibold text-rose-700">{{ stats.tournees_annulees }}</p>
                </div>
                <div class="bg-sky-50 border border-sky-100 rounded-xl p-3">
                    <p class="text-[11px] text-sky-700 uppercase tracking-wide">Avancement</p>
                    <p class="text-[20px] font-semibold text-sky-700">{{ Math.round(stats.avancement_pct * 100) }}%</p>
                </div>
                <div class="bg-stone-50 border border-surface-border rounded-xl p-3">
                    <p class="text-[11px] text-ink-muted uppercase tracking-wide">Restantes</p>
                    <p class="text-[20px] font-semibold text-ink">{{ stats.livraisons_restantes }}</p>
                </div>
                <div class="bg-sky-50 border border-sky-100 rounded-xl p-3">
                    <p class="text-[11px] text-sky-700 uppercase tracking-wide">En cours</p>
                    <p class="text-[20px] font-semibold text-sky-700">{{ stats.livraisons_en_cours }}</p>
                </div>
                <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3">
                    <p class="text-[11px] text-emerald-700 uppercase tracking-wide">Livrées</p>
                    <p class="text-[20px] font-semibold text-emerald-700">{{ stats.livraisons_livrees }}</p>
                </div>
                <div class="bg-rose-50 border border-rose-100 rounded-xl p-3">
                    <p class="text-[11px] text-rose-700 uppercase tracking-wide">Ignorées</p>
                    <p class="text-[20px] font-semibold text-rose-700">{{ stats.livraisons_ignorees }}</p>
                </div>
            </div>

            <IncidentsPanel :incidents="incidents" :chargement="chargementIncidents" :erreur="erreurIncidents"
                :url-resoudre="urls.incidentResoudre ?? ''" @changed="chargerTout" />

            <BuildRouteFlow :campagne-id="campagneId"
                :villes="villes" :secteurs="secteurs" :quartiers="quartiers" :organisations="organisations"
                :url="urlsCampagne.routesPersonnalisees" :url-non-couvertes-tableau="urlsCampagne.nonCouvertesTableau"
                @created="chargerTout" />

            <h2 class="text-[14px] font-medium text-ink mb-3">Tournées</h2>
            <RoutesPanel :routes="routes" :chargement="chargementRoutes" :erreur="erreurRoutes"
                :non-couvertes="nonCouvertes"
                :url-ajouter="urls.routeAjouter ?? ''" :url-retirer="urls.routeRetirer ?? ''"
                :url-reassigner="urls.routeReassigner ?? ''" :url-diviser="urls.routeDiviser ?? ''"
                :url-supprimer="urls.routeSupprimer ?? ''" :url-etape-statut="urls.etapeStatut ?? ''"
                @changed="chargerTout" />

            <ShortfallPanel :livraisons="nonCouvertes" :chargement="chargementNonCouvertes" :erreur="erreurNonCouvertes" />
        </div>
    </div>
</template>
