<!-- resources/js/components/livraison/shared/FamilleFilterPanel.vue -->
<!--
    Panneau de filtres partagé — voir le prompt du 05/09/2026 §1.6/§2.6
    ("same filter panel as Dossier Familles"). Reflète exactement les
    filtres reconnus par App\Support\FamilleFilters (extrait de
    FamillesController::baseQuery() ce même jour), utilisé à la fois par
    CampagneDetail.vue (sélection éligibilité), ContactsQueue.vue, et
    depuis le 10/09/2026 (Section A3 du refactor) par familles/index.blade.php
    et nouvelles.blade.php elles-mêmes (voir FamilleFiltresBar.vue et son
    docblock pour comment ces deux vues, restées de simples pages
    Blade/GET, montent ce composant sans passer par une réécriture
    Inertia — prévue séparément, Section E du refactor).

    Deux props optionnelles portent les deux features que la version
    Blade avait et que ce composant n'avait pas avant le 10/09/2026 :
    - avecStatut : ajoute le groupe de pastilles "Statut" (etat_dossier)
      — HORS scope de App\Support\FamilleFilters (voir son docblock),
      donc pas pertinent pour les écrans livraison (une campagne ne
      travaille qu'avec des dossiers déjà 'Validé' — aucun intérêt à
      filtrer par statut là où le "point of no return" a déjà eu lieu,
      décision du 10/09/2026) — seule familles/index.blade.php l'active.
    - avecAutocompletion : remplace le champ "recherche" libre par les
      deux champs Nom/Téléphone avec suggestions serveur (voir
      FamillesController::rechercheSuggestions()) — seule
      familles/index.blade.php l'active ; nouvelles.blade.php garde le
      champ `recherche` simple, comme les écrans livraison.

    Version compacte (01/10/2026) — mise en page reprise de l'ancienne barre
    Blade de la branche good_filter (familles/index.blade.php) : une carte à
    3 colonnes de sous-cartes teintées (Recherche & statut / Localisation &
    organisation / Profil du foyer), libellés 10.5px, chips de criticité
    colorées par palier, et bandeau de puces "Filtres actifs" retirables
    sous la carte (visible même repliée). Mêmes props, mêmes événements :
    les 5 écrans qui montent ce composant (Dossiers familles, Nouvelles,
    Campagne detail, Contacts, BuildRouteFlow) en héritent sans changement.
    Nouvelle prop optionnelle avecAssignation (Contacts uniquement) : filtre
    "Assigné à" + "Non assigné".

    Regroupé par thème + libellés (révisé le 05/09/2026, même prompt,
    correction ultérieure) — même structure visuelle que
    familles/index.blade.php : groupes "📍 Localisation" / "🏢
    Organisation" / "🎚️ Criticité" / "Caractéristiques", chaque champ
    avec son propre <label> au-dessus (text-[10.5px] font-semibold
    text-ink-muted), pas juste des <select>/checkbox nus.
-->
<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { apiGet } from './api';
import PersonSelect from './PersonSelect.vue';
import type { FamilleFiltres, FamilleSuggestion, Organisation, PersonneResume, Quartier, Secteur, Ville } from './types';

