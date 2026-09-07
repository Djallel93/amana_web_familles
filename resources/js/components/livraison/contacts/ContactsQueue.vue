<!-- resources/js/components/livraison/contacts/ContactsQueue.vue -->
<!--
    File de suivi des contacts — reconstruit en Vue le 03/09/2026, révisé
    en profondeur le 05/09/2026 (voir le prompt de cette date §2 et
    resources/views/livraison/contacts.blade.php) :
      - filtre étendu au panneau partagé FamilleFilterPanel (§2.6), même
        filtres qu'eligibles()/Dossier Familles ;
      - "tout sélectionner" couvre désormais TOUT le filtré (toutes pages,
        via ids_only), pas seulement la page affichée (§2.6.2) ;
      - per_page configurable (§2.7) ;
      - créneaux regroupés matin/après-midi avec case tout/rien par
        groupe + globale (§2.8) ;
      - bouton "Modifier le dossier" ouvre DetailPanel.vue (§2.3) — mêmes
        règles de statut/synchronisation que Dossier Familles, sans rien
        dupliquer ici ;
      - téléphone bis affiché, layout contact retravaillé (§2.5) ;
      - clustering déplacé ici depuis CampagneDetail.vue (§1.5), gated côté
        serveur ET côté Vue sur "plus aucune famille à a_contacter pour la
        journée choisie".
-->
<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue';
import { useToast, useConfirm } from '@amana/shared-ui';
import { apiGet, apiPost, buildQuery } from '../shared/api';
import Paginator from '../shared/Paginator.vue';
import PersonPicker from '../shared/PersonPicker.vue';
import FamilleFilterPanel from '../shared/FamilleFilterPanel.vue';
import {
    CRENEAUX_MATIN,
    CRENEAUX_APRES_MIDI,
    CRENEAU_LIBELLES,
    normalizePaginated,
    STATUTS_CONTACT_POSTABLES,
    type Campagne,
    type CampagneJournee,
    type Creneau,
    type FamilleFiltres,
    type GenererRoutesResultat,
    type Livraison,
    type Organisation,
    type Paginated,
    type PersonneResume,
    type Quartier,
    type RawLaravelPaginator,
    type Secteur,
    type StatutContactPostable,
    type Ville,
} from '../shared/types';

declare global {
    interface Window {
        openFamilleDetail?: (id: number) => void;
    }
}

const toast = useToast();
const confirmDialog = useConfirm();

const el = document.getElementById('vue-livraison-contacts-queue')!;
const campagnes = ref<Campagne[]>(JSON.parse(el.dataset.campagnes ?? '[]'));
const villes = ref<Ville[]>(JSON.parse(el.dataset.villes ?? '[]'));
const secteurs = ref<Secteur[]>(JSON.parse(el.dataset.secteurs ?? '[]'));
const quartiers = ref<Quartier[]>(JSON.parse(el.dataset.quartiers ?? '[]'));
const organisations = ref<Organisation[]>(JSON.parse(el.dataset.organisations ?? '[]'));
const queueUrl = el.dataset.queueUrl ?? '';
const assignerUrlTemplate = el.dataset.assignerUrlTemplate ?? '';
const assignerLotUrl = el.dataset.assignerLotUrl ?? '';
const contacterManuelUrlTemplate = el.dataset.contacterManuelUrlTemplate ?? '';
const genererRoutesUrlTemplate = el.dataset.genererRoutesUrlTemplate ?? '';

const LIBELLES_STATUT_CONTACT: Record<StatutContactPostable, string> = {
    contacte: 'Contacté',
    injoignable: 'Injoignable',
    confirme: 'Confirmé',
    rejetee: 'Rejetée',
    archive: 'Archivé',
};

function urlAssigner(id: number): string {
    return assignerUrlTemplate.replace('__ID__', String(id));
}
function urlContacterManuel(id: number): string {
    return contacterManuelUrlTemplate.replace('__ID__', String(id));
}
function urlGenererRoutes(idCampagne: number): string {
    return genererRoutesUrlTemplate.replace('__ID__', String(idCampagne));
}

function formatDateFr(iso: string): string {
    const [annee, mois, jour] = iso.split('T')[0].split('-');
    return `${jour}/${mois}/${annee}`;
}

