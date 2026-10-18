<!-- resources/js/pages/Livraison/Chargement.vue -->
<!--
    Page Inertia « Chargement » (11/10/2026) — remplace resources/views/livraison/chargement.blade.php
    et ses partials (chargement-route, chargement-famille, chargement-rappel), supprimés dans ce même
    changement. Raison : l'équipe chargement porte désormais la composition des tournées (ajouter /
    retirer une famille, changer de chauffeur, annuler une tournée vide — ex-Suivi livraison), ce qui
    demande de vrais composants avec état et boîtes de dialogue que le polling d'HTML pré-rendu
    écrasait.

    Polling (toutes les 20 s) : ChargementController::liste() renvoie les mêmes données que la page
    initiale ; les cartes sont clés par id, un rafraîchissement n'écrase donc ni un champ en cours de
    saisie ni un <details> ouvert. Les lignes apparues depuis le dernier rafraîchissement sont
    surlignées 5 s (comportement conservé de la version Blade).
-->
<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import { apiGet } from "../../components/livraison/shared/api";
import ChargementRouteCard from "../../components/livraison/chargement/ChargementRouteCard.vue";
import type {
    FamilleSansTournee,
    FiltreChargement,
    RappelRoutes,
    RouteChargement,
    StatsChargement,
    UrlsChargement,
} from "../../components/livraison/chargement/types";

const props = defineProps<{
    campagne: { id: number };
    filtre: FiltreChargement;
    urlRetour: string;
    urlEtiquettes: string;
    peutReassigner: boolean;
    urls: UrlsChargement;
    stats: StatsChargement;
    routes: RouteChargement[];
    familles: FamilleSansTournee[];
    rappel: RappelRoutes | null;
}>();

const POLL_MS = 20000;

const stats = ref<StatsChargement>(props.stats);
const routes = ref<RouteChargement[]>(props.routes);
const familles = ref<FamilleSansTournee[]>(props.familles);
const rappel = ref<RappelRoutes | null>(props.rappel);
const nouvelles = ref<Set<number>>(new Set());
let idsVus = new Set(props.routes.map((r) => r.id));
let minuteur: number | undefined;
let rafraichissementEnCours = false;

const FILTRES: { valeur: FiltreChargement; libelle: string }[] = [
    { valeur: "toutes", libelle: "Toutes" },
    { valeur: "restantes", libelle: "Restantes" },
    { valeur: "en_preparation", libelle: "En préparation" },
    { valeur: "pretes", libelle: "Prêtes" },
    { valeur: "chargees", libelle: "Chargées" },
    { valeur: "en_livraison", libelle: "En livraison" },
    { valeur: "terminees", libelle: "Terminées" },
    { valeur: "vides", libelle: "Vides" },
];

interface Liste {
    stats: StatsChargement;
    routes: RouteChargement[];
    familles: FamilleSansTournee[];
    rappel: RappelRoutes | null;
}

function appliquer(donnees: Liste, signalerNouvelles: boolean) {
    if (signalerNouvelles) {
        const apparues = donnees.routes.map((r) => r.id).filter((id) => !idsVus.has(id));
        if (apparues.length > 0) {
            nouvelles.value = new Set([...nouvelles.value, ...apparues]);
            window.setTimeout(() => {
                nouvelles.value = new Set([...nouvelles.value].filter((id) => !apparues.includes(id)));
            }, 5000);
        }
    }
    idsVus = new Set(donnees.routes.map((r) => r.id));
    stats.value = donnees.stats;
    routes.value = donnees.routes;
    familles.value = donnees.familles;
    rappel.value = donnees.rappel;
}

async function rafraichir(silencieux = true) {
    if (rafraichissementEnCours || (silencieux && document.hidden)) return;
    rafraichissementEnCours = true;
    const resultat = await apiGet<Liste>(`${props.urls.liste}?filtre_chargement=${props.filtre}`);
    rafraichissementEnCours = false;
    if (resultat.ok) appliquer(resultat.data, true);
}

function choisirFiltre(valeur: FiltreChargement) {
    router.get(window.location.pathname, valeur === "toutes" ? {} : { filtre_chargement: valeur }, {
        preserveScroll: true,
        replace: true,
    });
}

// Navigation Inertia (changement de filtre) : les props sont remplacées, on resynchronise l'état local.
watch(
    () => [props.routes, props.familles, props.stats, props.rappel] as const,
    ([r, f, s, ra]) => appliquer({ routes: r, familles: f, stats: s, rappel: ra }, false),
);

onMounted(() => {
    minuteur = window.setInterval(() => void rafraichir(), POLL_MS);
});
onBeforeUnmount(() => window.clearInterval(minuteur));
</script>