const props = withDefaults(defineProps<{
    villes: Ville[];
    secteurs: Secteur[];
    quartiers: Quartier[];
    organisations: Organisation[];
    modelValue: FamilleFiltres;
    avecStatut?: boolean;
    // Liste + couleurs du groupe Statut — fournies par l'appelant plutôt
    // que dupliquées ici en dur, pour rester dérivées de
    // Famille::ETATS_MODIFIABLES/ETAT_COLORS (PHP) comme seule source de
    // vérité (voir FamilleFiltresBar.vue, qui les lit depuis ses
    // data-attributes).
    etatsDisponibles?: string[];
    etatCouleurs?: Record<string, string>;
    avecAutocompletion?: boolean;
    // URL de FamillesController::rechercheSuggestions() — requis si
    // avecAutocompletion est vrai.
    suggestionsUrl?: string;
    // Replié par défaut partout (décision du 08/09/2026, "like in dossier
    // famille" puis 09/09/2026 : repliés par défaut sur tout le domaine
    // livraison) — familles/index.blade.php seule le passe à `true`, pour
    // rester ouvert par défaut comme avant ce refactor.
    ouvertParDefaut?: boolean;
    // avecSeDeplace (25/09/2026, prompt de cette date) : affiche la case
    // "Se déplace" du groupe Caractéristiques. Depuis que se_deplace a été
    // retiré de Famille (recentré comme propriété pure de la campagne, sur
    // Livraison), ce n'est plus un filtre App\Support\FamilleFilters — donc
    // masqué par défaut partout — mais reste pertinent scopé PAR CAMPAGNE
    // sur les 2 écrans qui listent des Livraison déjà existantes :
    // ContactsQueue.vue et BuildRouteFlow.vue (voir FamilleFiltres dans
    // types.ts pour le détail). CampagneDetail.vue ("familles éligibles",
    // aucune Livraison n'existe encore) et FamilleFiltresBar.vue (Dossier
    // Familles, hors contexte campagne) n'activent PAS cette prop.
    avecSeDeplace?: boolean;
    // avecAssignation (01/10/2026) : affiche le filtre "Assigné à" (une
    // personne précise, ou "Non assigné") — pertinent seulement là où des
    // Livraison portent une personne assignée : ContactsQueue.vue.
    avecAssignation?: boolean;
    // avecPuces (01/10/2026) : bandeau « Filtres actifs » sous la carte.
    // Vrai par défaut ; désactivé par Familles/Index.vue, qui affiche déjà
    // son propre bandeau de puces (côté serveur, à côté des boutons
    // Sync/Export) — les deux apparaissaient l'un sous l'autre.
    avecPuces?: boolean;
}>(), {
    avecStatut: false,
    etatsDisponibles: () => [],
    etatCouleurs: () => ({}),
    avecAutocompletion: false,
    suggestionsUrl: '',
    ouvertParDefaut: false,
    avecSeDeplace: false,
    avecAssignation: false,
    avecPuces: true,
});

const emit = defineEmits<{
    'update:modelValue': [FamilleFiltres];
    filtrer: [];
    // Émis au clic sur une suggestion d'autocomplétion — laisse
    // l'appelant décider quoi en faire (FamilleFiltresBar.vue navigue
    // immédiatement vers la fiche, "remplir, filtrer puis ouvrir",
    // comportement d'origine du 13/08/2026) plutôt que de coder une
    // navigation en dur ici, qui n'aurait aucun sens pour les écrans
    // livraison (avecAutocompletion toujours à false là-bas).
    selection: [id: number];
}>();

const filtres = reactive<FamilleFiltres>({ ...props.modelValue });

watch(filtres, () => emit('update:modelValue', { ...filtres }), { deep: true });

const CRITICITES = [0, 1, 2, 3, 4, 5];
const GROUPE_CARTE = 'bg-surface-2 rounded-lg p-3';
const GROUPE_LABEL = 'text-[10px] font-bold text-ink-muted uppercase tracking-wide mb-2';
const CHAMP_LABEL = 'block text-[10.5px] font-semibold text-ink-muted mb-1';
const CHAMP_INPUT = 'w-full rounded-md border border-ink-faint bg-surface px-2.5 py-1.5 text-[13px] min-h-[2rem] outline-none focus:border-accent';
// has-[:checked] (Tailwind 3.4+) : bordure + fond teinté à la coche, sans
// binding JS — même pattern que l'ancienne barre Blade.
const CHIP_LABEL = 'flex items-center gap-2 px-2.5 py-1.5 border border-ink-faint rounded-md text-[12px] text-ink-muted bg-surface cursor-pointer select-none transition-colors has-[:checked]:border-accent has-[:checked]:bg-accent/5 has-[:checked]:text-ink has-[:checked]:font-semibold';

