<!-- resources/js/components/livraison/campagnes/CampagneCreerForm.vue -->
<!--
    Formulaire « Nouvelle campagne » — sorti de CampagnesIndex.vue le
    03/10/2026 pour avoir sa propre page (CampagneCreer.vue). Mêmes champs,
    même POST (CampagnesController::store()), avec ces changements :

      - TOUT est affiché : plus de sections repliables, sauf « Paramètres
        avancés » (max livraisons par tournée + HQ) ;
      - le type n'a plus de valeur par défaut et est obligatoire ;
      - au moins une date est obligatoire — les lignes de date vides sont
        ignorées à l'envoi (seules les dates choisies comptent) ;
      - « Créer la campagne » ouvre d'abord une confirmation avec un
        récapitulatif de ce qui va être créé (ConfirmDialog partagé, option
        `details` — amana_shared_ui v1.8.0), puis seulement poste.
-->
<script setup lang="ts">
import { computed, ref } from "vue";
import { useConfirm, useToast } from "@amana/shared-ui";
import { apiPost } from "../shared/api";
import { CAMPAGNE_TYPES, type Campagne, type CampagneType } from "../shared/types";
import HqCoordinatesAutocomplete from "../../admin/HqCoordinatesAutocomplete.vue";
import { CAMPAGNE_TYPE_STYLES, formatDateFr } from "./campagneStyles";

const props = defineProps<{
    storeUrl: string;
    livraisonsMaxParTourneeDefaut: string;
    googlePlacesKey: string;
    // Affiché à titre indicatif à côté du champ HQ (prompt du 09/09/2026 §2.1)
    // et dans le récapitulatif quand le champ est laissé vide.
    hqGlobalDefaut: { lat: number; lng: number } | null;
}>();

const confirmDialog = useConfirm();
const toast = useToast();

interface FormJournee {
    date: string;
    label: string;
}

// Champs numériques : Vue convertit automatiquement la valeur d'un
// <input type="number"> sous v-model en NOMBRE dès qu'on tape (et ne la
// laisse en chaîne que si elle est vide : ''). Les déclarer `string` seul
// masquait ce cas au compilateur — c'est ce qui faisait planter le
// récapitulatif (kg() appelait .replace() sur un nombre) sans aucun message.
type ChampNumerique = string | number;

interface FormCampagne {
    type: CampagneType | null;
    journees: FormJournee[];
    poids_moyen_kg: ChampNumerique;
    poids_moyen_hotel_kg: ChampNumerique;
    poids_moyen_etudiant_kg: ChampNumerique;
    livraisons_max_par_tournee: ChampNumerique;
    hq_adresse: string;
    hq_latitude: ChampNumerique;
    hq_longitude: ChampNumerique;
}

function nouvelleLigneJournee(): FormJournee {
    return { date: "", label: "" };
}

const form = ref<FormCampagne>({
    type: null, // pas de valeur par défaut : choix explicite obligatoire
    journees: [nouvelleLigneJournee()],
    poids_moyen_kg: "",
    poids_moyen_hotel_kg: "",
    poids_moyen_etudiant_kg: "",
    // Préremplie depuis le réglage global ; laissée vide retombe sur le même
    // réglage côté serveur (voir store()).
    livraisons_max_par_tournee: props.livraisonsMaxParTourneeDefaut,
    hq_adresse: "",
    hq_latitude: "",
    hq_longitude: "",
});

function ajouterLigneJournee() {
    form.value.journees.push(nouvelleLigneJournee());
}

function retirerLigneJournee(index: number) {
    if (form.value.journees.length <= 1) return;
    form.value.journees.splice(index, 1);
}

// ── Complétude : ce qu'il faut pour pouvoir créer ───────────────────────
const journeesRenseignees = computed(() => form.value.journees.filter((j) => j.date !== ""));

const elementsManquants = computed(() => {
    const manquants: string[] = [];
    if (form.value.type === null) manquants.push("un type de campagne");
    if (journeesRenseignees.value.length === 0) manquants.push("au moins une date");
    if (form.value.poids_moyen_kg === "") manquants.push("le poids moyen par personne");
    return manquants;
});

const avancesOuverts = ref(false);
const fieldErrors = ref<Record<string, string[]>>({});
const messageGeneral = ref("");
const envoiEnCours = ref(false);

function erreursPourChamp(champ: string): string[] {
    return fieldErrors.value[champ] ?? [];
}

// Les clés d'erreur Laravel sont indexées sur les journées ENVOYÉES (lignes
// vides retirées) ; on les ramène à l'index affiché dans le formulaire.
const indicesEnvoyes = ref<number[]>([]);

