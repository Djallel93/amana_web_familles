<!-- resources/js/components/livraison/tableau-de-bord/BuildRouteFlow.vue -->
<!--
    "Construire une tournée personnalisée" — remplace les trois inputs
    numériques bruts (ID bénévole / ID véhicule / liste d'IDs livraisons
    séparés par des virgules) par un vrai flux guidé.

    Révisé le 09/09/2026 (prompt de cette date §5.1) :
      - section repliée par défaut (§5.1.1), même patron <details>/
        <summary> que FamilleFilterPanel.vue ;
      - VehiculePicker RETIRÉ (§5.1.2 : "driver info already includes a
        vehicule") — PersonPicker filtre déjà sur avec-vehicule, le
        véhicule est dérivé du profil du bénévole choisi ;
      - "Livraisons à inclure" n'est plus une checklist brute mais une
        vraie table filtrable/paginée (§5.1.3 : "Use the same layout and
        the same filter collapsable filter panel" que la sélection des
        familles éligibles de CampagneDetail.vue) — même
        FamilleFilterPanel partagé, même sélection croisant les pages via
        ids_only, backée par LiveBoardController::nonCouvertesTable().
-->
<script setup lang="ts">
import { ref, reactive } from 'vue';
import { useToast } from '@amana/shared-ui';
import { apiGet, apiPost, buildQuery } from '../shared/api';
import Paginator from '../shared/Paginator.vue';
import FamilleFilterPanel from '../shared/FamilleFilterPanel.vue';
import PersonPicker from '../shared/PersonPicker.vue';
import {
    CRENEAUX,
    CRENEAU_LIBELLES,
    normalizePaginated,
    type FamilleEligible,
    type FamilleFiltres,
    type Organisation,
    type Paginated,
    type PersonneResume,
    type Quartier,
    type RawLaravelPaginator,
    type Secteur,
    type Ville,
} from '../shared/types';

const props = defineProps<{
    campagneId: string;
    villes: Ville[];
    secteurs: Secteur[];
    quartiers: Quartier[];
    organisations: Organisation[];
    urlNonCouvertesTableau: string;
    url: string;
}>();

const emit = defineEmits<{ created: [] }>();

const toast = useToast();

function formatDateFr(iso: string): string {
    const [annee, mois, jour] = iso.split('T')[0].split('-');
    return `${jour}/${mois}/${annee}`;
}

const benevole = ref<PersonneResume | null>(null);
const creneau = ref('');
const envoiEnCours = ref(false);
const erreurs = ref<Record<string, string[]>>({});

// ── Table filtrable/paginée des livraisons non couvertes (09/09/2026,
//    prompt §5.1.3) — même patron que la table éligibles de
//    CampagneDetail.vue, sur LiveBoardController::nonCouvertesTable(). ──
const filtres = ref<FamilleFiltres>({});
const tri = ref<string | null>(null);
const directionTri = ref<'asc' | 'desc'>('asc');
const lignes = ref<FamilleEligible[]>([]);
const metaLignes = ref<Paginated<FamilleEligible>['meta'] | null>(null);
const chargementLignes = ref(true);
const erreurLignes = ref(false);
const chargementSelectionTout = ref(false);

// La sélection porte sur des id_livraison (pas des id de famille) — c'est
// ce que ids_livraisons attend côté serveur (voir construire() ci-dessous).
const idsLivraisons = reactive<Set<number>>(new Set());

function trierPar(colonne: string) {
    if (tri.value === colonne) {
        directionTri.value = directionTri.value === 'asc' ? 'desc' : 'asc';
    } else {
        tri.value = colonne;
        directionTri.value = 'asc';
    }
    chargerLignes(1);
}

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

async function chargerLignes(page = 1) {
    if (!props.urlNonCouvertesTableau) return;
    chargementLignes.value = true;
    erreurLignes.value = false;

    const resultat = await apiGet<RawLaravelPaginator<FamilleEligible>>(`${props.urlNonCouvertesTableau}${queryFiltres(page)}`);
    chargementLignes.value = false;

    if (!resultat.ok) {
        erreurLignes.value = true;
        return;
    }

    const paginé = normalizePaginated(resultat.data);
    lignes.value = paginé.data;
    metaLignes.value = paginé.meta;
}

function toggleLivraison(idLivraison: number | undefined) {
    if (idLivraison === undefined) return;
    if (idsLivraisons.has(idLivraison)) idsLivraisons.delete(idLivraison);
    else idsLivraisons.add(idLivraison);
}

async function toutSelectionnerFiltre() {
    const pageActuelleIds = lignes.value.map((f) => f.id_livraison).filter((id): id is number => id !== undefined);
    const dejaToutSelectionne = pageActuelleIds.length > 0 && pageActuelleIds.every((id) => idsLivraisons.has(id));

    if (dejaToutSelectionne) {
        for (const id of pageActuelleIds) idsLivraisons.delete(id);
        return;
    }

    chargementSelectionTout.value = true;
    const resultat = await apiGet<{ ids: number[] }>(`${props.urlNonCouvertesTableau}${queryFiltres(1)}&ids_only=1`);
    chargementSelectionTout.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    resultat.data.ids.forEach((id) => idsLivraisons.add(id));
}

if (props.urlNonCouvertesTableau) chargerLignes(1);

async function construire() {
    erreurs.value = {};

    if (!benevole.value?.id_vehicule_type || idsLivraisons.size === 0) {
        toast.error('Choisissez un bénévole (avec véhicule déclaré) et au moins une livraison.');
        return;
    }

    envoiEnCours.value = true;
    // id_vehicule_type dérivé du profil du bénévole choisi (09/09/2026,
    // prompt §5.1.2) — plus de VehiculePicker séparé.
    const resultat = await apiPost<{ success: boolean }>(props.url, {
        id_benevole: benevole.value.id,
        id_vehicule_type: benevole.value.id_vehicule_type,
        ids_livraisons: [...idsLivraisons],
        creneau: creneau.value || null,
    });
    envoiEnCours.value = false;

    if (!resultat.ok) {
        erreurs.value = resultat.errors;
        if (Object.keys(resultat.errors).length === 0) toast.error(resultat.message);
        return;
    }

    toast.success('Tournée créée.');
    benevole.value = null;
    idsLivraisons.clear();
    creneau.value = '';
    emit('created');
    chargerLignes(metaLignes.value?.current_page ?? 1);
}
</script>

<template>
    <details class="group mb-8 bg-surface border border-surface-border rounded-xl p-5">
        <summary class="cursor-pointer list-none flex items-center justify-between select-none -mx-1 -my-1 px-1 py-1 mb-2 rounded-lg hover:bg-surface-2 transition-colors">
            <h2 class="text-[14px] font-medium text-ink">Construire une tournée personnalisée</h2>
            <span class="text-ink-muted text-[13px] transition-transform duration-200 group-open:rotate-180">▾</span>
        </summary>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4 mt-2">
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Bénévole (avec véhicule déclaré)</label>
                <PersonPicker role="benevole" avec-vehicule placeholder="Rechercher un bénévole…" v-model="benevole" />
            </div>
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Créneau (optionnel)</label>
                <select v-model="creneau" class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]">
                    <option value="">— Aucun —</option>
                    <option v-for="c in CRENEAUX" :key="c" :value="c">{{ CRENEAU_LIBELLES[c] }}</option>
                </select>
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-[12px] text-ink-muted mb-1.5">
                Livraisons à inclure ({{ idsLivraisons.size }} sélectionnée{{ idsLivraisons.size > 1 ? 's' : '' }})
            </label>

            <FamilleFilterPanel :villes="villes" :secteurs="secteurs" :quartiers="quartiers" :organisations="organisations"
                :model-value="filtres" @update:model-value="filtres = $event" @filtrer="chargerLignes(1)" />

            <div class="overflow-x-auto mb-2 border border-surface-border rounded-lg">
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="text-left text-ink-muted border-b border-surface-border bg-stone-50">
                            <th class="px-3 py-2 font-medium">
                                <input type="checkbox"
                                    :checked="lignes.length > 0 && lignes.every((f) => idsLivraisons.has(f.id_livraison as number))"
                                    :disabled="chargementSelectionTout"
                                    @change="toutSelectionnerFiltre" class="w-4 h-4 accent-accent">
                            </th>
                            <th class="px-3 py-2 font-medium cursor-pointer select-none" @click="trierPar('id')">
                                ID <span v-if="tri === 'id'">{{ directionTri === 'asc' ? '▲' : '▼' }}</span>
                            </th>
                            <th class="px-3 py-2 font-medium cursor-pointer select-none" @click="trierPar('nom')">
                                Nom <span v-if="tri === 'nom'">{{ directionTri === 'asc' ? '▲' : '▼' }}</span>
                            </th>
                            <th class="px-3 py-2 font-medium cursor-pointer select-none" @click="trierPar('telephone')">
                                Contact <span v-if="tri === 'telephone'">{{ directionTri === 'asc' ? '▲' : '▼' }}</span>
                            </th>
                            <th class="px-3 py-2 font-medium">Adresse</th>
                            <th class="px-3 py-2 font-medium cursor-pointer select-none" @click="trierPar('criticite')">
                                Criticité <span v-if="tri === 'criticite'">{{ directionTri === 'asc' ? '▲' : '▼' }}</span>
                            </th>
                            <th class="px-3 py-2 font-medium cursor-pointer select-none" @click="trierPar('derniere_livraison_le')">
                                Dernière livraison <span v-if="tri === 'derniere_livraison_le'">{{ directionTri === 'asc' ? '▲' : '▼' }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="chargementLignes"><td colspan="7" class="px-3 py-3 text-ink-muted">Chargement…</td></tr>
                        <tr v-else-if="erreurLignes"><td colspan="7" class="px-3 py-3 text-rose-600">Impossible de charger les livraisons.</td></tr>
                        <tr v-else-if="lignes.length === 0"><td colspan="7" class="px-3 py-3 text-ink-muted">Aucune livraison non couverte pour ces filtres.</td></tr>
                        <tr v-for="famille in lignes" :key="famille.id_livraison ?? famille.id" class="border-b border-surface-border last:border-0 hover:bg-stone-50">
                            <td class="px-3 py-2">
                                <input type="checkbox" :checked="idsLivraisons.has(famille.id_livraison as number)" @change="toggleLivraison(famille.id_livraison)"
                                    class="w-4 h-4 accent-accent">
                            </td>
                            <td class="px-3 py-2 text-ink-muted">#{{ famille.id }}</td>
                            <td class="px-3 py-2 text-ink">{{ famille.prenom }} {{ famille.nom }}</td>
                            <td class="px-3 py-2 text-ink-muted">
                                {{ famille.telephone || '—' }}
                                <span v-if="famille.telephone_bis"> / {{ famille.telephone_bis }}</span>
                            </td>
                            <td class="px-3 py-2 text-ink-muted">
                                {{ famille.adresse || '—' }}
                                <span v-if="famille.quartier">— {{ famille.quartier.nom }}</span>
                            </td>
                            <td class="px-3 py-2 text-ink-muted">{{ famille.criticite ?? '—' }}</td>
                            <td class="px-3 py-2 text-ink-muted">
                                {{ famille.derniere_livraison_le ? formatDateFr(famille.derniere_livraison_le) : 'jamais livrée' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Paginator v-if="metaLignes" :meta="metaLignes" @change="chargerLignes" />
            <p v-for="e in erreurs.ids_livraisons ?? []" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
        </div>

        <button type="button" :disabled="envoiEnCours" @click="construire"
            class="min-h-[2.5rem] text-[13px] px-4 py-2 rounded-lg bg-accent text-white disabled:opacity-60">
            {{ envoiEnCours ? 'Création…' : 'Créer la tournée' }}
        </button>
    </details>
</template>