// Échelle de sévérité des pastilles de criticité du tableau (0-1 vert /
// 2-3 ambre / 4-5 rose). Classes écrites en toutes lettres (pas de
// construction dynamique) pour rester détectables par le scanner JIT.
const CRITICITE_ACTIVE: Record<number, string> = {
    0: 'bg-emerald-500 border-emerald-500 text-white',
    1: 'bg-emerald-500 border-emerald-500 text-white',
    2: 'bg-amber-500 border-amber-500 text-white',
    3: 'bg-amber-500 border-amber-500 text-white',
    4: 'bg-rose-500 border-rose-500 text-white',
    5: 'bg-rose-500 border-rose-500 text-white',
};

function toggleCriticite(valeur: number) {
    const courant = filtres.criticite ?? [];
    filtres.criticite = courant.includes(valeur)
        ? courant.filter((v) => v !== valeur)
        : [...courant, valeur];
}

function secteursFiltres() {
    return filtres.id_ville ? props.secteurs.filter((s) => s.id_ville === filtres.id_ville) : props.secteurs;
}

function quartiersFiltres() {
    if (!filtres.id_secteur) return props.quartiers;
    return props.quartiers.filter((q) => q.id_secteur === filtres.id_secteur);
}

// Personne choisie dans "Assigné à" — gardée à part (nom affiché dans la
// puce) plutôt que dans `filtres`, qui est transmis tel quel au parent :
// seul id_personne_assignee y est utile.
const assigneeChoisi = ref<PersonneResume | null>(null);

function choisirAssignee(personne: PersonneResume | null) {
    assigneeChoisi.value = personne;
    filtres.id_personne_assignee = personne ? personne.id : '';
    if (personne) filtres.non_assigne = false;
}

function basculerNonAssigne() {
    filtres.non_assigne = !filtres.non_assigne;
    if (filtres.non_assigne) {
        filtres.id_personne_assignee = '';
        assigneeChoisi.value = null;
    }
}

// Pousse la valeur au parent AVANT d'émettre 'filtrer' : le watcher
// deep ci-dessus ne s'exécute qu'au tick suivant, donc un parent qui lit
// son v-model dans son gestionnaire de 'filtrer' verrait sinon les
// filtres d'avant la réinitialisation / le retrait d'une puce.
function appliquer() {
    emit('update:modelValue', { ...filtres });
    emit('filtrer');
}

function reinitialiser() {
    Object.assign(filtres, {
        id_ville: '', id_secteur: '', id_quartier: '', criticite: [],
        se_deplace: false, est_hotel: false, etudiant: false,
        zakat_el_fitr: false, sadaqa: false,
        id_organisation_origine: '', id_organisation_rattachee: '', recherche: '',
        id_personne_assignee: '', non_assigne: false,
        // etat_dossier remis à '' (pas omis) : seul moyen de revenir à
        // "Tous" (pas d'option "Tous" dans les pastilles elles-mêmes, voir
        // le docblock du groupe Statut plus bas) — même comportement que
        // le lien "Tout réinitialiser" d'origine dans index.blade.php.
        etat_dossier: '', nom: '', telephone: '', id_selection: '',
    });
    assigneeChoisi.value = null;
    appliquer();
}

// Repliable (08/09/2026, prompt §2.2.3/§3.4 : "like in dossier famille")
// — <details>/<summary> natif plutôt qu'un ref + v-show : contenu
// simplement masqué, pas démonté, les v-model des champs restent actifs
// même repliés.
const filtresActifs = computed(() => Boolean(
    filtres.recherche || filtres.id_ville || filtres.id_secteur || filtres.id_quartier
    || (filtres.criticite ?? []).length > 0
    || filtres.se_deplace || filtres.est_hotel || filtres.etudiant
    || filtres.zakat_el_fitr || filtres.sadaqa
    || filtres.id_organisation_origine || filtres.id_organisation_rattachee
    || (props.avecStatut && filtres.etat_dossier)
    || (props.avecAutocompletion && (filtres.nom || filtres.telephone))
    || (props.avecAssignation && (filtres.id_personne_assignee || filtres.non_assigne)),
));

// ── Puces de filtres actifs (01/10/2026) ───────────────────────────────
// Reprise du bandeau "Filtres actifs" de l'ancienne barre Blade : une
// puce par filtre, cliquable pour retirer ce seul filtre. Visibles même
// quand le panneau est replié (état par défaut sur tout le domaine
// livraison) — le badge "actifs" seul ne disait pas LESQUELS.
interface Puce {
    cle: string;
    label: string;
    retirer: () => void;
}

