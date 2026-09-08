<!-- resources/js/components/livraison/campagnes/CampagnesIndex.vue -->
<!--
    Écran Campagnes (liste + création) — reconstruit en Vue le
    03/09/2026, voir resources/views/livraison/campagnes.blade.php pour
    la coquille Blade et le patch de migration frontend livraison pour le
    contexte complet.

    Remplace la version placeholder : formulaire vanilla JS avec
    alert(JSON.stringify(...)) sur erreur → erreurs de validation
    affichées champ par champ (voir erreursPourChampJournee ci-dessous).

    JOURNÉES MULTIPLES À LA CRÉATION (05/09/2026, suivi du prompt §3.1/
    §3.2) : le champ unique "Date de livraison" est remplacé par une
    liste dynamique de journées (date + label optionnel, au moins une),
    envoyée à CampagnesController::store() qui crée une CampagneJournee
    par ligne — voir Campagne::ajouterJournee(). Le cas mono-jour (le
    plus courant) est simplement une liste à une seule ligne, sans
    changement de comportement perçu par rapport à avant cette évolution.

    SUPPRESSION + BOUTONS/FILTRES/SECTIONS (08/09/2026, prompt de cette
    date §2.1/§2.2) :
      - suppression en cascade (voir CampagnesController::destroy() —
        cascadeOnDelete() en base sur toute la chaîne, rien à faire ici
        d'autre qu'afficher l'aperçu des répercussions et confirmer) ;
      - chaque ligne devient éditer/supprimer plutôt qu'un lien plein sur
        toute la ligne (édition = simplement la même navigation
        qu'avant, vers la page détail, qui sert déjà de surface
        d'édition — voir CampagneDetail.vue) ;
      - filtre type + date sur "Campagnes existantes" ;
      - "Nouvelle campagne" réorganisée en sections (Type, Poids,
        Journées, Paramètres avancés), type sur sa propre ligne en
        pastilles colorées (voir CAMPAGNE_TYPE_STYLES) plutôt qu'un
        <select> natif — aucun composant listbox/Teleport réutilisable
        dans cette app (contrairement à amana_web_planning), un simple
        groupe de boutons façon "segmented control" reste plus cohérent
        avec le reste de l'app (boutons bruts partout ailleurs) qu'une
        dépendance nouvelle pour ce seul écran — et les poids partagent
        une seule ligne à 3 colonnes.
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { useConfirm, useToast } from '@amana/shared-ui';
import { apiDelete, apiGet, apiPost } from '../shared/api';
import { CAMPAGNE_TYPES, type Campagne, type CampagneType } from '../shared/types';

const el = document.getElementById('vue-livraison-campagnes-index');
const storeUrl = el?.dataset.storeUrl ?? '';
const resumeSuppressionUrlTemplate = el?.dataset.resumeSuppressionUrlTemplate ?? '';
const destroyUrlTemplate = el?.dataset.destroyUrlTemplate ?? '';
const livraisonsMaxParTourneeDefaut = el?.dataset.livraisonsMaxParTourneeDefaut ?? '';
const campagnes = ref<Campagne[]>(JSON.parse(el?.dataset.campagnes ?? '[]'));

const toast = useToast();
const confirmDialog = useConfirm();

/**
 * Couleurs des pastilles de type — reprises pour le sélecteur du
 * formulaire ET le badge de chaque ligne "Campagnes existantes", pour
 * qu'un type se reconnaisse visuellement au premier coup d'œil aux deux
 * endroits (prompt du 08/09/2026 §2.2.1/§2.3).
 */
const CAMPAGNE_TYPE_STYLES: Record<CampagneType, { pastille: string; pastilleActive: string }> = {
    zakat_el_fitr: {
        pastille: 'bg-emerald-100 text-emerald-700 border-emerald-200',
        pastilleActive: 'bg-emerald-600 text-white border-emerald-600',
    },
    collecte_alimentaire: {
        pastille: 'bg-amber-100 text-amber-700 border-amber-200',
        pastilleActive: 'bg-amber-600 text-white border-amber-600',
    },
    don_ponctuel: {
        pastille: 'bg-sky-100 text-sky-700 border-sky-200',
        pastilleActive: 'bg-sky-600 text-white border-sky-600',
    },
};

interface FormJournee {
    date: string;
    label: string;
}

interface FormCampagne {
    type: CampagneType;
    journees: FormJournee[];
    poids_moyen_kg: string;
    poids_moyen_hotel_kg: string;
    poids_moyen_etudiant_kg: string;
    livraisons_max_par_tournee: string;
}

function nouvelleLigneJournee(): FormJournee {
    return { date: '', label: '' };
}

const form = ref<FormCampagne>({
    type: 'zakat_el_fitr',
    journees: [nouvelleLigneJournee()],
    poids_moyen_kg: '',
    poids_moyen_hotel_kg: '',
    poids_moyen_etudiant_kg: '',
    // Préremplie depuis le réglage global (voir data-livraisons-max-par-
    // tournee-defaut, CampagnesController::index()) — éditable avant
    // envoi, laissée vide retombe de toute façon sur le même réglage
    // côté serveur (voir store()).
    livraisons_max_par_tournee: livraisonsMaxParTourneeDefaut,
});

function ajouterLigneJournee() {
    form.value.journees.push(nouvelleLigneJournee());
}

function retirerLigneJournee(index: number) {
    // Toujours au moins une ligne — voir validation serveur
    // (journees required|array|min:1).
    if (form.value.journees.length <= 1) return;
    form.value.journees.splice(index, 1);
}

const fieldErrors = ref<Record<string, string[]>>({});
const messageGeneral = ref('');
const envoiEnCours = ref(false);

function erreursPourChamp(champ: string): string[] {
    return fieldErrors.value[champ] ?? [];
}

// Erreurs de validation d'une ligne de journée — Laravel renvoie des
// clés du type "journees.0.date" pour un tableau, pas un simple nom de
// champ plat (voir erreursPourChamp ci-dessus, utilisé pour les autres
// champs du formulaire).
function erreursPourChampJournee(index: number, champ: 'date' | 'label'): string[] {
    return fieldErrors.value[`journees.${index}.${champ}`] ?? [];
}

async function creerCampagne() {
    envoiEnCours.value = true;
    fieldErrors.value = {};
    messageGeneral.value = '';

    const resultat = await apiPost<{ success: boolean; campagne: Campagne }>(storeUrl, {
        type: form.value.type,
        journees: form.value.journees.map((j) => ({ date: j.date, label: j.label || null })),
        poids_moyen_kg: form.value.poids_moyen_kg,
        poids_moyen_hotel_kg: form.value.poids_moyen_hotel_kg || null,
        poids_moyen_etudiant_kg: form.value.poids_moyen_etudiant_kg || null,
        livraisons_max_par_tournee: form.value.livraisons_max_par_tournee || null,
    });

    envoiEnCours.value = false;

    if (!resultat.ok) {
        fieldErrors.value = resultat.errors;
        messageGeneral.value = Object.keys(resultat.errors).length === 0 ? resultat.message : '';
        return;
    }

    // Comportement identique à la version placeholder : navigation
    // directe vers le détail de la campagne créée plutôt que de recharger
    // la liste sur place (pas de raison de garder l'admin sur cette page,
    // la prochaine étape est toujours de sélectionner les familles). Si
    // d'autres journées sont nécessaires plus tard, l'écran détail a son
    // propre bouton "+ Ajouter une journée" (voir CampagneDetail.vue).
    window.location.href = `/livraison/campagnes/${resultat.data.campagne.id}`;
}

function formatDateFr(iso: string): string {
    // date_livraison est castée 'date' côté modèle : soit 'YYYY-MM-DD'
    // soit 'YYYY-MM-DDTHH:mm:ss.ssssssZ' selon la sérialisation Eloquent
    // — on ne prend que la partie calendaire pour éviter tout décalage
    // de fuseau horaire lié à un objet Date JS.
    const [annee, mois, jour] = iso.split('T')[0].split('-');
    return `${jour}/${mois}/${annee}`;
}

// ── Filtres type + date (08/09/2026, prompt §2.1.3) ─────────────────────
const filtreType = ref<CampagneType | ''>('');
const filtreDateDebut = ref('');
const filtreDateFin = ref('');

const campagnesTriees = computed(() =>
    [...campagnes.value].sort((a, b) => (a.date_livraison < b.date_livraison ? 1 : -1)),
);

const campagnesFiltrees = computed(() => campagnesTriees.value.filter((campagne) => {
    if (filtreType.value && campagne.type !== filtreType.value) return false;

    const date = campagne.date_livraison.split('T')[0];
    if (filtreDateDebut.value && date < filtreDateDebut.value) return false;
    if (filtreDateFin.value && date > filtreDateFin.value) return false;

    return true;
}));

function reinitialiserFiltres() {
    filtreType.value = '';
    filtreDateDebut.value = '';
    filtreDateFin.value = '';
}

// ── Suppression en cascade (08/09/2026, prompt §2.1/§2.2) ───────────────
// L'aperçu (resumeSuppression()) est demandé à CHAQUE clic sur
// "Supprimer" plutôt que précalculé pour toute la liste au chargement :
// ces comptages ne servent qu'à cet écran de confirmation ponctuel, pas
// affichés ailleurs sur la ligne — inutile de payer N requêtes à chaque
// visite de la page pour une info qu'on ne regarde qu'à la suppression.
const suppressionEnCours = ref<number | null>(null);

async function supprimerCampagne(campagne: Campagne) {
    suppressionEnCours.value = campagne.id;

    const resume = await apiGet<{
        journees: number; livraisons: number; routes: number;
        donations: number; arrivees: number; equipe_membres: number;
    }>(resumeSuppressionUrlTemplate.replace('__CAMPAGNE__', String(campagne.id)));

    if (!resume.ok) {
        toast.error(resume.message);
        suppressionEnCours.value = null;
        return;
    }

    const r = resume.data;
    const confirmed = await confirmDialog.ask({
        title: 'Supprimer cette campagne ?',
        message: `Cette campagne compte ${r.journees} journée(s), ${r.livraisons} livraison(s), `
            + `${r.routes} tournée(s), ${r.arrivees} arrivée(s) et ${r.donations} donation(s) enregistrée(s), `
            + `ainsi que ${r.equipe_membres} affectation(s) d'équipe. Tout sera supprimé définitivement `
            + `et irréversiblement, sans possibilité de récupération.`,
        confirmLabel: 'Supprimer définitivement',
        danger: true,
    });

    if (!confirmed) {
        suppressionEnCours.value = null;
        return;
    }

    const resultat = await apiDelete<{ success: boolean }>(destroyUrlTemplate.replace('__CAMPAGNE__', String(campagne.id)));
    suppressionEnCours.value = null;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    campagnes.value = campagnes.value.filter((c) => c.id !== campagne.id);
    toast.success('Campagne supprimée.');
}
</script>

<template>
    <div>
        <div class="bg-surface border border-surface-border rounded-xl p-5 mb-8">
            <h2 class="text-[14px] font-medium text-ink mb-4">Nouvelle campagne</h2>
            <form class="space-y-6" @submit.prevent="creerCampagne">
                <!-- Section : Type -->
                <div>
                    <h3 class="text-[12.5px] font-medium text-ink-muted uppercase tracking-wide mb-2">Type</h3>
                    <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Type de campagne">
                        <button v-for="(label, code) in CAMPAGNE_TYPES" :key="code" type="button"
                            role="radio" :aria-checked="form.type === code" @click="form.type = code as CampagneType"
                            class="min-h-[2.25rem] px-4 py-1.5 rounded-full text-[13px] font-medium border transition-colors"
                            :class="form.type === code ? CAMPAGNE_TYPE_STYLES[code as CampagneType].pastilleActive : CAMPAGNE_TYPE_STYLES[code as CampagneType].pastille">
                            {{ label }}
                        </button>
                    </div>
                    <p v-for="e in erreursPourChamp('type')" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
                </div>

                <!-- Section : Poids -->
                <div>
                    <h3 class="text-[12.5px] font-medium text-ink-muted uppercase tracking-wide mb-2">Poids</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-[12px] text-ink-muted mb-1">Poids moyen / personne (kg)</label>
                            <input v-model="form.poids_moyen_kg" type="number" step="0.1" required
                                class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]">
                            <p v-for="e in erreursPourChamp('poids_moyen_kg')" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
                        </div>
                        <div>
                            <label class="block text-[12px] text-ink-muted mb-1">— hôtel (kg, optionnel)</label>
                            <input v-model="form.poids_moyen_hotel_kg" type="number" step="0.1"
                                class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]">
                            <p v-for="e in erreursPourChamp('poids_moyen_hotel_kg')" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
                        </div>
                        <div>
                            <label class="block text-[12px] text-ink-muted mb-1">— étudiant (kg, optionnel)</label>
                            <input v-model="form.poids_moyen_etudiant_kg" type="number" step="0.1"
                                class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]">
                            <p v-for="e in erreursPourChamp('poids_moyen_etudiant_kg')" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
                        </div>
                    </div>
                </div>

                <!--
                    Section : Journées — au moins une ligne, bouton de
                    suppression masqué s'il n'en reste qu'une (voir
                    retirerLigneJournee, min 1 imposé côté serveur).
                -->
                <div>
                    <h3 class="text-[12.5px] font-medium text-ink-muted uppercase tracking-wide mb-2">Journée(s) de collecte/livraison</h3>
                    <div v-for="(journee, index) in form.journees" :key="index"
                        class="flex flex-col sm:flex-row gap-2 sm:items-start mb-2">
                        <div>
                            <input v-model="journee.date" type="date" required
                                class="rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]">
                            <p v-for="e in erreursPourChampJournee(index, 'date')" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
                        </div>
                        <div class="flex-1">
                            <input v-model="journee.label" type="text" placeholder="Label (optionnel, ex: Livraison jour 2)"
                                class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]">
                            <p v-for="e in erreursPourChampJournee(index, 'label')" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
                        </div>
                        <button v-if="form.journees.length > 1" type="button" @click="retirerLigneJournee(index)"
                            class="min-h-[2.5rem] px-3 rounded-lg border border-surface-border text-ink-muted hover:bg-stone-50 shrink-0">
                            ×
                        </button>
                    </div>
                    <p v-for="e in erreursPourChamp('journees')" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
                    <button type="button" @click="ajouterLigneJournee"
                        class="text-[12.5px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted hover:bg-stone-50 mt-1">
                        + Ajouter une date
                    </button>
                </div>

                <!-- Section : Paramètres avancés -->
                <div>
                    <h3 class="text-[12.5px] font-medium text-ink-muted uppercase tracking-wide mb-2">Paramètres avancés</h3>
                    <div class="max-w-xs">
                        <label class="block text-[12px] text-ink-muted mb-1">Nombre maximum de livraisons par tournée</label>
                        <input v-model="form.livraisons_max_par_tournee" type="number" min="1" step="1"
                            class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]">
                        <p class="text-[11px] text-ink-muted mt-1">Préremplie depuis les réglages, modifiable pour cette campagne uniquement.</p>
                        <p v-for="e in erreursPourChamp('livraisons_max_par_tournee')" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
                    </div>
                </div>

                <div>
                    <button type="submit" :disabled="envoiEnCours"
                        class="w-full sm:w-auto min-h-[2.5rem] rounded-lg bg-accent text-white text-[14px] font-medium px-4 py-2 disabled:opacity-60">
                        {{ envoiEnCours ? 'Création…' : 'Créer la campagne' }}
                    </button>
                    <p v-if="messageGeneral" class="text-[12.5px] text-rose-600 mt-2">{{ messageGeneral }}</p>
                </div>
            </form>
        </div>

        <div class="flex items-center justify-between gap-3 mb-3">
            <h2 class="text-[14px] font-medium text-ink">Campagnes existantes</h2>
        </div>

        <!-- Filtres type + date (08/09/2026, prompt §2.1.3) -->
        <div class="flex flex-wrap items-end gap-3 mb-4">
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Type</label>
                <select v-model="filtreType" class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
                    <option value="">Tous</option>
                    <option v-for="(label, code) in CAMPAGNE_TYPES" :key="code" :value="code">{{ label }}</option>
                </select>
            </div>
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Du</label>
                <input v-model="filtreDateDebut" type="date"
                    class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
            </div>
            <div>
                <label class="block text-[12px] text-ink-muted mb-1">Au</label>
                <input v-model="filtreDateFin" type="date"
                    class="rounded-lg border border-surface-border px-3 py-2 text-[13px] min-h-[2.25rem]">
            </div>
            <button v-if="filtreType || filtreDateDebut || filtreDateFin" type="button" @click="reinitialiserFiltres"
                class="text-[12.5px] text-ink-muted underline min-h-[2.25rem]">
                Réinitialiser
            </button>
        </div>

        <div class="space-y-2">
            <div v-for="campagne in campagnesFiltrees" :key="campagne.id"
                class="bg-surface border border-surface-border rounded-xl p-4 hover:border-accent transition-colors">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="shrink-0 text-[11px] font-medium px-2 py-0.5 rounded-full border"
                            :class="CAMPAGNE_TYPE_STYLES[campagne.type]?.pastille">
                            {{ CAMPAGNE_TYPES[campagne.type] ?? campagne.type }}
                        </span>
                        <span class="text-[14px] font-medium text-ink truncate">{{ formatDateFr(campagne.date_livraison) }}</span>
                        <span class="text-[12px] text-ink-muted shrink-0">{{ campagne.statut }}</span>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <a :href="`/livraison/campagnes/${campagne.id}`"
                            class="text-[12.5px] px-3 py-1.5 rounded-lg border border-surface-border text-ink-muted hover:bg-stone-50">
                            Modifier
                        </a>
                        <button type="button" :disabled="suppressionEnCours === campagne.id" @click="supprimerCampagne(campagne)"
                            class="text-[12.5px] px-3 py-1.5 rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50 disabled:opacity-60">
                            {{ suppressionEnCours === campagne.id ? 'Suppression…' : 'Supprimer' }}
                        </button>
                    </div>
                </div>
            </div>
            <p v-if="campagnesFiltrees.length === 0" class="text-[14px] text-ink-muted">Aucune campagne pour ces filtres.</p>
        </div>
    </div>
</template>
