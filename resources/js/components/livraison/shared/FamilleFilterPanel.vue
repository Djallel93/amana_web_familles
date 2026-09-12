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
import type { FamilleFiltres, FamilleSuggestion, Organisation, Quartier, Secteur, Ville } from './types';

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
}>(), {
    avecStatut: false,
    etatsDisponibles: () => [],
    etatCouleurs: () => ({}),
    avecAutocompletion: false,
    suggestionsUrl: '',
    ouvertParDefaut: false,
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
const GROUPE_LABEL = 'text-[10px] font-bold text-ink-muted uppercase tracking-wide mb-2';
const CHAMP_LABEL = 'block text-[10.5px] font-semibold text-ink-muted mb-1';
const CHIP_LABEL = 'flex items-center gap-2 px-3 py-2 border border-ink-faint rounded-md text-[12.5px] text-ink-muted cursor-pointer select-none transition-colors has-[:checked]:border-accent has-[:checked]:bg-accent/5 has-[:checked]:text-ink has-[:checked]:font-semibold';

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

function reinitialiser() {
    Object.assign(filtres, {
        id_ville: '', id_secteur: '', id_quartier: '', criticite: [],
        se_deplace: false, est_hotel: false, etudiant: false,
        zakat_el_fitr: false, sadaqa: false,
        id_organisation_origine: '', id_organisation_rattachee: '', recherche: '',
        // etat_dossier remis à '' (pas omis) : seul moyen de revenir à
        // "Tous" (pas d'option "Tous" dans les pastilles elles-mêmes, voir
        // le docblock du groupe Statut plus bas) — même comportement que
        // le lien "Tout réinitialiser" d'origine dans index.blade.php.
        etat_dossier: '', nom: '', telephone: '', id_selection: '',
    });
    emit('filtrer');
}

// Repliable (08/09/2026, prompt §2.2.3/§3.4 : "like in dossier famille")
// — même pattern que familles/index.blade.php : <details>/<summary>
// natif plutôt qu'un ref + v-show, pour bénéficier gratuitement du même
// comportement (contenu simplement masqué, pas démonté — les v-model
// des champs restent actifs même repliés) sans dupliquer de logique JS.
// Badge "actifs" affiché même repliée, pour ne pas cacher silencieusement
// qu'un filtre est appliqué.
const filtresActifs = computed(() => Boolean(
    filtres.recherche || filtres.id_ville || filtres.id_secteur || filtres.id_quartier
    || (filtres.criticite ?? []).length > 0
    || filtres.se_deplace || filtres.est_hotel || filtres.etudiant
    || filtres.zakat_el_fitr || filtres.sadaqa
    || filtres.id_organisation_origine || filtres.id_organisation_rattachee
    || (props.avecStatut && filtres.etat_dossier)
    || (props.avecAutocompletion && (filtres.nom || filtres.telephone)),
));

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
    <details class="group bg-surface border border-surface-border rounded-xl p-4 mb-4" :open="ouvertParDefaut">
        <summary class="cursor-pointer list-none flex items-center justify-between select-none -mx-1 -my-1 px-1 py-1 mb-2 rounded-lg hover:bg-surface-2 transition-colors">
            <span class="text-[13px] font-bold text-ink flex items-center gap-1.5">
                🔎 Filtres
                <span v-if="filtresActifs" class="px-1.5 py-0.5 rounded-full bg-accent/10 text-accent-dark text-[10px] font-bold">actifs</span>
            </span>
            <span class="text-ink-muted text-[13px] transition-transform duration-200 group-open:rotate-180">▾</span>
        </summary>

        <!-- Recherche : Nom/Téléphone + autocomplétion (avecAutocompletion),
             sinon le champ "recherche" libre générique. -->
        <div v-if="avecAutocompletion" class="mb-4 grid grid-cols-1 sm:grid-cols-2 gap-2 sm:max-w-lg">
            <div class="relative">
                <label :class="CHAMP_LABEL">Nom</label>
                <input v-model="filtres.nom" type="text" placeholder="Nom ou prénom…" autocomplete="off"
                    @input="onSaisieNom"
                    class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                <div v-if="suggestionsNom.length" class="absolute z-20 left-0 right-0 mt-1 bg-surface border border-surface-border rounded-lg shadow-lg overflow-hidden">
                    <button v-for="s in suggestionsNom" :key="s.id" type="button" @click="choisirSuggestion('nom', s)"
                        class="w-full text-left px-3 py-2 hover:bg-surface-2 text-[12.5px] flex items-center justify-between gap-2 border-b border-surface-3 last:border-b-0">
                        <span class="font-semibold text-ink">{{ s.label }}</span>
                        <span class="text-ink-muted text-[11px]">{{ s.sous_label }}</span>
                    </button>
                </div>
            </div>
            <div class="relative">
                <label :class="CHAMP_LABEL">Téléphone</label>
                <input v-model="filtres.telephone" type="text" placeholder="Numéro…" autocomplete="off"
                    @input="onSaisieTelephone"
                    class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                <div v-if="suggestionsTelephone.length" class="absolute z-20 left-0 right-0 mt-1 bg-surface border border-surface-border rounded-lg shadow-lg overflow-hidden">
                    <button v-for="s in suggestionsTelephone" :key="s.id" type="button" @click="choisirSuggestion('telephone', s)"
                        class="w-full text-left px-3 py-2 hover:bg-surface-2 text-[12.5px] flex items-center justify-between gap-2 border-b border-surface-3 last:border-b-0">
                        <span class="font-semibold text-ink">{{ s.label }}</span>
                        <span class="text-ink-muted text-[11px]">{{ s.sous_label }}</span>
                    </button>
                </div>
            </div>
        </div>
        <div v-else class="mb-4">
            <label :class="CHAMP_LABEL">🔎 Recherche (nom, téléphone…)</label>
            <input v-model="filtres.recherche" type="text" placeholder="Rechercher…"
                class="w-full sm:w-96 rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
        </div>

        <!-- Statut (avecStatut uniquement — voir docblock en tête de
             fichier). Pas d'option "Tous" pour la même raison que dans
             index.blade.php : les radios ne peuvent pas se décocher
             nativement, revenir à "Tous" passe par reinitialiser(). -->
        <div v-if="avecStatut" class="mb-4">
            <label :class="[CHAMP_LABEL, 'mb-1']">🏷️ Statut</label>
            <div class="flex flex-wrap gap-1.5">
                <label v-for="etat in etatsDisponibles" :key="etat" class="cursor-pointer">
                    <input type="radio" :value="etat" v-model="filtres.etat_dossier" class="sr-only peer">
                    <span class="inline-flex px-2.5 py-1 rounded-full text-[11.5px] font-semibold border peer-checked:ring-2 peer-checked:ring-offset-1 peer-checked:ring-current peer-checked:font-bold transition-all"
                        :class="etatCouleurs[etat] ?? ''">{{ etat }}</span>
                </label>
            </div>
        </div>

        <div class="mb-4">
            <div :class="GROUPE_LABEL">📍 Localisation</div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label :class="CHAMP_LABEL">🏙️ Ville</label>
                    <select v-model="filtres.id_ville" @change="filtres.id_secteur = ''; filtres.id_quartier = ''"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                        <option value="">Toutes</option>
                        <option v-for="v in villes" :key="v.id" :value="v.id">{{ v.nom }}</option>
                    </select>
                </div>
                <div>
                    <label :class="CHAMP_LABEL">Secteur</label>
                    <select v-model="filtres.id_secteur" @change="filtres.id_quartier = ''"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                        <option value="">Tous</option>
                        <option v-for="s in secteursFiltres()" :key="s.id" :value="s.id">{{ s.nom }}</option>
                    </select>
                </div>
                <div>
                    <label :class="CHAMP_LABEL">📌 Quartier</label>
                    <select v-model="filtres.id_quartier"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                        <option value="">Tous</option>
                        <option v-for="q in quartiersFiltres()" :key="q.id" :value="q.id">{{ q.nom }}</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <div :class="GROUPE_LABEL">🏢 Organisation</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label :class="CHAMP_LABEL">D'origine</label>
                    <select v-model="filtres.id_organisation_origine"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                        <option value="">Toutes</option>
                        <option v-for="o in organisations" :key="o.id" :value="o.id">{{ o.nom }}</option>
                    </select>
                </div>
                <div>
                    <label :class="CHAMP_LABEL">🔗 Rattachée</label>
                    <select v-model="filtres.id_organisation_rattachee"
                        class="w-full rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                        <option value="">Toutes</option>
                        <option v-for="o in organisations" :key="o.id" :value="o.id">{{ o.nom }}</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <label :class="[CHAMP_LABEL, 'mb-1.5']">🎚️ Criticité</label>
            <div class="flex items-center gap-1.5">
                <label v-for="c in CRITICITES" :key="c"
                    class="flex items-center justify-center w-7 h-7 rounded-full border text-[12px] cursor-pointer select-none"
                    :class="(filtres.criticite ?? []).includes(c) ? 'border-accent bg-accent/10 text-ink font-semibold' : 'border-surface-border text-ink-muted'">
                    <input type="checkbox" class="sr-only" :checked="(filtres.criticite ?? []).includes(c)" @change="toggleCriticite(c)">
                    {{ c }}
                </label>
            </div>
        </div>

        <div class="mb-4">
            <label :class="[CHAMP_LABEL, 'mb-1.5']">Caractéristiques</label>
            <div class="flex flex-wrap gap-2">
                <label :class="CHIP_LABEL">
                    <input type="checkbox" v-model="filtres.se_deplace" class="w-3.5 h-3.5 accent-accent"> Se déplace
                </label>
                <label :class="CHIP_LABEL">
                    <input type="checkbox" v-model="filtres.est_hotel" class="w-3.5 h-3.5 accent-accent"> Hôtel
                </label>
                <label :class="CHIP_LABEL">
                    <input type="checkbox" v-model="filtres.etudiant" class="w-3.5 h-3.5 accent-accent"> Étudiant
                </label>
                <label :class="CHIP_LABEL">
                    <input type="checkbox" v-model="filtres.zakat_el_fitr" class="w-3.5 h-3.5 accent-accent"> Zakat el-fitr
                </label>
                <label :class="CHIP_LABEL">
                    <input type="checkbox" v-model="filtres.sadaqa" class="w-3.5 h-3.5 accent-accent"> Sadaqa
                </label>
            </div>
        </div>

        <div class="flex gap-2">
            <button type="button" @click="emit('filtrer')"
                class="min-h-[2.25rem] text-[13px] px-4 py-1.5 rounded-lg bg-accent text-white">
                Filtrer
            </button>
            <button type="button" @click="reinitialiser"
                class="min-h-[2.25rem] text-[13px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted">
                Réinitialiser
            </button>
        </div>
    </details>
</template>