function nomDe<T extends { id: number; nom: string }>(liste: T[], id: number | ''): string {
    return liste.find((e) => e.id === id)?.nom ?? String(id);
}

const puces = computed<Puce[]>(() => {
    const liste: Puce[] = [];
    const ajouter = (cle: string, label: string, retirer: () => void) => liste.push({ cle, label, retirer });

    if (props.avecStatut && filtres.etat_dossier) {
        ajouter('etat', `Statut : ${filtres.etat_dossier}`, () => { filtres.etat_dossier = ''; });
    }
    if (filtres.recherche) {
        ajouter('recherche', `Recherche : « ${filtres.recherche} »`, () => { filtres.recherche = ''; });
    }
    if (props.avecAutocompletion) {
        if (filtres.id_selection) {
            ajouter('selection', '🔗 Résultat sélectionné', () => {
                filtres.id_selection = ''; filtres.nom = ''; filtres.telephone = '';
            });
        } else {
            if (filtres.nom) ajouter('nom', `Nom : « ${filtres.nom} »`, () => { filtres.nom = ''; });
            if (filtres.telephone) ajouter('telephone', `Téléphone : « ${filtres.telephone} »`, () => { filtres.telephone = ''; });
        }
    }
    if (props.avecAssignation) {
        if (filtres.non_assigne) {
            ajouter('non_assigne', '👤 Non assigné', () => { filtres.non_assigne = false; });
        } else if (filtres.id_personne_assignee) {
            const nom = assigneeChoisi.value ? `${assigneeChoisi.value.prenom} ${assigneeChoisi.value.nom}` : `#${filtres.id_personne_assignee}`;
            ajouter('assignee', `👤 Assigné à : ${nom}`, () => { filtres.id_personne_assignee = ''; assigneeChoisi.value = null; });
        }
    }
    if (filtres.id_ville) ajouter('ville', `Ville : ${nomDe(props.villes, filtres.id_ville)}`, () => { filtres.id_ville = ''; filtres.id_secteur = ''; filtres.id_quartier = ''; });
    if (filtres.id_secteur) ajouter('secteur', `Secteur : ${nomDe(props.secteurs, filtres.id_secteur)}`, () => { filtres.id_secteur = ''; filtres.id_quartier = ''; });
    if (filtres.id_quartier) ajouter('quartier', `Quartier : ${nomDe(props.quartiers, filtres.id_quartier)}`, () => { filtres.id_quartier = ''; });
    if (filtres.id_organisation_origine) ajouter('org_origine', `Organisation d'origine : ${nomDe(props.organisations, filtres.id_organisation_origine)}`, () => { filtres.id_organisation_origine = ''; });
    if (filtres.id_organisation_rattachee) ajouter('org_rattachee', `Organisation rattachée : ${nomDe(props.organisations, filtres.id_organisation_rattachee)}`, () => { filtres.id_organisation_rattachee = ''; });
    if ((filtres.criticite ?? []).length > 0) {
        ajouter('criticite', `Criticité : ${[...(filtres.criticite ?? [])].sort().join(', ')}`, () => { filtres.criticite = []; });
    }
    if (props.avecSeDeplace && filtres.se_deplace) ajouter('se_deplace', '🚗 Se déplace', () => { filtres.se_deplace = false; });
    if (filtres.est_hotel) ajouter('est_hotel', '🏨 Hôtel', () => { filtres.est_hotel = false; });
    if (filtres.etudiant) ajouter('etudiant', '🎓 Étudiant', () => { filtres.etudiant = false; });
    if (filtres.zakat_el_fitr) ajouter('zakat', '🌙 Zakat El Fitr', () => { filtres.zakat_el_fitr = false; });
    if (filtres.sadaqa) ajouter('sadaqa', '🤲 Sadaqa', () => { filtres.sadaqa = false; });

    return liste;
});

function retirerPuce(puce: Puce) {
    puce.retirer();
    appliquer();
}

