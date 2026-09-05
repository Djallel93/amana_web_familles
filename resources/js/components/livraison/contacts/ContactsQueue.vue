<!-- resources/js/components/livraison/contacts/ContactsQueue.vue -->
<!--
    File de suivi des contacts — reconstruit en Vue le 03/09/2026, voir
    resources/views/livraison/contacts.blade.php.

    Corrige par rapport à la version placeholder :
      - l'input "ID gestionnaire" → PersonPicker (recherche nom/prénom,
        voir shared/PersonPicker.vue et PickersController::personnes()) ;
      - l'assignation résolvait par alert() → Toast + mise à jour
        optimiste de la ligne ;
      - l'accordéon <details> de saisie manuelle → formulaire propre avec
        les mêmes champs mais un vrai layout (adresse/CP/ville sur une
        grille, adultes/enfants côte à côte, créneaux en chips) ;
      - pas de pagination (l'API paginait déjà, la version placeholder
        ignorait purement links/meta) → Paginator.vue.
-->
<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue';
import { useToast } from '@amana/shared-ui';
import { apiGet, apiPost, buildQuery } from '../shared/api';
import Paginator from '../shared/Paginator.vue';
import PersonPicker from '../shared/PersonPicker.vue';
import {
    CRENEAUX,
    CRENEAU_LIBELLES,
    normalizePaginated,
    STATUTS_CONTACT_POSTABLES,
    type Campagne,
    type Creneau,
    type Livraison,
    type Paginated,
    type PersonneResume,
    type RawLaravelPaginator,
    type StatutContactPostable,
} from '../shared/types';

const toast = useToast();

const el = document.getElementById('vue-livraison-contacts-queue')!;
const campagnes = ref<Campagne[]>(JSON.parse(el.dataset.campagnes ?? '[]'));
const queueUrl = el.dataset.queueUrl ?? '';
const assignerUrlTemplate = el.dataset.assignerUrlTemplate ?? '';
const assignerLotUrl = el.dataset.assignerLotUrl ?? '';
const contacterManuelUrlTemplate = el.dataset.contacterManuelUrlTemplate ?? '';

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

function formatDateFr(iso: string): string {
    const [annee, mois, jour] = iso.split('T')[0].split('-');
    return `${jour}/${mois}/${annee}`;
}

// ── Filtre + file ────────────────────────────────────────────────────────
// Pré-sélection depuis l'URL (?id_campagne=…) — ajouté le 03/09/2026 pour
// permettre un lien direct "Suivi des contacts" depuis CampagneDetail.vue,
// plutôt que d'atterrir systématiquement sur la file non filtrée et devoir
// re-sélectionner la campagne à la main.
const paramsUrl = new URLSearchParams(window.location.search);
const filtreCampagne = ref(paramsUrl.get('id_campagne') ?? '');
const filtreMine = ref(false);

const file = ref<Livraison[]>([]);
const meta = ref<Paginated<Livraison>['meta'] | null>(null);
const chargement = ref(true);
const erreur = ref(false);

