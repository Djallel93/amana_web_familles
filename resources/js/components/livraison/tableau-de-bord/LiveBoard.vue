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

    Section E4 du refactor (16/09/2026, septième et dernier chunk du
    domaine livraison) : ce composant n'est plus un îlot monté par
    app.ts sur #vue-livraison-suivi-livraison, mais un enfant normal de
    resources/js/pages/Livraison/SuiviLivraison.vue. Les data-* lues
    jusqu'ici sur le point de montage sont devenues des props — y
    compris `urls`, déjà un objet JSON unique côté Blade (data-urls),
    repris tel quel comme prop plutôt qu'éclaté en une prop par URL (voir
    le docblock de LiveBoardController::index()). Tout le reste
    (incidents/routes/non-couvertes/statistiques, RoutesPanel/
    IncidentsPanel/ShortfallPanel/BuildRouteFlow) reste des endpoints
    JSON classiques, inchangés — cet écran n'est PAS un cas B.

    Aucun repli dataset conservé : cet écran est le seul consommateur de
    ce composant (vérifié par grep avant conversion), il n'y a pas de
    page Blade non migrée à faire coexister.

    Rafraîchissement automatique (Scénario 2 du chantier "polling live" —
    règle de cohérence pour tout le polling de l'application : voir
    l'en-tête de components/familles/useLiveDossiers.ts ; ce composant est
    le cas 2 de cette règle, données JSON dans un composant Vue, où le
    usePoll() d'Inertia ne convient pas) :
    incidents/tournées/statistiques sont relus en arrière-plan toutes les
    20s (POLL_MS) pour qu'un arrêt livré ou ignoré par un chauffeur
    apparaisse sans action de l'admin. Ces données restent des endpoints
    JSON (pas des props Inertia, voir plus haut) : router.reload() n'aurait
    rien à recharger, on réutilise donc les mêmes loaders apiGet, en mode
    "silencieux" :
     - jamais de bascule `chargement` (RoutesPanel remplace toute sa liste
       par "Chargement…" tant qu'il est vrai, ce qui détruirait les
       panneaux ouverts et les sélections en cours à chaque tick) ;
     - un échec réseau ponctuel conserve les données affichées au lieu de
       les remplacer par "Impossible de charger" ;
     - la valeur n'est réassignée que si le contenu a réellement changé.
    Un jeton par liste (jetons) garantit que la réponse d'un poll parti
    AVANT un rechargement déclenché par une action de l'admin (chargerTout)
    ne l'écrase pas ; un tick est de toute façon ignoré tant qu'un
    chargement non silencieux est en cours. "Non couvertes" n'est relue que
    si l'ensemble des incidents ouverts a changé (c'est le "bénévole absent"
    signalé par l'équipe chargement qui en orpheline des livraisons — un
    arrêt livré ne change rien à cette liste), pas à chaque tick.
-->
<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { apiGet } from '../shared/api';
import type { Campagne, Livraison, Organisation, Quartier, RouteIncident, RouteLivraison, Secteur, SuiviLivraisonStatistiques, Ville } from '../shared/types';
import IncidentsPanel from './IncidentsPanel.vue';
import RoutesPanel from './RoutesPanel.vue';
import ShortfallPanel from './ShortfallPanel.vue';
import BuildRouteFlow from './BuildRouteFlow.vue';

const props = defineProps<{
    campagnes: Campagne[];
    campagneSelectionneeId: number | null;
    quartiers: Quartier[];
    villes: Ville[];
    secteurs: Secteur[];
    organisations: Organisation[];
    urls: Record<string, string>;
}>();

const campagnes = ref<Campagne[]>(props.campagnes);
const quartiers = ref<Quartier[]>(props.quartiers);
const villes = ref<Ville[]>(props.villes);
const secteurs = ref<Secteur[]>(props.secteurs);
const organisations = ref<Organisation[]>(props.organisations);
const urls = props.urls;

function formatDateFr(iso: string): string {
    const [annee, mois, jour] = iso.split('T')[0].split('-');
    return `${jour}/${mois}/${annee}`;
}

// Présélectionné quand on arrive depuis CampagneDetail.vue (07/09/2026,
// prompt §6) — voir campagneSelectionneeId (prop, ex-data-campagne-id).
const campagneId = ref(props.campagneSelectionneeId ? String(props.campagneSelectionneeId) : '');

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

// ── Chargement des listes ───────────────────────────────────────────────
//
// `silencieux` = true pour le rafraîchissement automatique (voir le
// commentaire en tête de fichier). Jeton par liste : incrémenté par chaque
// chargement NON silencieux ; un chargement (silencieux ou non) dont le
// jeton n'est plus le courant à son retour est écarté — il a été dépassé
// par un chargement plus récent (action de l'admin, changement de campagne).
const POLL_MS = 20000;
const jetons = { incidents: 0, routes: 0, nonCouvertes: 0, stats: 0 };

