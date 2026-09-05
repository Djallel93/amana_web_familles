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
-->
<script setup lang="ts">
import { ref, computed } from 'vue';
import { apiPost } from '../shared/api';
import { CAMPAGNE_TYPES, type Campagne, type CampagneType } from '../shared/types';

const el = document.getElementById('vue-livraison-campagnes-index');
const storeUrl = el?.dataset.storeUrl ?? '';
const campagnes = ref<Campagne[]>(JSON.parse(el?.dataset.campagnes ?? '[]'));

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

const campagnesTriees = computed(() =>
    [...campagnes.value].sort((a, b) => (a.date_livraison < b.date_livraison ? 1 : -1)),
);
</script>

<template>
    <div>
        <div class="bg-surface border border-surface-border rounded-xl p-5 mb-8">
            <h2 class="text-[14px] font-medium text-ink mb-4">Nouvelle campagne</h2>
            <form class="space-y-4" @submit.prevent="creerCampagne">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[12px] text-ink-muted mb-1">Type</label>
                        <select v-model="form.type" class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]">
                            <option v-for="(label, code) in CAMPAGNE_TYPES" :key="code" :value="code">{{ label }}</option>
                        </select>
                        <p v-for="e in erreursPourChamp('type')" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
                    </div>
                    <div>
                        <label class="block text-[12px] text-ink-muted mb-1">Poids moyen / personne (kg)</label>
                        <input v-model="form.poids_moyen_kg" type="number" step="0.1" required
                            class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]">
                        <p v-for="e in erreursPourChamp('poids_moyen_kg')" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
                    </div>
                    <div>
                        <label class="block text-[12px] text-ink-muted mb-1">Poids moyen / personne — hôtel (kg, optionnel)</label>
                        <input v-model="form.poids_moyen_hotel_kg" type="number" step="0.1"
                            class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]">
                        <p v-for="e in erreursPourChamp('poids_moyen_hotel_kg')" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
                    </div>
                    <div>
                        <label class="block text-[12px] text-ink-muted mb-1">Poids moyen / personne — étudiant (kg, optionnel)</label>
                        <input v-model="form.poids_moyen_etudiant_kg" type="number" step="0.1"
                            class="w-full rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem]">
                        <p v-for="e in erreursPourChamp('poids_moyen_etudiant_kg')" :key="e" class="text-[11px] text-rose-600 mt-1">{{ e }}</p>
                    </div>
                </div>

                <!--
                    Liste de journées (05/09/2026) — au moins une ligne,
                    bouton de suppression masqué s'il n'en reste qu'une
                    (voir retirerLigneJournee, min 1 imposé côté serveur).
                -->
                <div>
                    <label class="block text-[12px] text-ink-muted mb-1">Journée(s) de collecte/livraison</label>
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

                <div>
                    <button type="submit" :disabled="envoiEnCours"
                        class="w-full sm:w-auto min-h-[2.5rem] rounded-lg bg-accent text-white text-[14px] font-medium px-4 py-2 disabled:opacity-60">
                        {{ envoiEnCours ? 'Création…' : 'Créer la campagne' }}
                    </button>
                    <p v-if="messageGeneral" class="text-[12.5px] text-rose-600 mt-2">{{ messageGeneral }}</p>
                </div>
            </form>
        </div>

        <h2 class="text-[14px] font-medium text-ink mb-3">Campagnes existantes</h2>
        <div class="space-y-2">
            <a v-for="campagne in campagnesTriees" :key="campagne.id" :href="`/livraison/campagnes/${campagne.id}`"
                class="block bg-surface border border-surface-border rounded-xl p-4 hover:border-accent transition-colors">
                <div class="flex items-center justify-between gap-3">
                    <span class="text-[14px] font-medium text-ink">
                        {{ CAMPAGNE_TYPES[campagne.type] ?? campagne.type }}
                        — {{ formatDateFr(campagne.date_livraison) }}
                    </span>
                    <span class="text-[12px] text-ink-muted shrink-0">{{ campagne.statut }}</span>
                </div>
            </a>
            <p v-if="campagnesTriees.length === 0" class="text-[14px] text-ink-muted">Aucune campagne pour le moment.</p>
        </div>
    </div>
</template>