// ── Autocomplétion Nom/Téléphone (avecAutocompletion) ───────────────────
// Portée le 10/09/2026 (Section A3) depuis le script vanilla JS de
// familles/index.blade.php — même debounce (300ms), même seuil (2
// caractères), même endpoint. Deux jeux d'état (nom/téléphone) plutôt
// qu'un seul générique : les deux champs peuvent avoir des suggestions
// ouvertes indépendamment (pas dans l'original non plus, mais rien ne
// l'empêchait structurellement).
const suggestionsNom = ref<FamilleSuggestion[]>([]);
const suggestionsTelephone = ref<FamilleSuggestion[]>([]);
let minuteurNom: ReturnType<typeof setTimeout> | null = null;
let minuteurTelephone: ReturnType<typeof setTimeout> | null = null;

async function chercherSuggestions(champ: 'nom' | 'telephone', terme: string) {
    const cible = champ === 'nom' ? suggestionsNom : suggestionsTelephone;
    if (terme.trim().length < 2) {
        cible.value = [];
        return;
    }
    const resultat = await apiGet<FamilleSuggestion[]>(
        `${props.suggestionsUrl}?champ=${champ}&q=${encodeURIComponent(terme.trim())}`,
    );
    cible.value = resultat.ok ? resultat.data : [];
}

function onSaisieNom() {
    filtres.id_selection = '';
    if (minuteurNom) clearTimeout(minuteurNom);
    minuteurNom = setTimeout(() => chercherSuggestions('nom', filtres.nom ?? ''), 300);
}

function onSaisieTelephone() {
    filtres.id_selection = '';
    if (minuteurTelephone) clearTimeout(minuteurTelephone);
    minuteurTelephone = setTimeout(() => chercherSuggestions('telephone', filtres.telephone ?? ''), 300);
}

function choisirSuggestion(champ: 'nom' | 'telephone', suggestion: FamilleSuggestion) {
    if (champ === 'nom') {
        filtres.nom = suggestion.valeur;
        suggestionsNom.value = [];
    } else {
        filtres.telephone = suggestion.valeur;
        suggestionsTelephone.value = [];
    }
    filtres.id_selection = suggestion.id;
    emit('selection', suggestion.id);
}
</script>