// ── Filtre + file ────────────────────────────────────────────────────────
const paramsUrl = new URLSearchParams(window.location.search);
const filtreCampagne = ref(paramsUrl.get('id_campagne') ?? '');
const filtresFamille = ref<FamilleFiltres>({});
const parPage = ref(50);

const campagneSelectionnee = computed(() => campagnes.value.find((c) => String(c.id) === String(filtreCampagne.value)) ?? null);
const journeesCampagne = computed<CampagneJournee[]>(() => campagneSelectionnee.value?.journees ?? []);
const idJourneeSelectionnee = ref<number | ''>('');

const file = ref<Livraison[]>([]);
const meta = ref<Paginated<Livraison>['meta'] | null>(null);
const chargement = ref(true);
const erreur = ref(false);

function queryFiltres(page: number) {
    return buildQuery({
        page,
        per_page: parPage.value,
        id_campagne: filtreCampagne.value,
        id_ville: filtresFamille.value.id_ville,
        id_secteur: filtresFamille.value.id_secteur,
        id_quartier: filtresFamille.value.id_quartier,
        criticite: filtresFamille.value.criticite,
        se_deplace: filtresFamille.value.se_deplace || undefined,
        est_hotel: filtresFamille.value.est_hotel || undefined,
        etudiant: filtresFamille.value.etudiant || undefined,
        zakat_el_fitr: filtresFamille.value.zakat_el_fitr || undefined,
        sadaqa: filtresFamille.value.sadaqa || undefined,
        id_organisation_origine: filtresFamille.value.id_organisation_origine,
        id_organisation_rattachee: filtresFamille.value.id_organisation_rattachee,
        recherche: filtresFamille.value.recherche,
    });
}

async function chargerFile(page = 1) {
    chargement.value = true;
    erreur.value = false;
    selection.clear();

    const resultat = await apiGet<RawLaravelPaginator<Livraison>>(queueUrl + queryFiltres(page));
    chargement.value = false;

    if (!resultat.ok) {
        erreur.value = true;
        return;
    }

    const paginé = normalizePaginated(resultat.data);
    file.value = paginé.data;
    meta.value = paginé.meta;
}

// ── Assignation ──────────────────────────────────────────────────────────
const assignationEnCours = reactive<Record<number, boolean>>({});

async function assigner(livraison: Livraison, personne: PersonneResume | null) {
    if (!personne) return;
    assignationEnCours[livraison.id] = true;

    const resultat = await apiPost<{ success: boolean }>(urlAssigner(livraison.id), {
        id_personne_assignee: personne.id,
    });

    assignationEnCours[livraison.id] = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    livraison.id_personne_assignee = personne.id;
    livraison.personne_assignee = personne;
    toast.success(`Assigné à ${personne.prenom} ${personne.nom}.`);
}

// ── Sélection + assignation en lot (05/09/2026 : couvre tout le filtré,
//    pas seulement la page affichée — prompt §2.6.2) ─────────────────────
const selection = reactive<Set<number>>(new Set());
const assignationLotEnCours = ref(false);
const chargementSelectionTout = ref(false);

function toggleSelection(id: number) {
    if (selection.has(id)) selection.delete(id);
    else selection.add(id);
}

async function toutSelectionnerFiltre() {
    const pageActuelleIds = file.value.map((l) => l.id);
    const dejaTout = pageActuelleIds.length > 0 && pageActuelleIds.every((id) => selection.has(id));

    if (dejaTout) {
        pageActuelleIds.forEach((id) => selection.delete(id));
        return;
    }

    chargementSelectionTout.value = true;
    const resultat = await apiGet<{ ids: number[] }>(queueUrl + queryFiltres(1) + '&ids_only=1');
    chargementSelectionTout.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    resultat.data.ids.forEach((id) => selection.add(id));
}

async function assignerLot(personne: PersonneResume | null) {
    if (!personne || selection.size === 0) return;
    assignationLotEnCours.value = true;

    const resultat = await apiPost<{ success: boolean; assignees: number }>(assignerLotUrl, {
        id_personne_assignee: personne.id,
        ids_livraison: [...selection],
    });

    assignationLotEnCours.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    toast.success(`${resultat.data.assignees} livraison(s) assignée(s) à ${personne.prenom} ${personne.nom}.`);
    selection.clear();
    chargerFile(meta.value?.current_page ?? 1);
}

