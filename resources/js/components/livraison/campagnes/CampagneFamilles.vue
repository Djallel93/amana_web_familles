<!-- resources/js/components/livraison/campagnes/CampagneFamilles.vue -->
<!--
    Sélection des familles éligibles — sortie de l'ancienne page détail
    campagne (03/10/2026) pour avoir sa propre page, accessible depuis la
    barre latérale et depuis la carte « Sélection des familles » du hub
    (voir pages/Livraison/CampagneFamilles.vue et
    CampagnesController::familles()). Le code est celui de CampagneDetail.vue
    (05/09/2026 prompt §1.6 : vraie table + FamilleFilterPanel partagé +
    sélection croisant les pages via ids_only ; §1.7 : bouton désactivé tant
    qu'aucune sélection), déplacé tel quel avec le sélecteur de journée.
    La génération des routes n'est PLUS ici : voir GenererRoutesPanel.vue
    (Suivi livraison).
-->
<script setup lang="ts">
import { ref } from "vue";
import { onMounted } from "vue";
import { useToast } from "@amana/shared-ui";
import { apiGet, apiPost, buildQuery } from "../shared/api";
import PaginationControls from "../shared/PaginationControls.vue";
import FamilleFilterPanel from "../shared/FamilleFilterPanel.vue";
import {
    normalizePaginated,
    type Campagne,
    type CampagneJournee,
    type FamilleEligible,
    type FamilleFiltres,
    type GenererLivraisonsResultat,
    type Organisation,
    type Paginated,
    type Quartier,
    type RawLaravelPaginator,
    type Secteur,
    type Ville,
} from "../shared/types";

const toast = useToast();

const props = defineProps<{
    campagne: Campagne;
    quartiers: Quartier[];
    villes: Ville[];
    secteurs: Secteur[];
    organisations: Organisation[];
    urls: { eligibles: string; genererLivraisons: string };
}>();

const urls = props.urls;
const quartiers = ref<Quartier[]>(props.quartiers);
const villes = ref<Ville[]>(props.villes);
const secteurs = ref<Secteur[]>(props.secteurs);
const organisations = ref<Organisation[]>(props.organisations);

function formatDateFr(iso: string): string {
    const [annee, mois, jour] = iso.split("T")[0].split("-");
    return `${jour}/${mois}/${annee}`;
}

// ── Sélection de journée ─────────────────────────────────────────────────
const journees = ref<CampagneJournee[]>(props.campagne.journees ?? []);
const idJourneeSelectionnee = ref<number | null>(journees.value[0]?.id ?? null);

// ── Filtre + table des familles éligibles (05/09/2026, prompt §1.6) ──────
const filtres = ref<FamilleFiltres>({});
// Tri colonne par colonne (05/09/2026, prompt §1.2.3) — mêmes clés que
// CampagnesController::COLONNES_TRIABLES_ELIGIBLES.
const tri = ref<string | null>(null);
const directionTri = ref<"asc" | "desc">("asc");

function trierPar(colonne: string) {
    if (tri.value === colonne) {
        directionTri.value = directionTri.value === "asc" ? "desc" : "asc";
    } else {
        tri.value = colonne;
        directionTri.value = "asc";
    }
    chargerEligibles(1);
}

const eligibles = ref<FamilleEligible[]>([]);
const metaEligibles = ref<Paginated<FamilleEligible>["meta"] | null>(null);
const chargementEligibles = ref(true);
const erreurEligibles = ref(false);

// La sélection survit à la pagination et au changement de filtre — Set
// d'ids plutôt qu'un tableau de lignes cochées par page.
const selectionnees = ref<Set<number>>(new Set());

function queryFiltres(page: number) {
    return buildQuery({
        page,
        tri: tri.value,
        direction: tri.value ? directionTri.value : undefined,
        id_ville: filtres.value.id_ville,
        id_secteur: filtres.value.id_secteur,
        id_quartier: filtres.value.id_quartier,
        criticite: filtres.value.criticite,
        se_deplace: filtres.value.se_deplace || undefined,
        est_hotel: filtres.value.est_hotel || undefined,
        etudiant: filtres.value.etudiant || undefined,
        zakat_el_fitr: filtres.value.zakat_el_fitr || undefined,
        sadaqa: filtres.value.sadaqa || undefined,
        id_organisation_origine: filtres.value.id_organisation_origine,
        id_organisation_rattachee: filtres.value.id_organisation_rattachee,
        recherche: filtres.value.recherche,
    });
}

async function chargerEligibles(page = 1) {
    chargementEligibles.value = true;
    erreurEligibles.value = false;

    const resultat = await apiGet<RawLaravelPaginator<FamilleEligible>>(`${urls.eligibles}${queryFiltres(page)}`);
    chargementEligibles.value = false;

    if (!resultat.ok) {
        erreurEligibles.value = true;
        return;
    }

    const paginé = normalizePaginated(resultat.data);
    eligibles.value = paginé.data;
    metaEligibles.value = paginé.meta;
}

function toggleFamille(id: number) {
    if (selectionnees.value.has(id)) selectionnees.value.delete(id);
    else selectionnees.value.add(id);
    selectionnees.value = new Set(selectionnees.value);
}

/**
 * Sélectionner/désélectionner TOUT ce qui correspond au filtre courant,
 * pas seulement la page affichée (prompt §1.6.3) — interroge
 * .../eligibles?ids_only=1 pour récupérer les ids sur toutes les pages en
 * un appel plutôt que de paginer manuellement.
 */
const chargementSelectionTout = ref(false);

async function toutSelectionnerFiltre() {
    const pageActuelleIds = new Set(eligibles.value.map((f) => f.id));
    const dejaToutSelectionne =
        eligibles.value.length > 0 && eligibles.value.every((f) => selectionnees.value.has(f.id));

    if (dejaToutSelectionne) {
        // Décoche uniquement ce qui vient du filtre courant, pas une
        // sélection faite plus tôt sous un autre filtre.
        for (const id of pageActuelleIds) selectionnees.value.delete(id);
        selectionnees.value = new Set(selectionnees.value);
        return;
    }

    chargementSelectionTout.value = true;
    const resultat = await apiGet<{ ids: number[] }>(`${urls.eligibles}${queryFiltres(1)}&ids_only=1`);
    chargementSelectionTout.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    resultat.data.ids.forEach((id) => selectionnees.value.add(id));
    selectionnees.value = new Set(selectionnees.value);
}

// ── Génération des livraisons ───────────────────────────────────────────
const chargementGeneration = ref(false);
const resultatGeneration = ref<GenererLivraisonsResultat | null>(null);
const erreurGeneration = ref("");

async function genererLivraisons() {
    if (selectionnees.value.size === 0 || idJourneeSelectionnee.value === null) return;

    chargementGeneration.value = true;
    erreurGeneration.value = "";
    resultatGeneration.value = null;

    const resultat = await apiPost<GenererLivraisonsResultat>(urls.genererLivraisons, {
        ids_familles: [...selectionnees.value],
        id_campagne_journee: idJourneeSelectionnee.value,
    });
    chargementGeneration.value = false;

    if (!resultat.ok) {
        erreurGeneration.value = resultat.message;
        return;
    }

    resultatGeneration.value = resultat.data;
    selectionnees.value = new Set();
    // Libellé recentré sur l'action de l'utilisateur (09/09/2026, prompt
    // §2.1 : "instead of something like famille(s) ajoutée(s)") — compte
    // resultat.data.generees (familles réellement ajoutées), pas la
    // sélection brute qui peut inclure des conflits/déjà-existantes ; le
    // détail génération/déjà existantes/conflits reste affiché juste en
    // dessous (voir resultatGeneration dans le template).
    toast.success(`${resultat.data.generees} famille(s) ajoutée(s).`);
    chargerEligibles(metaEligibles.value?.current_page ?? 1);
}

onMounted(() => {
    chargerEligibles(1);
});
</script>

<template>
    <div>
        <!-- Sélecteur de journée -->
        <div v-if="journees.length > 1" class="mb-3">
            <label class="block text-[12.5px] font-medium text-ink-muted mb-1">Journée</label>
            <select
                v-model.number="idJourneeSelectionnee"
                class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]"
            >
                <option v-for="journee in journees" :key="journee.id" :value="journee.id">
                    {{ journee.label ?? formatDateFr(journee.date) }} —
                    {{ formatDateFr(journee.date) }}
                </option>
            </select>
        </div>

        <!-- Sélection des familles éligibles (05/09/2026, prompt §1.6) -->
        <div class="bg-surface border border-surface-border rounded-xl p-5 mb-8">
            <h2 class="text-[14px] font-medium text-ink mb-4">Sélection des familles éligibles</h2>

            <FamilleFilterPanel
                :villes="villes"
                :secteurs="secteurs"
                :quartiers="quartiers"
                :organisations="organisations"
                :model-value="filtres"
                @update:model-value="filtres = $event"
                @filtrer="chargerEligibles(1)"
            />

            <div class="overflow-x-auto mb-3 border border-surface-border rounded-lg">
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="text-left text-ink-muted border-b border-surface-border bg-stone-50">
                            <th class="px-3 py-2 font-medium">
                                <input
                                    type="checkbox"
                                    :checked="eligibles.length > 0 && eligibles.every((f) => selectionnees.has(f.id))"
                                    :disabled="chargementSelectionTout"
                                    @change="toutSelectionnerFiltre"
                                    class="w-4 h-4 accent-accent"
                                />
                            </th>
                            <!-- Colonnes triables (05/09/2026, prompt §1.2.3) —
                                 clic sur l'en-tête, mêmes clés que
                                 CampagnesController::COLONNES_TRIABLES_ELIGIBLES. -->
                            <th class="px-3 py-2 font-medium cursor-pointer select-none" @click="trierPar('id')">
                                ID
                                <span v-if="tri === 'id'">{{ directionTri === "asc" ? "▲" : "▼" }}</span>
                            </th>
                            <th class="px-3 py-2 font-medium cursor-pointer select-none" @click="trierPar('nom')">
                                Nom
                                <span v-if="tri === 'nom'">{{ directionTri === "asc" ? "▲" : "▼" }}</span>
                            </th>
                            <th class="px-3 py-2 font-medium cursor-pointer select-none" @click="trierPar('telephone')">
                                Contact
                                <span v-if="tri === 'telephone'">{{ directionTri === "asc" ? "▲" : "▼" }}</span>
                            </th>
                            <th class="px-3 py-2 font-medium">Adresse</th>
                            <th class="px-3 py-2 font-medium cursor-pointer select-none" @click="trierPar('criticite')">
                                Criticité
                                <span v-if="tri === 'criticite'">{{ directionTri === "asc" ? "▲" : "▼" }}</span>
                            </th>
                            <th
                                class="px-3 py-2 font-medium cursor-pointer select-none"
                                @click="trierPar('derniere_livraison_le')"
                            >
                                Dernière livraison
                                <span v-if="tri === 'derniere_livraison_le'">{{
                                    directionTri === "asc" ? "▲" : "▼"
                                }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="chargementEligibles">
                            <td colspan="7" class="px-3 py-3 text-ink-muted">Chargement…</td>
                        </tr>
                        <tr v-else-if="erreurEligibles">
                            <td colspan="7" class="px-3 py-3 text-rose-600">
                                Impossible de charger les familles éligibles.
                            </td>
                        </tr>
                        <tr v-else-if="eligibles.length === 0">
                            <td colspan="7" class="px-3 py-3 text-ink-muted">
                                Aucune famille éligible pour ces filtres.
                            </td>
                        </tr>
                        <tr
                            v-for="famille in eligibles"
                            :key="famille.id"
                            class="border-b border-surface-border last:border-0 hover:bg-stone-50"
                        >
                            <td class="px-3 py-2">
                                <input
                                    type="checkbox"
                                    :checked="selectionnees.has(famille.id)"
                                    @change="toggleFamille(famille.id)"
                                    class="w-4 h-4 accent-accent"
                                />
                            </td>
                            <td class="px-3 py-2 text-ink-muted">#{{ famille.id }}</td>
                            <td class="px-3 py-2 text-ink">{{ famille.prenom }} {{ famille.nom }}</td>
                            <td class="px-3 py-2 text-ink-muted">
                                {{ famille.telephone || "—" }}
                                <span v-if="famille.telephone_bis"> / {{ famille.telephone_bis }}</span>
                            </td>
                            <td class="px-3 py-2 text-ink-muted">
                                {{ famille.adresse || "—" }}
                                <span v-if="famille.quartier">— {{ famille.quartier.nom }}</span>
                            </td>
                            <td class="px-3 py-2 text-ink-muted">
                                {{ famille.criticite ?? "—" }}
                            </td>
                            <td class="px-3 py-2 text-ink-muted">
                                {{
                                    famille.derniere_livraison_le
                                        ? formatDateFr(famille.derniere_livraison_le)
                                        : "jamais livrée"
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <PaginationControls v-if="metaEligibles" :meta="metaEligibles" @change="chargerEligibles" />

            <p class="text-[12px] text-ink-muted mt-3 mb-2">{{ selectionnees.size }} famille(s) sélectionnée(s)</p>
            <!-- Renommé + désactivé tant qu'aucune sélection (05/09/2026,
                 prompt §1.7) — auparavant toujours actif dès que
                 chargementGeneration était faux, cliquable même à 0
                 sélection. -->
            <button
                type="button"
                :disabled="chargementGeneration || selectionnees.size === 0"
                @click="genererLivraisons"
                class="min-h-[2.5rem] text-[13px] px-4 py-2 rounded-lg bg-accent text-white disabled:opacity-40 disabled:cursor-not-allowed"
            >
                {{ chargementGeneration ? "Ajout…" : "Ajouter les familles sélectionnées" }}
            </button>
            <p v-if="erreurGeneration" class="text-[13px] text-rose-600 mt-3">
                {{ erreurGeneration }}
            </p>

            <div v-if="resultatGeneration" class="mt-4 pt-4 border-t border-surface-border">
                <p class="text-[13px] text-ink">
                    {{ resultatGeneration.generees }} livraison(s) générée(s),
                    {{ resultatGeneration.deja_existantes }} déjà existante(s).
                </p>
                <div
                    v-if="resultatGeneration.conflits.length > 0"
                    class="mt-2 bg-rose-50 border border-rose-200 rounded-lg p-3"
                >
                    <p class="text-[12.5px] font-medium text-rose-700 mb-1.5">
                        ⚠ {{ resultatGeneration.conflits.length }} famille(s) en conflit étudiant/hôtel, à corriger
                        avant génération :
                    </p>
                    <ul class="text-[12.5px] text-rose-700 space-y-0.5 list-disc list-inside">
                        <li v-for="conflit in resultatGeneration.conflits" :key="conflit.id">
                            {{ conflit.nom }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>
