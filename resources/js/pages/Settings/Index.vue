<!-- resources/js/pages/Settings/Index.vue -->
<!--
    Page Inertia « Paramètres ». Refonte d'octobre 2026 : les sept <form> POST natifs (rechargement
    pleine page → onglet « Général » à chaque enregistrement, saisie rejetée recopiée dans TOUTES
    les lignes, aucun message de succès — cette page n'affiche pas le flash) laissent la place à
    des envois Inertia (router.visit) :

      - la page reste montée : l'onglet actif (v-model sur SettingsTabs, aussi reflété dans le #hash)
        et la saisie en cours survivent à l'enregistrement ;
      - chaque formulaire a son « error bag » (errorBag) : une erreur de la ligne « organisation 3 »
        ne s'affiche plus dans le formulaire d'ajout, ni dans les autres lignes. La prop partagée
        `old` n'a plus de consommateur et a été retirée de HandleInertiaRequests ;
      - succès → toast (flash.success), échec → erreurs au bon endroit + toast ;
      - les lignes éditables vivent dans un état local réactif (plus de <form> dans un <tr>, HTML
        invalide) ; le formulaire « Général / Itinéraires » reste un <form> lu par FormData parce que
        HqCoordinatesAutocomplete et HotelAddressAutocomplete remplissent les champs par id.
-->
<script setup lang="ts">
import { Head, router, usePage } from "@inertiajs/vue3";
import { onMounted, reactive, ref, watch } from "vue";
import { useConfirm, useToast } from "@amana/shared-ui";
import SettingsTabs from "../../components/admin/SettingsTabs.vue";
import SettingsReglageRow, { type ReglageData } from "../../components/admin/SettingsReglageRow.vue";
import HqCoordinatesAutocomplete from "../../components/admin/HqCoordinatesAutocomplete.vue";
import HotelAddressAutocomplete from "../../components/admin/HotelAddressAutocomplete.vue";

interface Vehicule {
    id: number;
    type: string;
    capacite_kg: number;
    nombre_part_max: number;
    actif: boolean;
    /** Jamais retirable (« Non véhiculé », « Sans permis »). */
    protege: boolean;
}

interface OrganisationRow {
    id: number;
    code: string;
    nom: string;
    actif: boolean;
    est_principale: boolean;
}

interface HotelAddressRow {
    id: number;
    adresse: string;
}

const props = defineProps<{
    settings: Record<string, ReglageData>;
    reglagesGeneraux: Record<string, ReglageData>;
    reglagesItineraires: Record<string, ReglageData>;
    vehicules: Vehicule[];
    organisations: OrganisationRow[];
    hotelAddresses: HotelAddressRow[];
    googlePlacesKey: string;
    updateUrl: string;
    vehiculesUpdateUrl: string;
    vehiculesStoreUrl: string;
    vehiculesDestroyUrlTemplate: string;
    vehiculesReactiverUrlTemplate: string;
    organisationsStoreUrl: string;
    organisationsUpdateUrlTemplate: string;
    hotelAddressesStoreUrl: string;
    hotelAddressesUpdateUrlTemplate: string;
    hotelAddressesDestroyUrlTemplate: string;
}>();

const page = usePage();
const toast = useToast();
const confirmDialog = useConfirm();

// ── Onglet actif : état Vue, reflété dans le #hash (rechargement manuel de la page) ──────────────
const IDS_ONGLETS = ["general", "itineraires", "vehicules", "organisations", "hotels"];
const onglet = ref("general");

onMounted(() => {
    const depuisHash = window.location.hash.replace("#", "");
    if (IDS_ONGLETS.includes(depuisHash)) onglet.value = depuisHash;
});

watch(onglet, (id) => {
    window.history.replaceState(window.history.state, "", `#${id}`);
});