<template>
    <Head title="Chargement — AMANA Familles" />

    <div class="max-w-4xl mx-auto py-8">
        <Link
            :href="urlRetour"
            class="inline-flex items-center gap-2 text-[14px] font-semibold text-white bg-ink px-4 py-2 rounded-lg mb-4 hover:opacity-90"
        >
            ← Retour à la campagne
        </Link>

        <div class="flex items-center justify-between gap-3 mb-6">
            <h1 class="font-heading text-xl font-semibold text-ink">Chargement — tournées</h1>
            <a
                :href="urlEtiquettes"
                target="_blank"
                class="text-[12.5px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted shrink-0 bg-white hover:bg-stone-50"
            >
                🖨️ Étiquettes — toute la campagne
            </a>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3 mb-4">
            <div class="bg-stone-50 border border-surface-border rounded-xl p-3">
                <p class="text-[11px] text-ink-muted uppercase tracking-wide">Restantes</p>
                <p class="text-[20px] font-semibold text-ink">{{ stats.restantes }}</p>
            </div>
            <div class="bg-amber-50 border border-amber-100 rounded-xl p-3">
                <p class="text-[11px] text-amber-700 uppercase tracking-wide">En préparation</p>
                <p class="text-[20px] font-semibold text-amber-700">{{ stats.en_preparation }}</p>
            </div>
            <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3">
                <p class="text-[11px] text-emerald-700 uppercase tracking-wide">Prête(s)</p>
                <p class="text-[20px] font-semibold text-emerald-700">{{ stats.pretes }}</p>
            </div>
            <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-3">
                <p class="text-[11px] text-indigo-700 uppercase tracking-wide">Chargée(s)</p>
                <p class="text-[20px] font-semibold text-indigo-700">{{ stats.chargees }}</p>
            </div>
            <!-- 17/10/2026 : les tournées parties restent visibles. -->
            <div class="bg-sky-50 border border-sky-100 rounded-xl p-3">
                <p class="text-[11px] text-sky-700 uppercase tracking-wide">En livraison</p>
                <p class="text-[20px] font-semibold text-sky-700">{{ stats.en_livraison }}</p>
            </div>
            <div class="bg-teal-50 border border-teal-100 rounded-xl p-3">
                <p class="text-[11px] text-teal-700 uppercase tracking-wide">Terminée(s)</p>
                <p class="text-[20px] font-semibold text-teal-700">{{ stats.terminees }}</p>
            </div>
            <div class="bg-stone-100 border border-surface-border rounded-xl p-3">
                <p class="text-[11px] text-ink-muted uppercase tracking-wide">Vide(s)</p>
                <p class="text-[20px] font-semibold text-ink">{{ stats.vides }}</p>
            </div>
        </div>

        <div class="flex flex-wrap gap-2 mb-6">
            <button
                v-for="f in FILTRES"
                :key="f.valeur"
                type="button"
                class="text-[12.5px] px-3 py-1.5 rounded-lg border"
                :class="
                    filtre === f.valeur ? 'bg-accent text-white border-accent' : 'border-surface-border text-ink-muted'
                "
                @click="choisirFiltre(f.valeur)"
            >
                {{ f.libelle }}
            </button>
        </div>

        <div v-if="rappel" class="bg-amber-50 border border-amber-200 rounded-xl px-5 py-6 mb-4 text-center">
            <div class="text-[36px] leading-none mb-3" aria-hidden="true">🚚</div>
            <template v-if="rappel.demarree">
                <p class="text-[16px] text-amber-900 font-semibold mb-3">Tournées pas encore générées</p>
                <p class="text-[13.5px] text-amber-800 leading-relaxed max-w-md mx-auto mb-5">
                    Des familles sont confirmées mais aucune tournée n'a encore été créée pour cette campagne.
                </p>
                <a
                    v-if="rappel.peut_generer"
                    :href="rappel.url_generer"
                    class="inline-flex items-center gap-2 min-h-[44px] px-5 py-2.5 rounded-lg bg-accent hover:bg-accent-dark text-white text-[14px] font-semibold no-underline transition-colors active:scale-95"
                >
                    🗺️ Générer les routes
                </a>
                <p v-else class="text-[13px] text-amber-700">
                    Prévenez un admin/gestionnaire pour qu'il génère les routes depuis la page de la campagne.
                </p>
            </template>
            <template v-else>
                <p class="text-[16px] text-amber-900 font-semibold mb-3">Campagne pas encore démarrée</p>
                <p class="text-[13.5px] text-amber-800 leading-relaxed max-w-md mx-auto mb-5">
                    Les tournées se génèrent une fois la campagne démarrée.
                </p>
                <a
                    v-if="rappel.peut_generer"
                    :href="rappel.url_campagne"
                    class="inline-flex items-center gap-2 min-h-[44px] px-5 py-2.5 rounded-lg bg-accent hover:bg-accent-dark text-white text-[14px] font-semibold no-underline transition-colors active:scale-95"
                >
                    ▶️ Démarrer la campagne
                </a>
                <p v-else class="text-[13px] text-amber-700">
                    Prévenez un admin/gestionnaire pour qu'il démarre la campagne.
                </p>
            </template>
        </div>

        <div class="space-y-3">
            <ChargementRouteCard
                v-for="route in routes"
                :key="route.id"
                :route="route"
                :urls="urls"
                :familles="familles"
                :peut-reassigner="peutReassigner"
                :nouvelle="nouvelles.has(route.id)"
                @changed="rafraichir(false)"
            />
        </div>

        <p v-if="routes.length === 0 && familles.length === 0" class="text-[14px] text-ink-muted">
            Aucune tournée à charger pour le moment.
        </p>

        <div v-if="familles.length > 0" class="mt-6">
            <p class="text-[13px] text-ink-muted mb-2">Familles pas encore dans une tournée :</p>
            <div class="space-y-3">
                <div
                    v-for="f in familles"
                    :key="f.id"
                    class="bg-surface border border-surface-border rounded-xl p-4 opacity-90"
                    :id="`famille-${f.id}`"
                >
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-[14px] font-medium text-ink">Famille #{{ f.famille_id }}</span>
                        <span class="text-[11px] font-medium px-2 py-0.5 rounded-full" :class="f.etat_style">{{
                            f.etat_libelle
                        }}</span>
                    </div>
                    <p class="text-[12px] text-ink-muted">
                        <span v-if="f.etudiant" class="text-sky-600">· étudiant </span>
                        <span v-if="f.est_hotel" class="text-amber-600">· hôtel </span>
                        <span v-if="f.nombre_enfant > 0" class="text-violet-600"
                            >· {{ f.nombre_enfant }} enfant(s)</span
                        >
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
