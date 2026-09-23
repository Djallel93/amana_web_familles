<!-- resources/js/pages/Settings/Index.vue -->
<!--
    Page Inertia "Paramètres" — Section E4 du refactor (16/09/2026,
    chunk settings, dernier de la section), remplace resources/views/
    settings/index.blade.php (supprimée dans ce même chunk, plus aucun
    consommateur une fois SettingsController::index() converti en
    Inertia::render()).

    À la différence de TOUTE autre page convertie dans ce refactor, les
    sept <form> ci-dessous restent des soumissions natives classiques :
    SettingsControllerBase::update() (vendor), VehiculeTypesController::
    update(), Admin\OrganisationsController::store()/update()/destroy(),
    Admin\HotelAddressesController::store()/update()/destroy() ne sont
    PAS convertis — Inertia n'intercepte jamais un <form> HTML natif
    (seuls <Link>/router.* le sont), donc leur redirection pleine page
    continue de fonctionner sans rien y changer. Voir le docblock de
    SettingsController::index() pour le détail de cette décision.

    old()/@error() Blade (utilisés PARTOUT sur cette page — réglages
    génériques, HQ, véhicules, organisations, adresses hôtel) sont
    devenus des lectures de usePage().props.old/errors : 'errors' est
    partagé automatiquement par la classe Middleware Inertia de base,
    'old' a été ajouté spécifiquement pour cette page dans
    HandleInertiaRequests::share() (ce même chunk) — Inertia ne le
    partage PAS par défaut, contrairement à 'errors'.

    $defaultTab n'est plus calculé côté serveur (ancien @php de la
    Blade) : recalculé ici en TypeScript depuis la prop 'errors', même
    logique de préfixes de clé portée telle quelle.

    Le jeton CSRF de chaque <form> est lu depuis <meta name="csrf-token">
    (posée par la racine Blade), comme le fait déjà shared/api.ts pour
    les requêtes XHR de l'app — pas de différence entre un <form> natif
    sur une page Inertia et un <form> natif sur une page Blade classique
    de ce point de vue.

    SettingsTabs.vue/HqCoordinatesAutocomplete.vue/
    HotelAddressAutocomplete.vue sont désormais des enfants Vue normaux
    plutôt que des îlots séparés montés par app.ts — les deux derniers
    perdent dans ce même chunk leur repli sur point de montage Blade
    (voir leurs propres docblocks), cette page étant leur dernier
    consommateur sous cette forme.
-->
<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import SettingsTabs from '../../components/admin/SettingsTabs.vue';
import SettingsReglageRow, { type ReglageData } from '../../components/admin/SettingsReglageRow.vue';
import HqCoordinatesAutocomplete from '../../components/admin/HqCoordinatesAutocomplete.vue';
import HotelAddressAutocomplete from '../../components/admin/HotelAddressAutocomplete.vue';

interface Vehicule {
    id: number;
    type: string;
    capacite_kg: number;
    nombre_part_max: number;
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

defineProps<{
    settings: Record<string, ReglageData>;
    reglagesGeneraux: Record<string, ReglageData>;
    reglagesItineraires: Record<string, ReglageData>;
    vehicules: Vehicule[];
    organisations: OrganisationRow[];
    hotelAddresses: HotelAddressRow[];
    googlePlacesKey: string;
    updateUrl: string;
    vehiculesUpdateUrl: string;
    organisationsStoreUrl: string;
    organisationsUpdateUrlTemplate: string;
    hotelAddressesStoreUrl: string;
    hotelAddressesUpdateUrlTemplate: string;
    hotelAddressesDestroyUrlTemplate: string;
}>();

const page = usePage<{ errors: Record<string, string>; old: Record<string, any> }>();

// Même logique de préfixes que l'ancien @php de settings/index.blade.php,
// portée telle quelle depuis $errors->keys() vers la prop 'errors'.
const defaultTab = computed(() => {
    const cles = Object.keys(page.props.errors ?? {});
    if (cles.some((cle) => cle.startsWith('settings.route_'))) return 'itineraires';
    if (cles.includes('code') || cles.includes('nom')) return 'organisations';
    if (cles.includes('adresse')) return 'hotels';
    if (cles.some((cle) => cle.startsWith('vehicules.'))) return 'vehicules';
    return 'general';
});

function erreur(cle: string): string | undefined {
    return page.props.errors?.[cle];
}

function oldSettings(cle: string): unknown {
    return page.props.old?.settings?.[cle];
}

function oldVehicule(id: number, champ: 'capacite_kg' | 'nombre_part_max'): unknown {
    return page.props.old?.vehicules?.[String(id)]?.[champ];
}

const oldCode = computed(() => page.props.old?.code as string | undefined);
const oldNom = computed(() => page.props.old?.nom as string | undefined);
const oldAdresse = computed(() => page.props.old?.adresse as string | undefined);

// Voir le docblock ci-dessus : les <form> de cette page restent des
// soumissions natives, le jeton CSRF est lu comme partout ailleurs dans
// l'app pour ce cas de figure (shared/api.ts, formulaires publics).
const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
</script>

<template>
    <Head title="Paramètres — AMANA Familles" />

    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="font-heading text-2xl font-semibold text-ink tracking-tight">Paramètres</h1>
            <p class="text-[13px] text-ink-muted mt-1">Réglages de l'application</p>
        </div>
    </div>