// ── Erreurs par formulaire (error bags Inertia) ──────────────────────────────────────────────────
function erreur(bag: string, cle: string): string | undefined {
    const erreurs = (page.props.errors as Record<string, unknown> | undefined)?.[bag];
    if (erreurs && typeof erreurs === "object") {
        const message = (erreurs as Record<string, unknown>)[cle];
        return typeof message === "string" ? message : undefined;
    }
    return undefined;
}

/** Toutes les erreurs d'un formulaire dont la clé commence par `prefixe` (ex. « vehicules. »). */
function erreursAvecPrefixe(bag: string, prefixe: string): string[] {
    const erreurs = (page.props.errors as Record<string, unknown> | undefined)?.[bag];
    if (!erreurs || typeof erreurs !== "object") return [];
    return Object.entries(erreurs as Record<string, unknown>)
        .filter(([cle, message]) => cle.startsWith(prefixe) && typeof message === "string")
        .map(([, message]) => message as string);
}

// ── Envoi ────────────────────────────────────────────────────────────────────────────────────────
const occupes = reactive<Record<string, boolean>>({});

interface OptionsEnvoi {
    bag: string;
    onSucces?: () => void;
}

function envoyer(
    methode: "post" | "put" | "delete",
    url: string,
    donnees: Record<string, unknown> | FormData,
    options: OptionsEnvoi,
): void {
    occupes[options.bag] = true;
    router.visit(url, {
        method: methode,
        data: donnees as Record<string, never>,
        errorBag: options.bag,
        preserveScroll: true,
        preserveState: true,
        onSuccess: (nouvellePage) => {
            const succes = (nouvellePage.props as { flash?: { success?: string | null } }).flash?.success;
            if (succes) toast.success(succes);
            options.onSucces?.();
        },
        onError: () => toast.error("Enregistrement impossible : vérifiez les champs en erreur."),
        onFinish: () => {
            occupes[options.bag] = false;
        },
    });
}

/** Formulaire natif lu par FormData — voir le docblock (champs remplis par id par les widgets Google). */
function envoyerFormulaire(evenement: Event, url: string, bag: string, reinitialiser = false): void {
    const formulaire = evenement.target as HTMLFormElement;
    envoyer("post", url, new FormData(formulaire), {
        bag,
        onSucces: reinitialiser ? () => formulaire.reset() : undefined,
    });
}

/**
 * Garde l'état local des lignes éditables synchronisé avec les props, sans écraser la saisie en
 * cours d'une ligne dont les données serveur n'ont pas changé.
 */
function synchroniser<T extends { id: number }, E>(
    source: () => T[],
    cible: Record<number, E>,
    projeter: (ligne: T) => E,
): void {
    watch(
        source,
        (nouvelles, anciennes) => {
            const avant = new Map((anciennes ?? []).map((ligne) => [ligne.id, JSON.stringify(projeter(ligne))]));
            for (const ligne of nouvelles) {
                if (avant.get(ligne.id) !== JSON.stringify(projeter(ligne))) {
                    cible[ligne.id] = projeter(ligne);
                }
            }
        },
        { immediate: true },
    );
}

// ── Véhicules ────────────────────────────────────────────────────────────────────────────────────
const edVehicules = reactive<Record<number, { capacite_kg: number | string; nombre_part_max: number | string }>>({});
synchroniser(
    () => props.vehicules,
    edVehicules,
    (v) => ({ capacite_kg: v.capacite_kg, nombre_part_max: v.nombre_part_max }),
);

const nouveauVehicule = reactive({ type: "", capacite_kg: "", nombre_part_max: "" });

function enregistrerVehicules(): void {
    envoyer("post", props.vehiculesUpdateUrl, { vehicules: { ...edVehicules } }, { bag: "vehicules" });
}

function ajouterVehicule(): void {
    envoyer(
        "post",
        props.vehiculesStoreUrl,
        { ...nouveauVehicule },
        {
            bag: "vehicule-nouveau",
            onSucces: () => Object.assign(nouveauVehicule, { type: "", capacite_kg: "", nombre_part_max: "" }),
        },
    );
}