function erreursPourChampJournee(index: number, champ: "date" | "label"): string[] {
    const indexEnvoye = indicesEnvoyes.value.indexOf(index);
    return indexEnvoye === -1 ? [] : (fieldErrors.value[`journees.${indexEnvoye}.${champ}`] ?? []);
}

// ── Récapitulatif de confirmation ───────────────────────────────────────
function kg(valeur: ChampNumerique): string {
    return `${String(valeur).replace(".", ",")} kg`;
}

function lignesRecapitulatif(): { label: string; value: string }[] {
    const f = form.value;
    const type = f.type ? CAMPAGNE_TYPES[f.type] : "—";

    const journees = journeesRenseignees.value
        .map((j) => (j.label ? `${formatDateFr(j.date)} — ${j.label}` : formatDateFr(j.date)))
        .join("\n");

    const hq = (() => {
        if (f.hq_adresse || f.hq_latitude !== "") {
            const coordonnees =
                f.hq_latitude !== "" && f.hq_longitude !== "" ? ` (${f.hq_latitude}, ${f.hq_longitude})` : "";
            return `${f.hq_adresse || "Adresse non renseignée"}${coordonnees}`;
        }
        return props.hqGlobalDefaut
            ? `Réglage global (${props.hqGlobalDefaut.lat}, ${props.hqGlobalDefaut.lng})`
            : "Non configuré";
    })();

    return [
        { label: "Type", value: type },
        { label: journeesRenseignees.value.length > 1 ? "Journées" : "Journée", value: journees },
        { label: "Poids moyen / personne", value: kg(f.poids_moyen_kg) },
        { label: "Poids moyen hôtel", value: f.poids_moyen_hotel_kg ? kg(f.poids_moyen_hotel_kg) : "Poids standard" },
        {
            label: "Poids moyen étudiant",
            value: f.poids_moyen_etudiant_kg ? kg(f.poids_moyen_etudiant_kg) : "Poids standard",
        },
        {
            label: "Livraisons max / tournée",
            value:
                f.livraisons_max_par_tournee !== ""
                    ? String(f.livraisons_max_par_tournee)
                    : `Réglage global (${props.livraisonsMaxParTourneeDefaut})`,
        },
        { label: "Point de départ (HQ)", value: hq },
    ];
}

async function demanderConfirmation() {
    if (elementsManquants.value.length > 0 || envoiEnCours.value) return;

    // try/catch : une erreur dans la construction du récapitulatif (ou dans la
    // boîte de confirmation) ne doit jamais se traduire par un bouton qui ne
    // fait « rien » — on la montre et on la garde dans la console.
    try {
        const confirme = await confirmDialog.ask({
            title: "Créer cette campagne ?",
            message:
                "Vous êtes sur le point de créer la campagne ci-dessous. Elle démarre en préparation ; " +
                "vous pourrez ensuite ajuster ses poids, son point de départ et ses journées depuis la page de la campagne.",
            details: lignesRecapitulatif(),
            confirmLabel: "Créer la campagne",
        });

        if (confirme) await creerCampagne();
    } catch (erreur) {
        console.error("[CampagneCreerForm] confirmation de création", erreur);
        toast.error("Impossible d'afficher le récapitulatif. Réessayez ou rechargez la page.");
    }
}

async function creerCampagne() {
    envoiEnCours.value = true;
    fieldErrors.value = {};
    messageGeneral.value = "";

    indicesEnvoyes.value = form.value.journees
        .map((j, index) => (j.date !== "" ? index : -1))
        .filter((index) => index !== -1);

    const resultat = await apiPost<{ success: boolean; campagne: Campagne }>(props.storeUrl, {
        type: form.value.type,
        journees: journeesRenseignees.value.map((j) => ({ date: j.date, label: j.label || null })),
        poids_moyen_kg: form.value.poids_moyen_kg,
        poids_moyen_hotel_kg: form.value.poids_moyen_hotel_kg || null,
        poids_moyen_etudiant_kg: form.value.poids_moyen_etudiant_kg || null,
        livraisons_max_par_tournee: form.value.livraisons_max_par_tournee || null,
        hq_adresse: form.value.hq_adresse || null,
        hq_latitude: form.value.hq_latitude === "" ? null : Number(form.value.hq_latitude),
        hq_longitude: form.value.hq_longitude === "" ? null : Number(form.value.hq_longitude),
    });

    envoiEnCours.value = false;

    if (!resultat.ok) {
        fieldErrors.value = resultat.errors;
        // Les erreurs de la section repliée seraient invisibles : on l'ouvre.
        if (
            Object.keys(resultat.errors).some((champ) => champ.startsWith("livraisons_max") || champ.startsWith("hq_"))
        ) {
            avancesOuverts.value = true;
        }
        messageGeneral.value = Object.keys(resultat.errors).length === 0 ? resultat.message : "";
        return;
    }

    // Navigation directe vers la campagne créée (prochaine étape : la
    // sélection des familles) — même comportement qu'avant ce déménagement.
    window.location.href = `/livraison/campagnes/${resultat.data.campagne.id}`;
}
</script>