    <SettingsTabs :default-tab="defaultTab" />

    <form :action="updateUrl" method="POST" class="max-w-2xl mb-8">
        <input type="hidden" name="_token" :value="csrfToken">

        <div data-settings-tab="general">
            <div class="bg-surface border border-surface-border rounded-lg divide-y divide-surface-border">
                <template v-if="Object.keys(reglagesGeneraux).length">
                    <SettingsReglageRow v-for="(data, cle) in reglagesGeneraux" :key="cle" :cle="cle" :data="data"
                        :old-valeur="oldSettings(cle)" :erreur="erreur(`settings.${cle}`)" />
                </template>
                <p v-else class="p-4 text-sm text-ink-muted">Aucun paramètre configuré pour cette application.</p>
            </div>
        </div>

        <div data-settings-tab="itineraires">
            <div class="bg-surface border border-surface-border rounded-lg divide-y divide-surface-border">
                <template v-if="Object.keys(reglagesItineraires).length">
                    <SettingsReglageRow v-for="(data, cle) in reglagesItineraires" :key="cle" :cle="cle" :data="data"
                        :old-valeur="oldSettings(cle)" :erreur="erreur(`settings.${cle}`)" />
                </template>
                <p v-else class="p-4 text-sm text-ink-muted">Aucun réglage d'itinéraire configuré.</p>
            </div>