async function retirerVehicule(vehicule: Vehicule): Promise<void> {
    const ok = await confirmDialog.ask({
        title: "Retirer ce type de véhicule ?",
        message: `« ${vehicule.type} » ne sera plus proposé dans les formulaires ni les sélecteurs. Les bénévoles et tournées qui l'utilisent déjà le conservent, et vous pourrez le réactiver.`,
        confirmLabel: "Retirer",
        danger: true,
    });
    if (!ok) return;
    envoyer(
        "delete",
        props.vehiculesDestroyUrlTemplate.replace("__ID__", String(vehicule.id)),
        {},
        { bag: "vehicules-ligne" },
    );
}

function reactiverVehicule(vehicule: Vehicule): void {
    envoyer(
        "post",
        props.vehiculesReactiverUrlTemplate.replace("__ID__", String(vehicule.id)),
        {},
        { bag: "vehicules-ligne" },
    );
}

// ── Organisations ────────────────────────────────────────────────────────────────────────────────
const edOrganisations = reactive<Record<number, { nom: string; actif: boolean }>>({});
synchroniser(
    () => props.organisations,
    edOrganisations,
    (o) => ({ nom: o.nom, actif: o.actif }),
);

const nouvelleOrganisation = reactive({ code: "", nom: "" });

function enregistrerOrganisation(organisation: OrganisationRow): void {
    const saisie = edOrganisations[organisation.id];
    // La ligne principale ne peut pas être désactivée : on n'envoie pas « actif ».
    const donnees: Record<string, unknown> = organisation.est_principale
        ? { nom: saisie.nom }
        : { nom: saisie.nom, actif: saisie.actif ? 1 : 0 };
    envoyer("put", props.organisationsUpdateUrlTemplate.replace("__ID__", String(organisation.id)), donnees, {
        bag: `org-${organisation.id}`,
    });
}

function ajouterOrganisation(): void {
    envoyer(
        "post",
        props.organisationsStoreUrl,
        { ...nouvelleOrganisation },
        {
            bag: "org-nouvelle",
            onSucces: () => Object.assign(nouvelleOrganisation, { code: "", nom: "" }),
        },
    );
}

// ── Adresses hôtel ───────────────────────────────────────────────────────────────────────────────
const edHotels = reactive<Record<number, { adresse: string }>>({});
synchroniser(
    () => props.hotelAddresses,
    edHotels,
    (h) => ({ adresse: h.adresse }),
);

function enregistrerHotel(hotel: HotelAddressRow): void {
    envoyer(
        "put",
        props.hotelAddressesUpdateUrlTemplate.replace("__ID__", String(hotel.id)),
        { adresse: edHotels[hotel.id].adresse },
        {
            bag: `hotel-${hotel.id}`,
        },
    );
}

async function supprimerHotel(hotel: HotelAddressRow): Promise<void> {
    const ok = await confirmDialog.ask({
        message: "Supprimer cette adresse hôtel ? Les dossiers déjà marqués « hôtel » ne seront pas modifiés.",
        confirmLabel: "Supprimer",
        danger: true,
    });
    if (!ok) return;
    envoyer(
        "delete",
        props.hotelAddressesDestroyUrlTemplate.replace("__ID__", String(hotel.id)),
        {},
        { bag: `hotel-${hotel.id}` },
    );
}
</script>