// ── Saisie téléphonique manuelle ────────────────────────────────────────
/**
 * Formulaire de CONFIRMATION uniquement désormais (05/09/2026, prompt
 * §2.2 : "Delete Saisie Telephonique button since there is now Modifier
 * le dossier" — la correction de champs famille se fait sur ce panneau,
 * pas ici). injoignable/rejetee/archive n'ont plus besoin d'un
 * formulaire du tout (voir marquerStatutSimple()) — un seul champ requis
 * nulle part pour ces 3-là (voir la validation serveur, contacterManuel()).
 *
 * Réduit à `creneaux` uniquement (07/09/2026, prompt §2.5) —
 * adresse/code postal/ville/adultes/enfants retirés : ces informations
 * vivent déjà dans le dossier famille, éditable juste au-dessus via
 * "✏️ Modifier le dossier" — les redemander ici dupliquait une saisie
 * pour rien et risquait de diverger de la même source de vérité que ce
 * panneau. Voir ContactTrackingController::contacterManuel(), qui
 * n'exige plus ces champs pour ce chemin.
 */
interface FormeConfirmation {
    ouvert: boolean;
    creneaux: Creneau[];
    envoiEnCours: boolean;
    erreurs: Record<string, string[]>;
}

const formulaires = reactive<Record<number, FormeConfirmation>>({});

function formulaire(id: number): FormeConfirmation {
    if (!formulaires[id]) {
        formulaires[id] = {
            ouvert: false,
            creneaux: [],
            envoiEnCours: false,
            erreurs: {},
        };
    }
    return formulaires[id];
}

function toggleCreneau(id: number, creneau: Creneau) {
    const f = formulaire(id);
    const index = f.creneaux.indexOf(creneau);
    if (index === -1) f.creneaux.push(creneau);
    else f.creneaux.splice(index, 1);
}

/**
 * Regroupement matin/après-midi (05/09/2026, prompt §2.8) — case
 * tout/rien PAR groupe, plus une case globale qui coche/décoche les deux
 * groupes en un geste (voir template : "Tout" à côté de "Matin"/
 * "Après-midi").
 */
function groupeToutCoche(id: number, groupe: Creneau[]): boolean {
    return groupe.every((c) => formulaire(id).creneaux.includes(c));
}
function toggleGroupe(id: number, groupe: Creneau[]) {
    const f = formulaire(id);
    if (groupeToutCoche(id, groupe)) {
        f.creneaux = f.creneaux.filter((c) => !groupe.includes(c));
    } else {
        f.creneaux = [...new Set([...f.creneaux, ...groupe])];
    }
}
function toggleTout(id: number) {
    const toutesCoches = groupeToutCoche(id, CRENEAUX_MATIN) && groupeToutCoche(id, CRENEAUX_APRES_MIDI);
    formulaire(id).creneaux = toutesCoches ? [] : [...CRENEAUX_MATIN, ...CRENEAUX_APRES_MIDI];
}

/**
 * injoignable/rejetee/archive — un clic, aucun champ requis côté
 * validation serveur (voir ContactTrackingController::contacterManuel()),
 * donc aucune raison de passer par un formulaire pour ces trois-là
 * (05/09/2026, prompt §2.2/§2.3).
 */
const statutSimpleEnCours = reactive<Record<number, boolean>>({});

async function marquerStatutSimple(livraison: Livraison, statut: 'injoignable' | 'rejetee' | 'archive') {
    statutSimpleEnCours[livraison.id] = true;
    const resultat = await apiPost<{ success: boolean }>(urlContacterManuel(livraison.id), { statut_contact: statut });
    statutSimpleEnCours[livraison.id] = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    toast.success('Statut mis à jour.');
    chargerFile(meta.value?.current_page ?? 1);
    // Corrigé le 05/09/2026 (prompt §2.4) : le bouton de clustering restait
    // grisé/pas à jour tant que la page n'était pas rechargée — la gate ne
    // se revérifiait qu'au montage du composant. Revérifiée maintenant
    // après CHAQUE changement de statut_contact, ici et dans
    // enregistrerContact() ci-dessous.
    verifierGateClustering();
}