<template>
    <!-- novalidate : la validation est faite par le formulaire (éléments manquants)
         et par le serveur (messages sous les champs). Sans lui, un champ
         invalide caché dans « Paramètres avancés » (replié) fait refuser la
         soumission par le navigateur sans rien afficher. -->
    <form class="space-y-5" novalidate @submit.prevent="demanderConfirmation">
        <!-- Type : obligatoire, sans valeur par défaut -->
        <section class="bg-surface border border-surface-border rounded-xl p-4">
            <h2 class="text-[12.5px] font-medium text-ink-muted uppercase tracking-wide mb-2">
                Type <span class="text-rose-600">*</span>
            </h2>
            <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Type de campagne">
                <button
                    v-for="(label, code) in CAMPAGNE_TYPES"
                    :key="code"
                    type="button"
                    role="radio"
                    :aria-checked="form.type === code"
                    @click="form.type = code as CampagneType"
                    class="min-h-[2.25rem] px-4 py-1.5 rounded-full text-[13px] font-medium border transition-colors"
                    :class="
                        form.type === code
                            ? CAMPAGNE_TYPE_STYLES[code as CampagneType].pastilleActive
                            : CAMPAGNE_TYPE_STYLES[code as CampagneType].pastille
                    "
                >
                    {{ label }}
                </button>
            </div>
            <p v-if="form.type === null" class="text-[11.5px] text-ink-muted mt-2">Choisissez le type de campagne.</p>
            <p v-for="e in erreursPourChamp('type')" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
        </section>

        <!-- Journées : au moins une date -->
        <section class="bg-surface border border-surface-border rounded-xl p-4">
            <h2 class="flex items-center gap-2 text-[12.5px] font-medium text-ink-muted uppercase tracking-wide mb-2">
                Journée(s) de collecte/livraison <span class="text-rose-600">*</span>
                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-accent/10 text-accent-dark">{{
                    journeesRenseignees.length
                }}</span>
            </h2>
            <div
                v-for="(journee, index) in form.journees"
                :key="index"
                class="flex flex-col sm:flex-row gap-2 sm:items-start mb-2"
            >
                <div>
                    <input
                        v-model="journee.date"
                        type="date"
                        :aria-label="`Date de la journée ${index + 1}`"
                        class="rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]"
                    />
                    <p
                        v-for="e in erreursPourChampJournee(index, 'date')"
                        :key="e"
                        class="text-[11px] text-rose-600 mt-1"
                    >
                        {{ e }}
                    </p>
                </div>
                <div class="flex-1">
                    <input
                        v-model="journee.label"
                        type="text"
                        placeholder="Label (optionnel, ex: Livraison jour 2)"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]"
                    />
                    <p
                        v-for="e in erreursPourChampJournee(index, 'label')"
                        :key="e"
                        class="text-[11px] text-rose-600 mt-1"
                    >
                        {{ e }}
                    </p>
                </div>
                <button
                    v-if="form.journees.length > 1"
                    type="button"
                    @click="retirerLigneJournee(index)"
                    aria-label="Retirer cette date"
                    class="min-h-[2.5rem] px-3 rounded-lg border border-surface-border text-ink-muted hover:bg-stone-50 shrink-0"
                >
                    ×
                </button>
            </div>
            <p v-for="e in erreursPourChamp('journees')" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
            <button
                type="button"
                @click="ajouterLigneJournee"
                class="text-[12.5px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted hover:bg-stone-50 mt-1"
            >
                + Ajouter une date
            </button>
        </section>

        <!-- Poids -->
        <section class="bg-surface border border-surface-border rounded-xl p-4">
            <h2 class="text-[12.5px] font-medium text-ink-muted uppercase tracking-wide mb-2">Poids</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1"
                        >Poids moyen / personne (kg) <span class="text-rose-600">*</span></label
                    >
                    <input
                        v-model="form.poids_moyen_kg"
                        type="number"
                        step="0.1"
                        min="0"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]"
                    />
                    <p v-for="e in erreursPourChamp('poids_moyen_kg')" :key="e" class="text-[11px] text-rose-600 mt-1">
                        {{ e }}
                    </p>
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">— hôtel (kg, optionnel)</label>
                    <input
                        v-model="form.poids_moyen_hotel_kg"
                        type="number"
                        step="0.1"
                        min="0"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]"
                    />
                    <p
                        v-for="e in erreursPourChamp('poids_moyen_hotel_kg')"
                        :key="e"
                        class="text-[11px] text-rose-600 mt-1"
                    >
                        {{ e }}
                    </p>
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">— étudiant (kg, optionnel)</label>
                    <input
                        v-model="form.poids_moyen_etudiant_kg"
                        type="number"
                        step="0.1"
                        min="0"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]"
                    />
                    <p
                        v-for="e in erreursPourChamp('poids_moyen_etudiant_kg')"
                        :key="e"
                        class="text-[11px] text-rose-600 mt-1"
                    >
                        {{ e }}
                    </p>
                </div>
            </div>
            <p class="text-[11px] text-ink-muted mt-2">Hôtel/étudiant laissés vides : le poids standard s'applique.</p>
        </section>

        <!-- Paramètres avancés : seule section repliable -->
        <details
            class="group bg-surface border border-surface-border rounded-xl p-4"
            :open="avancesOuverts"
            @toggle="avancesOuverts = ($event.target as HTMLDetailsElement).open"
        >
            <summary
                class="cursor-pointer list-none flex items-center justify-between select-none -mx-1 -my-1 px-1 py-1 rounded-lg hover:bg-surface-2 transition-colors"
            >
                <h2 class="text-[12.5px] font-medium text-ink-muted uppercase tracking-wide">Paramètres avancés</h2>
                <span class="text-ink-muted text-[13px] transition-transform duration-200 group-open:rotate-180"
                    >▾</span
                >
            </summary>
            <div class="max-w-xs mt-3">
                <label class="block text-[12px] text-ink-muted mb-1">Nombre maximum de livraisons par tournée</label>
                <input
                    v-model="form.livraisons_max_par_tournee"
                    type="number"
                    min="1"
                    step="1"
                    class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]"
                />
                <p class="text-[11px] text-ink-muted mt-1">
                    Préremplie depuis les réglages, modifiable pour cette campagne uniquement.
                </p>
                <p
                    v-for="e in erreursPourChamp('livraisons_max_par_tournee')"
                    :key="e"
                    class="text-[11px] text-rose-600 mt-1"
                >
                    {{ e }}
                </p>
            </div>

            <!-- HQ : entièrement optionnel, vide = réglage global (voir store()) -->
            <div class="max-w-xs mt-4">
                <label class="block text-[12px] text-ink-muted mb-1">Adresse HQ (optionnel)</label>
                <input
                    v-model="form.hq_adresse"
                    type="text"
                    class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]"
                />
                <p class="text-[11px] text-ink-muted mt-1">
                    Laissée vide, cette campagne utilisera le réglage global
                    <template v-if="hqGlobalDefaut">({{ hqGlobalDefaut.lat }}, {{ hqGlobalDefaut.lng }})</template>
                    <template v-else
                        >— non configuré actuellement, pensez à le renseigner ci-dessous ou dans les réglages.</template
                    >
                </p>
            </div>
            <HqCoordinatesAutocomplete
                :google-places-key="googlePlacesKey"
                target-lat-id="nouvelle-campagne-hq-lat"
                target-lng-id="nouvelle-campagne-hq-lng"
            />
            <div class="max-w-xs grid grid-cols-2 gap-3 mt-2">
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Latitude</label>
                    <input
                        id="nouvelle-campagne-hq-lat"
                        v-model="form.hq_latitude"
                        type="number"
                        step="any"
                        readonly
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem] bg-stone-50 text-ink-muted"
                    />
                </div>
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Longitude</label>
                    <input
                        id="nouvelle-campagne-hq-lng"
                        v-model="form.hq_longitude"
                        type="number"
                        step="any"
                        readonly
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem] bg-stone-50 text-ink-muted"
                    />
                </div>
            </div>
        </details>

        <div>
            <button
                type="submit"
                :disabled="envoiEnCours || elementsManquants.length > 0"
                class="w-full sm:w-auto min-h-[2.5rem] rounded-lg bg-accent text-white text-[14px] font-medium px-4 py-2 disabled:opacity-50 disabled:cursor-not-allowed"
            >
                {{ envoiEnCours ? "Création…" : "Créer la campagne" }}
            </button>
            <p v-if="elementsManquants.length > 0" class="text-[12px] text-amber-700 mt-2">
                Il manque : {{ elementsManquants.join(", ") }}.
            </p>
            <p v-if="messageGeneral" class="text-[12.5px] text-rose-600 mt-2">{{ messageGeneral }}</p>
        </div>
    </form>
</template>