<template>
    <Head title="Paramètres — AMANA Familles" />

    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Paramètres</h1>
            <p class="text-[13px] text-ink-muted mt-1">Réglages de l'application</p>
        </div>
    </div>

    <SettingsTabs v-model="onglet" />

    <!-- Général + Itinéraires : un seul formulaire, donc un seul « Enregistrer » — masqué sur les autres onglets
         (avant, ce bouton restait visible partout et enregistrait les réglages généraux, pas l'onglet affiché). -->
    <form
        v-show="onglet === 'general' || onglet === 'itineraires'"
        class="max-w-2xl mb-8"
        @submit.prevent="envoyerFormulaire($event, updateUrl, 'general')"
    >
        <div v-show="onglet === 'general'">
            <div class="bg-surface border border-surface-border rounded-lg divide-y divide-surface-border">
                <template v-if="Object.keys(reglagesGeneraux).length">
                    <SettingsReglageRow
                        v-for="(data, cle) in reglagesGeneraux"
                        :key="cle"
                        :cle="cle"
                        :data="data"
                        :erreur="erreur('general', `settings.${cle}`)"
                    />
                </template>
                <p v-else class="p-4 text-sm text-ink-muted">Aucun paramètre configuré pour cette application.</p>
            </div>
        </div>

        <div v-show="onglet === 'itineraires'">
            <div class="bg-surface border border-surface-border rounded-lg divide-y divide-surface-border">
                <template v-if="Object.keys(reglagesItineraires).length">
                    <SettingsReglageRow
                        v-for="(data, cle) in reglagesItineraires"
                        :key="cle"
                        :cle="cle"
                        :data="data"
                        :erreur="erreur('general', `settings.${cle}`)"
                    />
                </template>
                <p v-else class="p-4 text-sm text-ink-muted">Aucun réglage d'itinéraire configuré.</p>
            </div>

            <div class="mt-6">
                <h3 class="font-heading text-base font-semibold text-ink tracking-tight mb-1">HQ par défaut</h3>
                <p class="text-[13px] text-ink-muted mb-3">
                    Coordonnées du point de départ des tournées (local de l'association). Requises avant tout
                    clustering. Recherchez une adresse pour remplir automatiquement l'adresse et les coordonnées, ou
                    saisissez-les manuellement. Ces valeurs préremplissent chaque nouvelle campagne (modifiables ensuite
                    pour cette campagne seulement) ; l'adresse figure dans les emails de retrait au QG.
                </p>

                <HqCoordinatesAutocomplete
                    :google-places-key="googlePlacesKey"
                    target-lat-id="setting-route_hq_latitude"
                    target-lng-id="setting-route_hq_longitude"
                    target-adresse-id="setting-route_hq_adresse"
                />

                <div class="max-w-md mt-3">
                    <label for="setting-route_hq_adresse" class="block text-xs font-bold text-ink mb-1.5"
                        >Adresse</label
                    >
                    <input
                        type="text"
                        maxlength="255"
                        id="setting-route_hq_adresse"
                        name="settings[route_hq_adresse]"
                        :value="settings.route_hq_adresse?.valeur"
                        class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface text-ink"
                    />
                    <span
                        v-if="erreur('general', 'settings.route_hq_adresse')"
                        class="block text-xs text-rose-600 mt-1"
                        >{{ erreur("general", "settings.route_hq_adresse") }}</span
                    >
                </div>

                <!-- Latitude / longitude saisissables à la main (elles étaient en lecture seule alors que le
                     texte ci-dessus promet une saisie manuelle). -->
                <div class="flex gap-3 max-w-md mt-3">
                    <div class="flex-1">
                        <label for="setting-route_hq_latitude" class="block text-xs font-bold text-ink mb-1.5"
                            >Latitude</label
                        >
                        <input
                            type="number"
                            step="any"
                            id="setting-route_hq_latitude"
                            name="settings[route_hq_latitude]"
                            :value="settings.route_hq_latitude?.valeur"
                            class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface text-ink"
                        />
                    </div>
                    <div class="flex-1">
                        <label for="setting-route_hq_longitude" class="block text-xs font-bold text-ink mb-1.5"
                            >Longitude</label
                        >
                        <input
                            type="number"
                            step="any"
                            id="setting-route_hq_longitude"
                            name="settings[route_hq_longitude]"
                            :value="settings.route_hq_longitude?.valeur"
                            class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface text-ink"
                        />
                    </div>
                </div>
                <span v-if="erreur('general', 'settings.route_hq_latitude')" class="block text-xs text-rose-600 mt-1">{{
                    erreur("general", "settings.route_hq_latitude")
                }}</span>
                <span
                    v-if="erreur('general', 'settings.route_hq_longitude')"
                    class="block text-xs text-rose-600 mt-1"
                    >{{ erreur("general", "settings.route_hq_longitude") }}</span
                >
            </div>
        </div>

        <button
            type="submit"
            :disabled="occupes.general"
            class="mt-5 px-5 py-2.5 bg-accent hover:bg-accent-dark text-white font-bold text-sm rounded-lg transition-colors cursor-pointer disabled:opacity-60"
        >
            Enregistrer
        </button>
    </form>

    <!-- ── Véhicules ─────────────────────────────────────────────────────────────────────────────── -->
    <div v-show="onglet === 'vehicules'" class="max-w-2xl">
        <h2 class="font-heading text-lg font-semibold text-ink tracking-tight mb-1">Types de véhicule</h2>
        <p class="text-[13px] text-ink-muted mb-4">
            Capacité de charge et nombre de colis transportables par type de véhicule — utilisés par le formulaire de
            candidature bénévole et le moteur de répartition des livraisons. Le libellé se fixe à la création. Retirer
            un type le masque des formulaires sans toucher aux bénévoles et tournées qui l'utilisent déjà.
        </p>

        <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden mb-4">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th
                                v-for="col in ['Type', 'Capacité (kg)', 'Nb. colis max', '']"
                                :key="col"
                                class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap"
                            >
                                {{ col }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="vehicule in vehicules"
                            :key="vehicule.id"
                            class="border-b border-surface-3 last:border-0"
                            :class="vehicule.actif ? '' : 'opacity-60'"
                        >
                            <td class="px-4 py-2.5 text-ink font-semibold">
                                {{ vehicule.type }}
                                <span
                                    v-if="!vehicule.actif"
                                    class="ml-1.5 px-1.5 py-0.5 rounded bg-stone-200 text-ink-muted text-[10px] font-bold uppercase tracking-wide"
                                    >Retiré</span
                                >
                            </td>
                            <td class="px-4 py-2.5">
                                <input
                                    v-model="edVehicules[vehicule.id].capacite_kg"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    class="w-28 px-2.5 py-1.5 border border-surface-border rounded-md text-[13px] bg-surface text-ink"
                                />
                            </td>
                            <td class="px-4 py-2.5">
                                <input
                                    v-model="edVehicules[vehicule.id].nombre_part_max"
                                    type="number"
                                    step="1"
                                    min="0"
                                    class="w-24 px-2.5 py-1.5 border border-surface-border rounded-md text-[13px] bg-surface text-ink"
                                />
                            </td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                <button
                                    v-if="!vehicule.actif"
                                    type="button"
                                    class="px-3 py-1.5 rounded-full border border-surface-border text-[12px] font-medium text-ink hover:bg-surface-2 cursor-pointer"
                                    @click="reactiverVehicule(vehicule)"
                                >
                                    Réactiver
                                </button>
                                <button
                                    v-else-if="!vehicule.protege"
                                    type="button"
                                    class="px-3 py-1.5 rounded-full border border-rose-200 text-[12px] font-medium text-rose-600 hover:bg-rose-50 cursor-pointer"
                                    @click="retirerVehicule(vehicule)"
                                >
                                    Retirer
                                </button>
                                <span
                                    v-else
                                    class="text-[11px] text-ink-muted"
                                    title="Utilisé par la logique de répartition"
                                    >Non retirable</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ul v-if="erreursAvecPrefixe('vehicules', 'vehicules').length" class="px-4 pt-3 text-xs text-rose-600">
                <li v-for="message in erreursAvecPrefixe('vehicules', 'vehicules')" :key="message">{{ message }}</li>
            </ul>
            <p v-if="erreur('vehicules-ligne', 'vehicule')" class="px-4 pt-3 text-xs text-rose-600">
                {{ erreur("vehicules-ligne", "vehicule") }}
            </p>
            <div class="px-4 py-3 border-t border-surface-3">
                <button
                    type="button"
                    :disabled="occupes.vehicules"
                    class="px-4 py-2.5 bg-accent hover:bg-accent-dark text-white text-[13px] font-semibold rounded-lg transition-colors cursor-pointer min-h-[44px] disabled:opacity-60"
                    @click="enregistrerVehicules"
                >
                    Enregistrer
                </button>
            </div>
        </div>

        <form
            class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 flex flex-wrap items-end gap-3"
            @submit.prevent="ajouterVehicule"
        >
            <div class="flex-1 min-w-[10rem]">
                <label for="vehicule-type" class="block text-xs font-bold text-ink mb-1.5">Nouveau type</label>
                <input
                    id="vehicule-type"
                    v-model="nouveauVehicule.type"
                    type="text"
                    maxlength="50"
                    required
                    placeholder="ex : Camionnette"
                    class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink"
                />
                <span v-if="erreur('vehicule-nouveau', 'type')" class="block text-xs text-rose-600 mt-1">{{
                    erreur("vehicule-nouveau", "type")
                }}</span>
            </div>
            <div class="w-28">
                <label for="vehicule-capacite" class="block text-xs font-bold text-ink mb-1.5">Capacité (kg)</label>
                <input
                    id="vehicule-capacite"
                    v-model="nouveauVehicule.capacite_kg"
                    type="number"
                    step="0.01"
                    min="0"
                    required
                    class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink"
                />
                <span v-if="erreur('vehicule-nouveau', 'capacite_kg')" class="block text-xs text-rose-600 mt-1">{{
                    erreur("vehicule-nouveau", "capacite_kg")
                }}</span>
            </div>
            <div class="w-28">
                <label for="vehicule-parts" class="block text-xs font-bold text-ink mb-1.5">Nb. colis max</label>
                <input
                    id="vehicule-parts"
                    v-model="nouveauVehicule.nombre_part_max"
                    type="number"
                    step="1"
                    min="0"
                    required
                    class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink"
                />
                <span v-if="erreur('vehicule-nouveau', 'nombre_part_max')" class="block text-xs text-rose-600 mt-1">{{
                    erreur("vehicule-nouveau", "nombre_part_max")
                }}</span>
            </div>
            <button
                type="submit"
                :disabled="occupes['vehicule-nouveau']"
                class="px-5 py-2.5 bg-accent hover:bg-accent-dark text-white font-bold text-sm rounded-lg transition-colors cursor-pointer disabled:opacity-60"
            >
                + Ajouter
            </button>
        </form>
    </div>

    <!-- ── Organisations ─────────────────────────────────────────────────────────────────────────── -->
    <div v-show="onglet === 'organisations'" class="max-w-2xl">
        <h2 class="font-heading text-lg font-semibold text-ink tracking-tight mb-1">Organisations partenaires</h2>
        <p class="text-[13px] text-ink-muted mb-4">
            Organisations pouvant enregistrer des familles dans un dossier commun avec AMANA — voir le rôle
            "Gestionnaire (organisation partenaire)" dans la gestion des personnes. Liste fermée : seules les
            organisations actives apparaissent dans les formulaires publics et les imports.
        </p>

        <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden mb-4">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            v-for="col in ['Code', 'Nom', 'Statut', '']"
                            :key="col"
                            class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap"
                        >
                            {{ col }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="organisations.length === 0">
                        <td colspan="4" class="px-4 py-4 text-ink-muted text-center">Aucune organisation.</td>
                    </tr>
                    <template v-for="organisation in organisations" :key="organisation.id">
                        <tr class="border-b border-surface-3 last:border-0">
                            <td class="px-4 py-2.5 text-ink-muted font-mono text-xs">
                                {{ organisation.code }}
                                <span
                                    v-if="organisation.est_principale"
                                    class="ml-1.5 px-1.5 py-0.5 rounded bg-accent/10 text-accent text-[10px] font-bold uppercase tracking-wide"
                                    >Principale</span
                                >
                            </td>
                            <td class="px-4 py-2.5">
                                <input
                                    v-model="edOrganisations[organisation.id].nom"
                                    type="text"
                                    class="w-full px-2.5 py-1.5 border border-surface-border rounded-md text-[13px] bg-surface text-ink"
                                    @keydown.enter.prevent="enregistrerOrganisation(organisation)"
                                />
                            </td>
                            <td class="px-4 py-2.5">
                                <select
                                    v-model="edOrganisations[organisation.id].actif"
                                    :disabled="organisation.est_principale"
                                    class="px-2.5 py-1.5 border border-surface-border rounded-md text-[13px] bg-surface text-ink disabled:opacity-60"
                                >
                                    <option :value="true">Active</option>
                                    <option :value="false">Désactivée</option>
                                </select>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <button
                                    type="button"
                                    :disabled="occupes[`org-${organisation.id}`]"
                                    class="px-3 py-1.5 bg-accent hover:bg-accent-dark text-white text-[12px] font-semibold rounded-md transition-colors cursor-pointer disabled:opacity-60"
                                    @click="enregistrerOrganisation(organisation)"
                                >
                                    Enregistrer
                                </button>
                            </td>
                        </tr>
                        <!-- Erreurs de CETTE ligne seulement (error bag « org-{id} »). -->
                        <tr v-if="erreur(`org-${organisation.id}`, 'nom') || erreur(`org-${organisation.id}`, 'actif')">
                            <td colspan="4" class="px-4 pb-2.5 text-xs text-rose-600">
                                {{
                                    erreur(`org-${organisation.id}`, "nom") ?? erreur(`org-${organisation.id}`, "actif")
                                }}
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <form
            class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 flex items-end gap-3"
            @submit.prevent="ajouterOrganisation"
        >
            <div class="flex-1">
                <label for="org-code" class="block text-xs font-bold text-ink mb-1.5">Code</label>
                <input
                    id="org-code"
                    v-model="nouvelleOrganisation.code"
                    type="text"
                    required
                    placeholder="ex : secours-machin"
                    class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink"
                />
                <span v-if="erreur('org-nouvelle', 'code')" class="block text-xs text-rose-600 mt-1">{{
                    erreur("org-nouvelle", "code")
                }}</span>
            </div>
            <div class="flex-1">
                <label for="org-nom" class="block text-xs font-bold text-ink mb-1.5">Nom</label>
                <input
                    id="org-nom"
                    v-model="nouvelleOrganisation.nom"
                    type="text"
                    required
                    placeholder="ex : Secours Machin"
                    class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink"
                />
                <span v-if="erreur('org-nouvelle', 'nom')" class="block text-xs text-rose-600 mt-1">{{
                    erreur("org-nouvelle", "nom")
                }}</span>
            </div>
            <button
                type="submit"
                :disabled="occupes['org-nouvelle']"
                class="px-5 py-2.5 bg-accent hover:bg-accent-dark text-white font-bold text-sm rounded-lg transition-colors cursor-pointer disabled:opacity-60"
            >
                + Ajouter
            </button>
        </form>
    </div>

    <!-- ── Adresses hôtel ────────────────────────────────────────────────────────────────────────── -->
    <div v-show="onglet === 'hotels'" class="max-w-2xl">
        <h2 class="font-heading text-lg font-semibold text-ink tracking-tight mb-1">Adresses hôtel</h2>
        <p class="text-[13px] text-ink-muted mb-4">
            Adresses d'hébergement d'urgence connues (hôtels, appart-hôtels). Quand l'adresse d'une famille correspond à
            une entrée de cette liste, la case "hôtel" de son dossier est cochée automatiquement, même si la famille ne
            l'a pas cochée elle-même. Un même établissement peut avoir plusieurs adresses.
        </p>

        <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden mb-4">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            v-for="col in ['Adresse', '']"
                            :key="col"
                            class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap"
                        >
                            {{ col }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="hotelAddresses.length === 0">
                        <td colspan="2" class="px-4 py-4 text-ink-muted text-center">Aucune adresse enregistrée.</td>
                    </tr>
                    <template v-for="hotelAddress in hotelAddresses" :key="hotelAddress.id">
                        <tr class="border-b border-surface-3 last:border-0">
                            <td class="px-4 py-2.5">
                                <div class="flex items-center gap-2">
                                    <input
                                        v-model="edHotels[hotelAddress.id].adresse"
                                        type="text"
                                        required
                                        class="flex-1 px-2.5 py-1.5 border border-surface-border rounded-md text-[13px] bg-surface text-ink"
                                        @keydown.enter.prevent="enregistrerHotel(hotelAddress)"
                                    />
                                    <button
                                        type="button"
                                        :disabled="occupes[`hotel-${hotelAddress.id}`]"
                                        class="px-3 py-1.5 bg-accent hover:bg-accent-dark text-white text-[12px] font-semibold rounded-md transition-colors cursor-pointer whitespace-nowrap disabled:opacity-60"
                                        @click="enregistrerHotel(hotelAddress)"
                                    >
                                        Enregistrer
                                    </button>
                                </div>
                                <!-- Erreur de CETTE ligne seulement : plus de recopie de la saisie rejetée dans toutes les lignes. -->
                                <span
                                    v-if="erreur(`hotel-${hotelAddress.id}`, 'adresse')"
                                    class="block text-xs text-rose-600 mt-1"
                                    >{{ erreur(`hotel-${hotelAddress.id}`, "adresse") }}</span
                                >
                            </td>
                            <td class="px-1 py-2.5 text-right">
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-rose-200 bg-rose-50 hover:bg-rose-100 text-sm transition-colors cursor-pointer min-h-[44px] min-w-[44px]"
                                    title="Supprimer"
                                    @click="supprimerHotel(hotelAddress)"
                                >
                                    🗑️
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <form
            class="bg-surface rounded-xl border border-surface-border shadow-sm p-4"
            @submit.prevent="envoyerFormulaire($event, hotelAddressesStoreUrl, 'hotel-nouvelle', true)"
        >
            <label for="hotel-address-adresse" class="block text-xs font-bold text-ink mb-1.5">Nouvelle adresse</label>
            <div class="flex items-end gap-3">
                <div class="flex-1">
                    <input
                        type="text"
                        id="hotel-address-adresse"
                        name="adresse"
                        required
                        placeholder="ex : 12 Rue de la Johardière, 44800 Saint-Herblain"
                        class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink"
                    />
                    <span v-if="erreur('hotel-nouvelle', 'adresse')" class="block text-xs text-rose-600 mt-1">{{
                        erreur("hotel-nouvelle", "adresse")
                    }}</span>
                    <!-- Widget d'appoint : bouton "Rechercher via Google Maps" qui remplit le champ ci-dessus au lieu de
                         le remplacer — voir HotelAddressAutocomplete.vue. La saisie manuelle reste toujours possible. -->
                    <HotelAddressAutocomplete
                        :google-places-key="googlePlacesKey"
                        target-input-id="hotel-address-adresse"
                        class="mt-1.5"
                    />
                </div>
                <button
                    type="submit"
                    :disabled="occupes['hotel-nouvelle']"
                    class="px-5 py-2.5 bg-accent hover:bg-accent-dark text-white font-bold text-sm rounded-lg transition-colors cursor-pointer disabled:opacity-60"
                >
                    + Ajouter
                </button>
            </div>
        </form>
    </div>
</template>