async function enregistrerContact(livraison: Livraison) {
    const f = formulaire(livraison.id);
    f.envoiEnCours = true;
    f.erreurs = {};

    const resultat = await apiPost<{ success: boolean }>(urlContacterManuel(livraison.id), {
        statut_contact: 'confirme',
        creneaux: f.creneaux,
    });
    f.envoiEnCours = false;

    if (!resultat.ok) {
        f.erreurs = resultat.errors;
        if (Object.keys(resultat.errors).length === 0) toast.error(resultat.message);
        return;
    }

    toast.success('Contact enregistré.');
    chargerFile(meta.value?.current_page ?? 1);
    verifierGateClustering();
}

// ── Modifier le dossier famille (05/09/2026, prompt §2.3) ────────────────
// Ouvre DetailPanel.vue (même panneau que Dossier Familles, voir
// contacts.blade.php pour son montage) — mêmes règles de statut/
// synchronisation qu'ailleurs dans l'app, rien à dupliquer ici.
function modifierDossier(livraison: Livraison) {
    if (!window.openFamilleDetail) {
        toast.error("Le panneau d'édition n'a pas pu être chargé.");
        return;
    }
    window.openFamilleDetail(livraison.famille.id);
}

// ── Clustering (05/09/2026, prompt §1.5 : déplacé depuis CampagneDetail.vue) ─
// Gate : plus aucune livraison à statut_contact = 'a_contacter' pour la
// journée choisie — revérifié aussi côté serveur (voir
// LiveBoardController::genererRoutes()), ce calcul côté Vue ne sert qu'à
// griser le bouton avant même de tenter l'appel.
const chargementVerifGate = ref(false);
const resteAContacter = ref<number | null>(null);

async function verifierGateClustering() {
    if (!campagneSelectionnee.value || idJourneeSelectionnee.value === '') {
        resteAContacter.value = null;
        return;
    }
    chargementVerifGate.value = true;
    const resultat = await apiGet<{ ids: number[] }>(queueUrl + buildQuery({
        id_campagne: campagneSelectionnee.value.id,
        id_campagne_journee: idJourneeSelectionnee.value,
        statut_contact: 'a_contacter',
        ids_only: 1,
    }));
    chargementVerifGate.value = false;
    resteAContacter.value = resultat.ok ? resultat.data.ids.length : null;
}

const chargementRoutes = ref(false);
const resultatRoutes = ref<GenererRoutesResultat | null>(null);
const erreurRoutes = ref('');

async function genererRoutes() {
    if (!campagneSelectionnee.value || idJourneeSelectionnee.value === '') return;

    const confirmed = await confirmDialog.ask({
        title: 'Lancer la génération des routes',
        message: "Le clustering et l'assignation des tournées vont être (re)calculés pour cette journée. Continuer ?",
        confirmLabel: 'Lancer',
    });
    if (!confirmed) return;

    chargementRoutes.value = true;
    erreurRoutes.value = '';
    resultatRoutes.value = null;

    const resultat = await apiPost<GenererRoutesResultat>(urlGenererRoutes(campagneSelectionnee.value.id), {
        id_campagne_journee: idJourneeSelectionnee.value,
    });
    chargementRoutes.value = false;

    if (!resultat.ok) {
        erreurRoutes.value = resultat.message;
        toast.error(resultat.message);
        return;
    }

    resultatRoutes.value = resultat.data;
    toast.success(`${resultat.data.routes_creees} tournée(s) créée(s).`);
}

function surChangementCampagne() {
    idJourneeSelectionnee.value = journeesCampagne.value[0]?.id ?? '';
    chargerFile(1);
    verifierGateClustering();
}
function surChangementJournee() {
    verifierGateClustering();
}

onMounted(() => {
    if (campagneSelectionnee.value) idJourneeSelectionnee.value = journeesCampagne.value[0]?.id ?? '';
    chargerFile(1);
    verifierGateClustering();
});
</script>