            <div class="mt-6">
                <h3 class="font-heading text-base font-semibold text-ink tracking-tight mb-1">HQ par défaut</h3>
                <p class="text-[13px] text-ink-muted mb-3">
                    Coordonnées du point de départ des tournées (local de l'association). Requises avant tout
                    clustering. Recherchez une adresse pour remplir automatiquement les coordonnées, ou
                    saisissez-les manuellement — seules les coordonnées sont conservées, pas l'adresse recherchée.
                </p>

                <HqCoordinatesAutocomplete :google-places-key="googlePlacesKey" target-lat-id="setting-route_hq_latitude"
                    target-lng-id="setting-route_hq_longitude" />

                <div class="flex gap-3 max-w-md mt-3">
                    <div class="flex-1">
                        <label for="setting-route_hq_latitude" class="block text-xs font-bold text-ink mb-1.5">Latitude</label>
                        <input type="number" step="any" id="setting-route_hq_latitude" name="settings[route_hq_latitude]"
                            :value="oldSettings('route_hq_latitude') ?? settings.route_hq_latitude?.valeur" readonly
                            class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink-muted">
                    </div>
                    <div class="flex-1">
                        <label for="setting-route_hq_longitude" class="block text-xs font-bold text-ink mb-1.5">Longitude</label>
                        <input type="number" step="any" id="setting-route_hq_longitude" name="settings[route_hq_longitude]"
                            :value="oldSettings('route_hq_longitude') ?? settings.route_hq_longitude?.valeur" readonly
                            class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink-muted">
                    </div>
                </div>
                <span v-if="erreur('settings.route_hq_latitude')" class="block text-xs text-rose-600 mt-1">{{ erreur('settings.route_hq_latitude') }}</span>
                <span v-if="erreur('settings.route_hq_longitude')" class="block text-xs text-rose-600 mt-1">{{ erreur('settings.route_hq_longitude') }}</span>
            </div>
        </div>

        <button type="submit"
            class="mt-5 px-5 py-2.5 bg-accent hover:bg-accent-dark text-white font-bold text-sm rounded-lg transition-colors cursor-pointer">
            Enregistrer
        </button>
    </form>

    <div class="max-w-2xl" data-settings-tab="vehicules">
        <h2 class="font-heading text-lg font-semibold text-ink tracking-tight mb-1">Types de véhicule</h2>
        <p class="text-[13px] text-ink-muted mb-4">
            Capacité de charge et nombre de colis transportables par type de véhicule — utilisés par le
            formulaire de candidature bénévole et le futur moteur de répartition des livraisons.
            Le libellé de chaque type n'est pas modifiable ici.
        </p>

        <form :action="vehiculesUpdateUrl" method="POST"
            class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden">
            <input type="hidden" name="_token" :value="csrfToken">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <thead>
                        <tr>
                            <th v-for="col in ['Type', 'Capacité (kg)', 'Nb. colis max']" :key="col"
                                class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                                {{ col }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="vehicule in vehicules" :key="vehicule.id" class="border-b border-surface-3 last:border-0">
                            <td class="px-4 py-2.5 text-ink font-semibold">{{ vehicule.type }}</td>
                            <td class="px-4 py-2.5">
                                <input type="number" step="0.01" min="0" :name="`vehicules[${vehicule.id}][capacite_kg]`"
                                    :value="oldVehicule(vehicule.id, 'capacite_kg') ?? vehicule.capacite_kg"
                                    class="w-28 px-2.5 py-1.5 border border-surface-border rounded-md text-[13px] bg-surface text-ink">
                            </td>
                            <td class="px-4 py-2.5">
                                <input type="number" step="1" min="0" :name="`vehicules[${vehicule.id}][nombre_part_max]`"
                                    :value="oldVehicule(vehicule.id, 'nombre_part_max') ?? vehicule.nombre_part_max"
                                    class="w-24 px-2.5 py-1.5 border border-surface-border rounded-md text-[13px] bg-surface text-ink">
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-surface-3">
                <button type="submit"
                    class="px-4 py-2.5 bg-accent hover:bg-accent-dark text-white text-[13px] font-semibold rounded-lg transition-colors cursor-pointer min-h-[44px]">
                    Enregistrer
                </button>
            </div>
        </form>
    </div>

    <div class="max-w-2xl mt-10" data-settings-tab="organisations">
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
                        <th v-for="col in ['Code', 'Nom', 'Statut', '']" :key="col"
                            class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                            {{ col }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="organisation in organisations" :key="organisation.id" class="border-b border-surface-3 last:border-0">
                        <form :action="organisationsUpdateUrlTemplate.replace('__ID__', String(organisation.id))"
                            method="POST" class="contents">
                            <input type="hidden" name="_token" :value="csrfToken">
                            <input type="hidden" name="_method" value="PUT">
                            <td class="px-4 py-2.5 text-ink-muted font-mono text-xs">
                                {{ organisation.code }}
                                <span v-if="organisation.est_principale"
                                    class="ml-1.5 px-1.5 py-0.5 rounded bg-accent/10 text-accent text-[10px] font-bold uppercase tracking-wide">Principale</span>
                            </td>
                            <td class="px-4 py-2.5">
                                <input type="text" name="nom" :value="oldNom ?? organisation.nom"
                                    class="w-full px-2.5 py-1.5 border border-surface-border rounded-md text-[13px] bg-surface text-ink">
                            </td>
                            <td class="px-4 py-2.5">
                                <select name="actif" :disabled="organisation.est_principale"
                                    class="px-2.5 py-1.5 border border-surface-border rounded-md text-[13px] bg-surface text-ink disabled:opacity-60">
                                    <option value="1" :selected="organisation.actif">Active</option>
                                    <option value="0" :selected="!organisation.actif">Désactivée</option>
                                </select>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <button type="submit"
                                    class="px-3 py-1.5 bg-accent hover:bg-accent-dark text-white text-[12px] font-semibold rounded-md transition-colors cursor-pointer">
                                    Enregistrer
                                </button>
                            </td>
                        </form>
                    </tr>
                </tbody>
            </table>
        </div>

        <form :action="organisationsStoreUrl" method="POST"
            class="bg-surface rounded-xl border border-surface-border shadow-sm p-4 flex items-end gap-3">
            <input type="hidden" name="_token" :value="csrfToken">
            <div class="flex-1">
                <label for="org-code" class="block text-xs font-bold text-ink mb-1.5">Code</label>
                <input type="text" id="org-code" name="code" :value="oldCode" required placeholder="ex : secours-machin"
                    class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink">
                <span v-if="erreur('code')" class="block text-xs text-rose-600 mt-1">{{ erreur('code') }}</span>
            </div>
            <div class="flex-1">
                <label for="org-nom" class="block text-xs font-bold text-ink mb-1.5">Nom</label>
                <input type="text" id="org-nom" name="nom" :value="oldNom" required placeholder="ex : Secours Machin"
                    class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink">
                <span v-if="erreur('nom')" class="block text-xs text-rose-600 mt-1">{{ erreur('nom') }}</span>
            </div>
            <button type="submit"
                class="px-5 py-2.5 bg-accent hover:bg-accent-dark text-white font-bold text-sm rounded-lg transition-colors cursor-pointer">
                + Ajouter
            </button>
        </form>
    </div>

    <div class="max-w-2xl mt-10" data-settings-tab="hotels">
        <h2 class="font-heading text-lg font-semibold text-ink tracking-tight mb-1">Adresses hôtel</h2>
        <p class="text-[13px] text-ink-muted mb-4">
            Adresses d'hébergement d'urgence connues (hôtels, appart-hôtels). Quand l'adresse d'une famille
            correspond à une entrée de cette liste, la case "hôtel" de son dossier est cochée automatiquement,
            même si la famille ne l'a pas cochée elle-même. Un même établissement peut avoir plusieurs adresses.
        </p>

        <div class="bg-surface rounded-xl border border-surface-border shadow-sm overflow-hidden mb-4">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th v-for="col in ['Adresse', '']" :key="col"
                            class="text-left px-4 py-2.5 text-[10.5px] font-bold text-ink-muted uppercase tracking-[0.6px] bg-surface-2 border-b border-surface-3 whitespace-nowrap">
                            {{ col }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="hotelAddresses.length === 0">
                        <td colspan="2" class="px-4 py-4 text-ink-muted text-center">Aucune adresse enregistrée.</td>
                    </tr>
                    <tr v-for="hotelAddress in hotelAddresses" :key="hotelAddress.id" class="border-b border-surface-3 last:border-0">
                        <td class="px-4 py-2.5">
                            <form :action="hotelAddressesUpdateUrlTemplate.replace('__ID__', String(hotelAddress.id))"
                                method="POST" class="flex items-center gap-2">
                                <input type="hidden" name="_token" :value="csrfToken">
                                <input type="hidden" name="_method" value="PUT">
                                <input type="text" name="adresse" :value="oldAdresse ?? hotelAddress.adresse" required
                                    class="flex-1 px-2.5 py-1.5 border border-surface-border rounded-md text-[13px] bg-surface text-ink">
                                <button type="submit"
                                    class="px-3 py-1.5 bg-accent hover:bg-accent-dark text-white text-[12px] font-semibold rounded-md transition-colors cursor-pointer whitespace-nowrap">
                                    Enregistrer
                                </button>
                            </form>
                        </td>
                        <td class="px-1 py-2.5 text-right">
                            <form :action="hotelAddressesDestroyUrlTemplate.replace('__ID__', String(hotelAddress.id))"
                                method="POST" data-confirm="Supprimer cette adresse hôtel ? Les dossiers déjà marqués « hôtel » ne seront pas modifiés."
                                data-confirm-danger data-confirm-label="Supprimer">
                                <input type="hidden" name="_token" :value="csrfToken">
                                <input type="hidden" name="_method" value="DELETE">
                                <button type="submit"
                                    class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-rose-200 bg-rose-50 hover:bg-rose-100 text-sm transition-colors cursor-pointer min-h-[44px] min-w-[44px]"
                                    title="Supprimer">🗑️</button>
                            </form>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <form :action="hotelAddressesStoreUrl" method="POST"
            class="bg-surface rounded-xl border border-surface-border shadow-sm p-4">
            <input type="hidden" name="_token" :value="csrfToken">
            <label for="hotel-address-adresse" class="block text-xs font-bold text-ink mb-1.5">Nouvelle adresse</label>
            <div class="flex items-end gap-3">
                <div class="flex-1">
                    <input type="text" id="hotel-address-adresse" name="adresse" :value="oldAdresse" required
                        placeholder="ex : 12 Rue de la Johardière, 44800 Saint-Herblain"
                        class="w-full px-3 py-2 border-[1.5px] border-ink-faint rounded-lg text-sm bg-surface-2 text-ink">
                    <span v-if="erreur('adresse')" class="block text-xs text-rose-600 mt-1">{{ erreur('adresse') }}</span>
                    <!-- Widget d'appoint : bouton "Rechercher via Google Maps" qui
                         remplit le champ ci-dessus au lieu de le remplacer — voir
                         HotelAddressAutocomplete.vue. La saisie manuelle reste
                         toujours possible sans JS. -->
                    <HotelAddressAutocomplete :google-places-key="googlePlacesKey" target-input-id="hotel-address-adresse"
                        class="mt-1.5" />
                </div>
                <button type="submit"
                    class="px-5 py-2.5 bg-accent hover:bg-accent-dark text-white font-bold text-sm rounded-lg transition-colors cursor-pointer">
                    + Ajouter
                </button>
            </div>
        </form>
    </div>
</template>