async function chargerFile(page = 1) {
    chargement.value = true;
    erreur.value = false;
    selection.clear();

    const url = queueUrl + buildQuery({
        page,
        id_campagne: filtreCampagne.value,
        mine: filtreMine.value ? 1 : undefined,
    });
    const resultat = await apiGet<RawLaravelPaginator<Livraison>>(url);
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

// ── Assignation en lot ────────────────────────────────────────────────────
// Ajoutée le 03/09/2026 (prompt de cette date §2.4) : avec 100+ familles à
// répartir, assigner une par une n'est pas praticable — on filtre/coche
// puis on assigne tout le lot sélectionné en un appel (voir
// ContactTrackingController::assignerLot()). Sélection remise à zéro à
// chaque rechargement de la file (changement de filtre/page) plutôt que
// persistée entre pages — éviter la confusion "j'ai sélectionné des lignes
// que je ne vois plus".
const selection = reactive<Set<number>>(new Set());
const assignationLotEnCours = ref(false);

function toggleSelection(id: number) {
    if (selection.has(id)) selection.delete(id);
    else selection.add(id);
}

function toutSelectionner() {
    if (selection.size === file.value.length) {
        selection.clear();
    } else {
        file.value.forEach((l) => selection.add(l.id));
    }
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
interface FormeContact {
    ouvert: boolean;
    statut_contact: StatutContactPostable;
    adresse_confirmee: string;
    code_postal_confirme: string;
    ville_confirmee: string;
    nombre_adulte_confirme: string;
    nombre_enfant_confirme: string;
    creneaux: Creneau[];
    envoiEnCours: boolean;
    erreurs: Record<string, string[]>;
}

const formulaires = reactive<Record<number, FormeContact>>({});

function formulaire(id: number): FormeContact {
    if (!formulaires[id]) {
        formulaires[id] = {
            ouvert: false,
            statut_contact: 'contacte',
            adresse_confirmee: '',
            code_postal_confirme: '',
            ville_confirmee: '',
            nombre_adulte_confirme: '',
            nombre_enfant_confirme: '',
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

async function enregistrerContact(livraison: Livraison) {
    const f = formulaire(livraison.id);
    f.envoiEnCours = true;
    f.erreurs = {};

    const corps: Record<string, unknown> = { statut_contact: f.statut_contact };
    if (f.statut_contact === 'confirme') {
        corps.adresse_confirmee = f.adresse_confirmee;
        corps.code_postal_confirme = f.code_postal_confirme || null;
        corps.ville_confirmee = f.ville_confirmee || null;
        corps.nombre_adulte_confirme = f.nombre_adulte_confirme;
        corps.nombre_enfant_confirme = f.nombre_enfant_confirme;
        corps.creneaux = f.creneaux;
    }

    const resultat = await apiPost<{ success: boolean }>(urlContacterManuel(livraison.id), corps);
    f.envoiEnCours = false;

    if (!resultat.ok) {
        f.erreurs = resultat.errors;
        if (Object.keys(resultat.errors).length === 0) toast.error(resultat.message);
        return;
    }

    toast.success('Contact enregistré.');
    // La file exclut statut_contact = 'confirme' côté serveur (voir
    // ContactTrackingController::queue()) — un recharge fait disparaître
    // la ligne dès qu'elle est confirmée, comportement attendu plutôt
    // qu'une mise à jour optimiste locale qui la laisserait affichée.
    chargerFile(meta.value?.current_page ?? 1);
}

onMounted(() => chargerFile(1));
</script>

<template>
    <div>
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 mb-4">
            <select v-model="filtreCampagne" @change="chargerFile(1)"
                class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.5rem]">
                <option value="">Toutes les campagnes</option>
                <option v-for="c in campagnes" :key="c.id" :value="c.id">
                    {{ formatDateFr(c.date_livraison) }} — {{ c.type }}
                </option>
            </select>
            <label class="flex items-center gap-2 text-[13px] text-ink-muted min-h-[2.5rem]">
                <input type="checkbox" v-model="filtreMine" @change="chargerFile(1)" class="w-4 h-4 accent-accent">
                Assignées à moi
            </label>
        </div>

        <p v-if="chargement" class="text-[14px] text-ink-muted">Chargement…</p>
        <p v-else-if="erreur" class="text-[14px] text-rose-600">Impossible de charger la file de contact.</p>
        <p v-else-if="file.length === 0" class="text-[14px] text-ink-muted">Aucune livraison en attente de contact.</p>

        <div v-else class="space-y-3">
            <div class="flex flex-wrap items-center gap-3 bg-stone-50 border border-surface-border rounded-xl px-4 py-2.5">
                <label class="flex items-center gap-2 text-[12.5px] text-ink-muted min-h-[2rem]">
                    <input type="checkbox" :checked="selection.size === file.length" @change="toutSelectionner"
                        class="w-4 h-4 accent-accent">
                    Tout sélectionner ({{ selection.size }})
                </label>
                <div v-if="selection.size > 0" class="max-w-xs">
                    <PersonPicker role="gestionnaire" placeholder="Assigner la sélection à…"
                        :model-value="null"
                        @update:model-value="assignerLot" />
                </div>
            </div>

            <div v-for="livraison in file" :key="livraison.id" class="bg-surface border border-surface-border rounded-xl p-4">
                <div class="flex items-center justify-between gap-2 mb-1">
                    <span class="flex items-center gap-2 text-[14px] font-medium text-ink">
                        <input type="checkbox" :checked="selection.has(livraison.id)" @change="toggleSelection(livraison.id)"
                            class="w-4 h-4 accent-accent shrink-0">
                        {{ livraison.famille.prenom }} {{ livraison.famille.nom }}
                    </span>
                    <span class="text-[12px] text-ink-muted shrink-0">{{ LIBELLES_STATUT_CONTACT[livraison.statut_contact as StatutContactPostable] ?? livraison.statut_contact }}</span>
                </div>
                <p class="text-[12px] text-ink-muted mb-3">
                    {{ livraison.famille.telephone || '—' }} · {{ livraison.famille.email || "pas d'email" }}
                    <span v-if="livraison.personne_assignee">
                        · assigné à {{ livraison.personne_assignee.prenom }} {{ livraison.personne_assignee.nom }}
                    </span>
                </p>

                <div class="max-w-xs mb-3">
                    <PersonPicker role="gestionnaire" placeholder="Assigner à…"
                        :model-value="livraison.personne_assignee"
                        @update:model-value="(p) => assigner(livraison, p)" />
                </div>

                <details class="group">
                    <summary class="text-[12.5px] text-accent cursor-pointer select-none min-h-[2rem] flex items-center">
                        Saisie téléphonique
                    </summary>
                    <div class="mt-3 space-y-3">
                        <div>
                            <label class="block text-[11px] text-ink-muted mb-1">Statut</label>
                            <select v-model="formulaire(livraison.id).statut_contact"
                                class="w-full sm:w-auto rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                                <option v-for="s in STATUTS_CONTACT_POSTABLES" :key="s" :value="s">
                                    {{ LIBELLES_STATUT_CONTACT[s] }}
                                </option>
                            </select>
                        </div>

                        <template v-if="formulaire(livraison.id).statut_contact === 'confirme'">
                            <div>
                                <label class="block text-[11px] text-ink-muted mb-1">Adresse</label>
                                <input v-model="formulaire(livraison.id).adresse_confirmee" type="text"
                                    class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                                <p v-for="e in formulaire(livraison.id).erreurs.adresse_confirmee ?? []" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] text-ink-muted mb-1">Code postal</label>
                                    <input v-model="formulaire(livraison.id).code_postal_confirme" type="text"
                                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                                </div>
                                <div>
                                    <label class="block text-[11px] text-ink-muted mb-1">Ville</label>
                                    <input v-model="formulaire(livraison.id).ville_confirmee" type="text"
                                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-[11px] text-ink-muted mb-1">Adultes</label>
                                    <input v-model="formulaire(livraison.id).nombre_adulte_confirme" type="number" min="1"
                                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                                    <p v-for="e in formulaire(livraison.id).erreurs.nombre_adulte_confirme ?? []" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
                                </div>
                                <div>
                                    <label class="block text-[11px] text-ink-muted mb-1">Enfants</label>
                                    <input v-model="formulaire(livraison.id).nombre_enfant_confirme" type="number" min="0"
                                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                                </div>
                            </div>
                            <div>
                                <label class="block text-[11px] text-ink-muted mb-1.5">Créneaux</label>
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-1.5">
                                    <label v-for="creneau in CRENEAUX" :key="creneau"
                                        class="flex items-center gap-1.5 px-2.5 py-2 border border-ink-faint rounded-md text-[11.5px] text-ink-muted cursor-pointer select-none transition-colors has-[:checked]:border-accent has-[:checked]:bg-accent/5 has-[:checked]:text-ink has-[:checked]:font-semibold">
                                        <input type="checkbox" :checked="formulaire(livraison.id).creneaux.includes(creneau)"
                                            @change="toggleCreneau(livraison.id, creneau)" class="w-3.5 h-3.5 accent-accent">
                                        {{ CRENEAU_LIBELLES[creneau] }}
                                    </label>
                                </div>
                                <p v-for="e in formulaire(livraison.id).erreurs.creneaux ?? []" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
                            </div>
                        </template>

                        <button type="button" :disabled="formulaire(livraison.id).envoiEnCours" @click="enregistrerContact(livraison)"
                            class="min-h-[2.25rem] text-[12.5px] px-3 py-1.5 rounded-lg bg-accent text-white disabled:opacity-60">
                            {{ formulaire(livraison.id).envoiEnCours ? 'Enregistrement…' : 'Enregistrer' }}
                        </button>
                    </div>
                </details>
            </div>
        </div>

        <div v-if="meta" class="mt-4">
            <Paginator :meta="meta" @change="chargerFile" />
        </div>
    </div>
</template>