<template>
    <div>
        <div class="flex flex-col sm:flex-row sm:items-end gap-3 mb-4">
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Campagne</label>
                <select v-model="filtreCampagne" @change="surChangementCampagne"
                    class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.5rem]">
                    <option value="">Toutes les campagnes</option>
                    <option v-for="c in campagnes" :key="c.id" :value="c.id">
                        {{ formatDateFr(c.date_livraison) }} — {{ c.type }}
                    </option>
                </select>
            </div>
            <div v-if="journeesCampagne.length > 1">
                <label class="block text-[12px] text-ink-muted mb-1">Journée</label>
                <select v-model="idJourneeSelectionnee" @change="surChangementJournee"
                    class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.5rem]">
                    <option v-for="j in journeesCampagne" :key="j.id" :value="j.id">{{ j.label ?? formatDateFr(j.date) }}</option>
                </select>
            </div>
        </div>

        <FamilleFilterPanel :villes="villes" :secteurs="secteurs" :quartiers="quartiers" :organisations="organisations"
            :model-value="filtresFamille" @update:model-value="filtresFamille = $event" @filtrer="chargerFile(1)" />

        <!--
            Clustering (05/09/2026, prompt §1.5) déplacé sous le filtre,
            sur la même rangée que "Tout sélectionner" (07/09/2026, prompt
            §2.3) — ne peut se lancer que si une campagne (et sa journée
            s'il y en a plusieurs) est choisie ET qu'il ne reste plus
            aucune famille à contacter pour cette journée — grisé sinon
            plutôt que de laisser tenter un appel qui échouera de toute
            façon côté serveur.
        -->
        <p v-if="chargement" class="text-[14px] text-ink-muted">Chargement…</p>
        <p v-else-if="erreur" class="text-[14px] text-rose-600">Impossible de charger la file de contact.</p>
        <p v-else-if="file.length === 0" class="text-[14px] text-ink-muted">Aucune livraison en attente de contact.</p>

        <div v-else class="space-y-3">
            <div class="flex flex-wrap items-center gap-3 bg-stone-50 border border-surface-border rounded-xl px-4 py-2.5">
                <label class="flex items-center gap-2 text-[12.5px] text-ink-muted min-h-[2rem]">
                    <input type="checkbox" :disabled="chargementSelectionTout"
                        :checked="file.length > 0 && file.every((l) => selection.has(l.id))"
                        @change="toutSelectionnerFiltre" class="w-4 h-4 accent-accent">
                    Tout sélectionner (le filtre entier — {{ selection.size }})
                </label>
                <div v-if="selection.size > 0" class="max-w-xs">
                    <PersonPicker role="gestionnaire" placeholder="Assigner la sélection à…"
                        :model-value="null"
                        @update:model-value="assignerLot" />
                </div>
                <template v-if="campagneSelectionnee">
                    <button type="button" :disabled="chargementRoutes || chargementVerifGate || (resteAContacter ?? 1) > 0" @click="genererRoutes"
                        class="min-h-[2.25rem] text-[13px] px-4 py-2 rounded-lg bg-accent text-white disabled:opacity-40 disabled:cursor-not-allowed">
                        🚚 {{ chargementRoutes ? 'Génération…' : 'Lancer le clustering / génération des routes' }}
                    </button>
                    <p v-if="chargementVerifGate" class="text-[12.5px] text-ink-muted">Vérification…</p>
                    <p v-else-if="(resteAContacter ?? 0) > 0" class="text-[12.5px] text-amber-700">
                        {{ resteAContacter }} famille(s) encore à contacter pour cette journée.
                    </p>
                    <p v-else-if="resteAContacter === 0" class="text-[12.5px] text-emerald-700">Toutes les familles ont été contactées.</p>
                    <p v-if="resultatRoutes" class="text-[12.5px] text-ink-muted w-full">
                        {{ resultatRoutes.routes_creees }} tournée(s) créée(s), dont {{ resultatRoutes.imposees }} imposée(s).
                    </p>
                    <p v-if="erreurRoutes" class="text-[12.5px] text-rose-600 w-full">{{ erreurRoutes }}</p>
                </template>
            </div>

            <div v-for="livraison in file" :key="livraison.id" class="bg-surface border border-surface-border rounded-xl p-4 shadow-sm">
                <div class="flex items-start justify-between gap-2 mb-2">
                    <span class="flex items-center gap-2 text-[15px] font-semibold text-ink">
                        <input type="checkbox" :checked="selection.has(livraison.id)" @change="toggleSelection(livraison.id)"
                            class="w-4 h-4 accent-accent shrink-0">
                        {{ livraison.famille.prenom }} {{ livraison.famille.nom }}
                    </span>
                </div>

                <!-- Coordonnées — mises en avant + téléphone bis affiché
                     (05/09/2026, prompt §2.5 : "Are you displaying phone
                     bis?" → non, corrigé ici et côté requête serveur). -->
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-ink mb-3 bg-stone-50 rounded-lg px-3 py-2">
                    <span>📞 {{ livraison.famille.telephone || '—' }}</span>
                    <span v-if="livraison.famille.telephone_bis">📞 {{ livraison.famille.telephone_bis }} <span class="text-ink-muted">(bis)</span></span>
                    <span>✉️ {{ livraison.famille.email || "pas d'email" }}</span>
                    <span v-if="livraison.personne_assignee" class="text-ink-muted">
                        · assigné à {{ livraison.personne_assignee.prenom }} {{ livraison.personne_assignee.nom }}
                    </span>
                </div>

                <!--
                    Statut + "Modifier le dossier" sur la même rangée
                    (07/09/2026, prompt §2.4) — le statut était affiché en
                    haut de carte auparavant, déplacé ici. Boutons colorés
                    (même prompt) : indigo pour Modifier le dossier
                    (action neutre "consulter/éditer", cohérent avec les
                    boutons d'édition ailleurs dans l'app), et une couleur
                    distincte par statut simple juste en dessous plutôt
                    que tous en gris indifférencié.
                -->
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <div class="max-w-xs">
                        <PersonPicker role="gestionnaire" placeholder="Assigner à…"
                            :model-value="livraison.personne_assignee"
                            @update:model-value="(p) => assigner(livraison, p)" />
                    </div>
                    <button type="button" @click="modifierDossier(livraison)"
                        class="min-h-[2.25rem] text-[12.5px] px-3 py-1.5 rounded-lg bg-indigo-600 text-white hover:opacity-90">
                        ✏️ Modifier le dossier
                    </button>
                    <span class="text-[11.5px] font-medium px-2 py-0.5 rounded-full shrink-0"
                        :class="{
                            'bg-stone-100 text-ink-muted': livraison.statut_contact === 'a_contacter',
                            'bg-sky-100 text-sky-700': livraison.statut_contact === 'contacte',
                            'bg-emerald-100 text-emerald-700': livraison.statut_contact === 'confirme',
                        }">
                        {{ LIBELLES_STATUT_CONTACT[livraison.statut_contact as StatutContactPostable] ?? livraison.statut_contact }}
                    </span>
                </div>

                <!--
                    Remplace l'ancien bouton "Saisie téléphonique" +
                    sélecteur de statut (05/09/2026, prompt §2.2/§2.3) :
                    injoignable/rejetée/archivée n'ont plus besoin d'aucun
                    champ (un clic suffit, voir marquerStatutSimple()) —
                    seule la confirmation garde un formulaire, préremplie
                    avec les infos famille actuelles, repliée derrière un
                    vrai bouton visible plutôt qu'un lien discret.
                -->
                <div class="flex flex-wrap gap-2">
                    <button type="button" @click="formulaire(livraison.id).ouvert = !formulaire(livraison.id).ouvert"
                        class="min-h-[2.25rem] text-[12.5px] px-3 py-1.5 rounded-lg bg-emerald-600 text-white hover:opacity-90">
                        ✅ Confirmer
                    </button>
                    <button type="button" :disabled="statutSimpleEnCours[livraison.id]" @click="marquerStatutSimple(livraison, 'injoignable')"
                        class="min-h-[2.25rem] text-[12.5px] px-3 py-1.5 rounded-lg bg-amber-600 text-white hover:opacity-90 disabled:opacity-60">
                        Injoignable
                    </button>
                    <button type="button" :disabled="statutSimpleEnCours[livraison.id]" @click="marquerStatutSimple(livraison, 'rejetee')"
                        class="min-h-[2.25rem] text-[12.5px] px-3 py-1.5 rounded-lg bg-rose-600 text-white hover:opacity-90 disabled:opacity-60">
                        Rejetée
                    </button>
                    <button type="button" :disabled="statutSimpleEnCours[livraison.id]" @click="marquerStatutSimple(livraison, 'archive')"
                        class="min-h-[2.25rem] text-[12.5px] px-3 py-1.5 rounded-lg bg-stone-500 text-white hover:opacity-90 disabled:opacity-60">
                        Archivée
                    </button>
                </div>

                <div v-if="formulaire(livraison.id).ouvert" class="mt-3 space-y-3 bg-stone-50 rounded-lg p-3">
                    <!-- Regroupement matin/après-midi (05/09/2026, prompt §2.8).
                         Adresse/code postal/ville/adultes/enfants retirés
                         d'ici (07/09/2026, prompt §2.5) — voir le
                         commentaire sur FormeConfirmation plus haut :
                         édition désormais uniquement via "✏️ Modifier le
                         dossier". -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-[11px] text-ink-muted">Créneaux</label>
                            <button type="button" @click="toggleTout(livraison.id)" class="text-[11px] text-accent">Tout / Rien</button>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="border border-ink-faint rounded-lg p-2">
                                <label class="flex items-center gap-1.5 text-[11.5px] font-medium text-ink mb-1.5">
                                    <input type="checkbox" :checked="groupeToutCoche(livraison.id, CRENEAUX_MATIN)"
                                        @change="toggleGroupe(livraison.id, CRENEAUX_MATIN)" class="w-3.5 h-3.5 accent-accent">
                                    Matin
                                </label>
                                <div class="flex flex-wrap gap-1.5">
                                    <label v-for="creneau in CRENEAUX_MATIN" :key="creneau"
                                        class="flex items-center gap-1.5 px-2.5 py-2 border border-ink-faint rounded-md text-[11.5px] text-ink-muted cursor-pointer select-none transition-colors has-[:checked]:border-accent has-[:checked]:bg-accent/5 has-[:checked]:text-ink has-[:checked]:font-semibold">
                                        <input type="checkbox" :checked="formulaire(livraison.id).creneaux.includes(creneau)"
                                            @change="toggleCreneau(livraison.id, creneau)" class="w-3.5 h-3.5 accent-accent">
                                        {{ CRENEAU_LIBELLES[creneau] }}
                                    </label>
                                </div>
                            </div>
                            <div class="border border-ink-faint rounded-lg p-2">
                                <label class="flex items-center gap-1.5 text-[11.5px] font-medium text-ink mb-1.5">
                                    <input type="checkbox" :checked="groupeToutCoche(livraison.id, CRENEAUX_APRES_MIDI)"
                                        @change="toggleGroupe(livraison.id, CRENEAUX_APRES_MIDI)" class="w-3.5 h-3.5 accent-accent">
                                    Après-midi
                                </label>
                                <div class="flex flex-wrap gap-1.5">
                                    <label v-for="creneau in CRENEAUX_APRES_MIDI" :key="creneau"
                                        class="flex items-center gap-1.5 px-2.5 py-2 border border-ink-faint rounded-md text-[11.5px] text-ink-muted cursor-pointer select-none transition-colors has-[:checked]:border-accent has-[:checked]:bg-accent/5 has-[:checked]:text-ink has-[:checked]:font-semibold">
                                        <input type="checkbox" :checked="formulaire(livraison.id).creneaux.includes(creneau)"
                                            @change="toggleCreneau(livraison.id, creneau)" class="w-3.5 h-3.5 accent-accent">
                                        {{ CRENEAU_LIBELLES[creneau] }}
                                    </label>
                                </div>
                            </div>
                        </div>
                        <p v-for="e in formulaire(livraison.id).erreurs.creneaux ?? []" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
                    </div>

                    <button type="button" :disabled="formulaire(livraison.id).envoiEnCours" @click="enregistrerContact(livraison)"
                        class="min-h-[2.25rem] text-[12.5px] px-3 py-1.5 rounded-lg bg-accent text-white disabled:opacity-60">
                        {{ formulaire(livraison.id).envoiEnCours ? 'Enregistrement…' : 'Enregistrer la confirmation' }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="meta" class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <Paginator :meta="meta" @change="chargerFile" />
            <!-- Déplacé en bas (07/09/2026, prompt §2.2) : à côté de la
                 pagination qu'il gouverne, plutôt qu'au-dessus des
                 filtres campagne/journée où il n'avait pas vraiment sa
                 place. -->
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Par page</label>
                <select v-model.number="parPage" @change="chargerFile(1)"
                    class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.5rem]">
                    <option :value="25">25</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select>
            </div>
        </div>
    </div>
</template>