function memeContenu(a: unknown, b: unknown): boolean {
    return JSON.stringify(a) === JSON.stringify(b);
}

async function chargerIncidents(silencieux = false) {
    const jeton = silencieux ? jetons.incidents : ++jetons.incidents;
    if (!silencieux) {
        chargementIncidents.value = true;
        erreurIncidents.value = false;
    }
    const resultat = await apiGet<RouteIncident[]>(urlsCampagne.value.incidents);
    if (jeton !== jetons.incidents) return;
    if (!silencieux) chargementIncidents.value = false;
    if (!resultat.ok) {
        if (!silencieux) erreurIncidents.value = true;
        return;
    }
    if (!silencieux || !memeContenu(incidents.value, resultat.data)) incidents.value = resultat.data;
}

async function chargerRoutes(silencieux = false) {
    const jeton = silencieux ? jetons.routes : ++jetons.routes;
    if (!silencieux) {
        chargementRoutes.value = true;
        erreurRoutes.value = false;
    }
    const resultat = await apiGet<RouteLivraison[]>(urlsCampagne.value.routes);
    if (jeton !== jetons.routes) return;
    if (!silencieux) chargementRoutes.value = false;
    if (!resultat.ok) {
        if (!silencieux) erreurRoutes.value = true;
        return;
    }
    if (!silencieux || !memeContenu(routes.value, resultat.data)) routes.value = resultat.data;
}

async function chargerNonCouvertes(silencieux = false) {
    const jeton = silencieux ? jetons.nonCouvertes : ++jetons.nonCouvertes;
    if (!silencieux) {
        chargementNonCouvertes.value = true;
        erreurNonCouvertes.value = false;
    }
    // nonCouvertes() n'est pas paginé côté contrôleur (response()->json()
    // sur une Collection, pas un paginator) — contrairement à eligibles()/
    // file() — donc apiGet<Livraison[]> directement, pas de
    // normalizePaginated ici.
    const resultat = await apiGet<Livraison[]>(urlsCampagne.value.nonCouvertes);
    if (jeton !== jetons.nonCouvertes) return;
    if (!silencieux) chargementNonCouvertes.value = false;
    if (!resultat.ok) {
        if (!silencieux) erreurNonCouvertes.value = true;
        return;
    }
    if (!silencieux || !memeContenu(nonCouvertes.value, resultat.data)) nonCouvertes.value = resultat.data;
}

async function chargerStatistiques(silencieux = false) {
    const jeton = silencieux ? jetons.stats : ++jetons.stats;
    const resultat = await apiGet<SuiviLivraisonStatistiques>(urlsCampagne.value.statistiques);
    if (jeton !== jetons.stats) return;
    if (!resultat.ok) {
        // Silencieux : on garde les dernières statistiques connues.
        if (!silencieux) stats.value = null;
        return;
    }
    if (!silencieux || !memeContenu(stats.value, resultat.data)) stats.value = resultat.data;
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

// ── Rafraîchissement automatique ────────────────────────────────────────
let pollEnCours = false;
let minuterie: ReturnType<typeof setInterval> | undefined;

/** Empreinte de l'ensemble des incidents ouverts (ids triés). */
function empreinteIncidents(): string {
    return incidents.value
        .map((i) => i.id)
        .sort((a, b) => a - b)
        .join(',');
}

async function rafraichirEnArrierePlan() {
    if (!campagneId.value || document.hidden || pollEnCours) return;
    // Un chargement non silencieux (action de l'admin, changement de
    // campagne) est plus récent que ce tick : on laisse passer ce tick.
    if (chargementIncidents.value || chargementRoutes.value || chargementNonCouvertes.value) return;

    pollEnCours = true;
    try {
        const avant = empreinteIncidents();
        await Promise.all([chargerIncidents(true), chargerRoutes(true), chargerStatistiques(true)]);
        if (empreinteIncidents() !== avant) await chargerNonCouvertes(true);
    } finally {
        pollEnCours = false;
    }
}

function auChangementDeVisibilite() {
    if (!document.hidden) void rafraichirEnArrierePlan();
}

onMounted(() => {
    if (campagneId.value) chargerTout();
    minuterie = setInterval(rafraichirEnArrierePlan, POLL_MS);
    document.addEventListener('visibilitychange', auChangementDeVisibilite);
});

onUnmounted(() => {
    if (minuterie) clearInterval(minuterie);
    document.removeEventListener('visibilitychange', auChangementDeVisibilite);
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