<template>
    <div class="mb-4">
        <details class="group bg-surface border border-surface-border rounded-xl p-3 shadow-sm" :open="ouvertParDefaut">
            <summary class="cursor-pointer list-none flex items-center justify-between select-none -mx-1 -my-1 px-1 py-1 rounded-lg hover:bg-surface-2 transition-colors">
                <span class="text-[13px] font-bold text-ink flex items-center gap-1.5">
                    🔎 Filtres
                    <span v-if="filtresActifs" class="px-1.5 py-0.5 rounded-full bg-accent/10 text-accent-dark text-[10px] font-bold">actifs</span>
                </span>
                <span class="text-ink-muted text-[13px] transition-transform duration-200 group-open:rotate-180">▾</span>
            </summary>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mt-3">
                <!-- Groupe 1 : recherche & statut (+ assignation) -->
                <div :class="GROUPE_CARTE">
                    <div :class="GROUPE_LABEL">🔎 Recherche &amp; statut</div>

                    <div v-if="avecAutocompletion" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2 gap-2">
                        <div class="relative">
                            <label :class="CHAMP_LABEL">Nom</label>
                            <input v-model="filtres.nom" type="text" placeholder="Nom ou prénom…" autocomplete="off"
                                @input="onSaisieNom" :class="CHAMP_INPUT">
                            <div v-if="suggestionsNom.length" class="absolute z-20 left-0 right-0 mt-1 bg-surface border border-surface-border rounded-lg shadow-lg overflow-hidden">
                                <button v-for="s in suggestionsNom" :key="s.id" type="button" @click="choisirSuggestion('nom', s)"
                                    class="w-full text-left px-3 py-2 hover:bg-surface-2 text-[12.5px] flex items-center justify-between gap-2 border-b border-surface-border last:border-b-0">
                                    <span class="font-semibold text-ink">{{ s.label }}</span>
                                    <span class="text-ink-muted text-[11px]">{{ s.sous_label }}</span>
                                </button>
                            </div>
                        </div>
                        <div class="relative">
                            <label :class="CHAMP_LABEL">Téléphone</label>
                            <input v-model="filtres.telephone" type="text" placeholder="Numéro…" autocomplete="off"
                                @input="onSaisieTelephone" :class="CHAMP_INPUT">
                            <div v-if="suggestionsTelephone.length" class="absolute z-20 left-0 right-0 mt-1 bg-surface border border-surface-border rounded-lg shadow-lg overflow-hidden">
                                <button v-for="s in suggestionsTelephone" :key="s.id" type="button" @click="choisirSuggestion('telephone', s)"
                                    class="w-full text-left px-3 py-2 hover:bg-surface-2 text-[12.5px] flex items-center justify-between gap-2 border-b border-surface-border last:border-b-0">
                                    <span class="font-semibold text-ink">{{ s.label }}</span>
                                    <span class="text-ink-muted text-[11px]">{{ s.sous_label }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-else>
                        <label :class="CHAMP_LABEL">Recherche (nom, téléphone…)</label>
                        <input v-model="filtres.recherche" type="text" placeholder="Rechercher…" :class="CHAMP_INPUT">
                    </div>

                    <!-- Statut (avecStatut uniquement — voir docblock en tête de
                         fichier). Pas d'option "Tous" : les radios ne peuvent pas
                         se décocher nativement, revenir à "Tous" passe par la
                         puce Statut ou par Réinitialiser. -->
                    <div v-if="avecStatut" class="mt-2">
                        <label :class="CHAMP_LABEL">🏷️ Statut</label>
                        <div class="flex flex-wrap gap-1.5">
                            <label v-for="etat in etatsDisponibles" :key="etat" class="cursor-pointer">
                                <input type="radio" :value="etat" v-model="filtres.etat_dossier" class="sr-only peer">
                                <span class="inline-flex px-2.5 py-1 rounded-full text-[11.5px] font-semibold border peer-checked:ring-2 peer-checked:ring-offset-1 peer-checked:ring-accent"
                                    :class="etatCouleurs[etat] ?? ''">{{ etat }}</span>
                            </label>
                        </div>
                    </div>

                    <!-- Assigné à (avecAssignation) : une personne précise OU
                         "Non assigné" — mutuellement exclusifs. -->
                    <div v-if="avecAssignation" class="mt-2">
                        <label :class="CHAMP_LABEL">👤 Assigné à</label>
                        <div class="space-y-1.5">
                            <PersonSelect role="gestionnaire" placeholder="Toutes les personnes"
                                :model-value="assigneeChoisi" @update:model-value="choisirAssignee" />
                            <label :class="CHIP_LABEL">
                                <input type="checkbox" :checked="Boolean(filtres.non_assigne)" @change="basculerNonAssigne"
                                    class="w-3.5 h-3.5 accent-accent"> Non assigné
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Groupe 2 : localisation & organisation -->
                <div :class="GROUPE_CARTE">
                    <div :class="GROUPE_LABEL">📍 Localisation &amp; organisation</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <div>
                            <label :class="CHAMP_LABEL">🏙️ Ville</label>
                            <select v-model="filtres.id_ville" @change="filtres.id_secteur = ''; filtres.id_quartier = ''" :class="CHAMP_INPUT">
                                <option value="">Toutes</option>
                                <option v-for="v in villes" :key="v.id" :value="v.id">{{ v.nom }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="CHAMP_LABEL">Secteur</label>
                            <select v-model="filtres.id_secteur" @change="filtres.id_quartier = ''" :class="CHAMP_INPUT">
                                <option value="">Tous</option>
                                <option v-for="s in secteursFiltres()" :key="s.id" :value="s.id">{{ s.nom }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label :class="CHAMP_LABEL">📌 Quartier</label>
                            <select v-model="filtres.id_quartier" :class="CHAMP_INPUT">
                                <option value="">Tous</option>
                                <option v-for="q in quartiersFiltres()" :key="q.id" :value="q.id">{{ q.nom }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="CHAMP_LABEL">🏢 D'origine</label>
                            <select v-model="filtres.id_organisation_origine" :class="CHAMP_INPUT">
                                <option value="">Toutes</option>
                                <option v-for="o in organisations" :key="o.id" :value="o.id">{{ o.nom }}</option>
                            </select>
                        </div>
                        <div>
                            <label :class="CHAMP_LABEL">🔗 Rattachée</label>
                            <select v-model="filtres.id_organisation_rattachee" :class="CHAMP_INPUT">
                                <option value="">Toutes</option>
                                <option v-for="o in organisations" :key="o.id" :value="o.id">{{ o.nom }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Groupe 3 : profil du foyer -->
                <div :class="GROUPE_CARTE">
                    <div :class="GROUPE_LABEL">🧾 Profil du foyer</div>
                    <div class="mb-3">
                        <label :class="CHAMP_LABEL">🎚️ Criticité</label>
                        <div class="flex flex-wrap items-center gap-1.5">
                            <label v-for="c in CRITICITES" :key="c"
                                class="flex items-center justify-center w-8 h-8 rounded-md border text-[12.5px] font-bold cursor-pointer select-none transition-colors"
                                :class="(filtres.criticite ?? []).includes(c) ? CRITICITE_ACTIVE[c] : 'border-ink-faint bg-surface text-ink-muted'">
                                <input type="checkbox" class="sr-only" :checked="(filtres.criticite ?? []).includes(c)" @change="toggleCriticite(c)">
                                {{ c }}
                            </label>
                        </div>
                    </div>
                    <div>
                        <label :class="CHAMP_LABEL">Caractéristiques</label>
                        <div class="grid grid-cols-2 gap-1.5">
                            <label v-if="avecSeDeplace" :class="CHIP_LABEL">
                                <input type="checkbox" v-model="filtres.se_deplace" class="w-3.5 h-3.5 accent-accent"> 🚗 Se déplace
                            </label>
                            <label :class="CHIP_LABEL">
                                <input type="checkbox" v-model="filtres.est_hotel" class="w-3.5 h-3.5 accent-accent"> 🏨 Hôtel
                            </label>
                            <label :class="CHIP_LABEL">
                                <input type="checkbox" v-model="filtres.etudiant" class="w-3.5 h-3.5 accent-accent"> 🎓 Étudiant
                            </label>
                            <label :class="CHIP_LABEL">
                                <input type="checkbox" v-model="filtres.zakat_el_fitr" class="w-3.5 h-3.5 accent-accent"> 🌙 Zakat El Fitr
                            </label>
                            <label :class="CHIP_LABEL">
                                <input type="checkbox" v-model="filtres.sadaqa" class="w-3.5 h-3.5 accent-accent"> 🤲 Sadaqa
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex gap-2 mt-3">
                <button type="button" @click="appliquer"
                    class="min-h-[2rem] text-[12.5px] px-4 py-1 rounded-lg bg-accent text-white">
                    Filtrer
                </button>
                <button type="button" @click="reinitialiser"
                    class="min-h-[2rem] text-[12.5px] px-3 py-1 rounded-lg border border-surface-border text-ink-muted">
                    Réinitialiser
                </button>
            </div>
        </details>

        <!-- Puces "Filtres actifs" — hors du <details> : visibles même replié. -->
        <div v-if="avecPuces && puces.length" class="flex flex-wrap items-center gap-2 mt-2 px-3 py-2 rounded-lg bg-accent/5 border border-accent/20">
            <span class="text-[10.5px] text-accent-dark uppercase tracking-wide font-bold">🔎 Filtres actifs</span>
            <button v-for="puce in puces" :key="puce.cle" type="button" @click="retirerPuce(puce)"
                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-accent/15 text-accent-dark text-[11.5px] font-semibold hover:bg-accent/25 transition-colors">
                {{ puce.label }}
                <span class="text-[10px]" aria-hidden="true">✕</span>
            </button>
            <button type="button" @click="reinitialiser"
                class="ml-auto text-[11px] text-ink-muted hover:text-accent-dark font-semibold transition-colors">
                Tout réinitialiser
            </button>
        </div>
    </div>
</template>
