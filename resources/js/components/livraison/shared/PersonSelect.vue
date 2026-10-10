<!-- resources/js/components/livraison/shared/PersonSelect.vue -->
<!--
    Sélecteur de personne (staff Familles) en dropdown avec recherche — créé
    le 29/09/2026 (prompt de cette date §2.2/§3) pour remplacer PersonPicker
    (champ de recherche libre, 2 caractères minimum) partout où l'on assigne
    une personne : file de contact (assignation unitaire ET en lot),
    sections de rôle des équipes, chauffeur des tournées.

    Comportement demandé : « en tapant A, tous les membres commençant par
    A » — la recherche compare le DÉBUT du prénom OU du nom (match-mode
    "word-prefix" de SearchableSelect, amana_shared_ui), pas un « contient ».

    Construit sur SearchableSelect (déjà partagé) plutôt qu'un nouveau
    dropdown : la liste complète est chargée UNE FOIS (GET
    /livraison/personnes/recherche?tous=1, voir PickersController) puis
    filtrée côté client. Le cache est au niveau du module, par
    (role, avec_vehicule) : les ~50 lignes de la file de contact partagent
    donc une seule requête au lieu d'une par ligne.

    Contrat identique à PersonPicker — v-model = PersonneResume | null — pour
    que le remplacement soit transparent chez les appelants existants.
-->
<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { SearchableSelect, type SearchableSelectItem } from "@amana/shared-ui";
import { apiGet, buildQuery } from "./api";
import type { PersonneResume } from "./types";

const props = defineProps<{
    /** Filtre de rôle minimum, ex. 'benevole' ou 'gestionnaire' (voir Personne::hasAtLeastRole()). */
    role?: string;
    placeholder?: string;
    /** Personne déjà sélectionnée (affichage initial), le cas échéant. */
    modelValue?: PersonneResume | null;
    /** Ne proposer que les bénévoles ayant déclaré un véhicule (voir PickersController::personnes()). */
    avecVehicule?: boolean;
    /**
     * Restreint la liste aux chauffeurs CONFIRMÉS pour cette journée (09/10/2026), avec le
     * véhicule déclaré pour la journée — « Prise en charge par un chauffeur » de Suivi des
     * contacts. À défaut, `idCampagne` couvre une livraison sans journée.
     */
    idCampagneJournee?: number | null;
    idCampagne?: number | null;
}>();

const emit = defineEmits<{
    "update:modelValue": [personne: PersonneResume | null];
}>();

// Cache module : clé = paramètres de requête → la même Promise pour tous les
// composants montés en même temps (une seule requête réseau, même à 50 lignes).
const cache = new Map<string, Promise<PersonneResume[]>>();

function charger(
    role: string | undefined,
    avecVehicule: boolean,
    idCampagneJournee?: number | null,
    idCampagne?: number | null,
): Promise<PersonneResume[]> {
    const url =
        "/livraison/personnes/recherche" +
        buildQuery({
            tous: 1,
            role,
            avec_vehicule: avecVehicule || undefined,
            id_campagne_journee: idCampagneJournee ?? undefined,
            // La campagne ne sert que de repli quand la livraison n'a pas de journée.
            id_campagne: idCampagneJournee ? undefined : (idCampagne ?? undefined),
        });

    let promesse = cache.get(url);
    if (!promesse) {
        promesse = apiGet<PersonneResume[]>(url).then((resultat) => {
            if (!resultat.ok) {
                // Ne pas garder un échec en cache : le prochain montage réessaie.
                cache.delete(url);
                throw new Error(resultat.message);
            }
            return resultat.data;
        });
        cache.set(url, promesse);
    }
    return promesse;
}

const personnes = ref<PersonneResume[]>([]);
const chargement = ref(true);
const erreur = ref(false);

onMounted(async () => {
    try {
        personnes.value = await charger(
            props.role,
            props.avecVehicule ?? false,
            props.idCampagneJournee,
            props.idCampagne,
        );
    } catch {
        erreur.value = true;
    } finally {
        chargement.value = false;
    }
});

const items = computed<SearchableSelectItem[]>(() => {
    const liste = personnes.value.map((p) => ({ id: String(p.id), name: `${p.prenom} ${p.nom}` }));

    // Personne déjà sélectionnée mais absente de la liste (ex. assignée avant
    // de perdre le rôle) : on la garde affichable plutôt que de montrer son id brut.
    const courante = props.modelValue;
    if (courante && !personnes.value.some((p) => p.id === courante.id)) {
        liste.unshift({ id: String(courante.id), name: `${courante.prenom} ${courante.nom}` });
    }

    return liste;
});

const valeur = computed(() => (props.modelValue ? String(props.modelValue.id) : ""));

function onSelection(id: string | string[]) {
    const identifiant = Array.isArray(id) ? (id[0] ?? "") : id;
    const personne = personnes.value.find((p) => String(p.id) === identifiant) ?? null;
    emit("update:modelValue", personne);
}

const messageVide = computed(() => {
    if (chargement.value) return "Chargement…";
    if (erreur.value) return "Liste indisponible, réessayez.";
    if (props.idCampagneJournee || props.idCampagne) return "Aucun chauffeur confirmé pour cette journée.";
    return "Aucune personne disponible.";
});
</script>

<template>
    <SearchableSelect
        :model-value="valeur"
        :items="items"
        match-mode="word-prefix"
        :placeholder="placeholder ?? 'Sélectionner une personne…'"
        search-placeholder="Tapez le début d'un prénom ou d'un nom…"
        :empty-message="messageVide"
        @update:model-value="onSelection"
    />
</template>
