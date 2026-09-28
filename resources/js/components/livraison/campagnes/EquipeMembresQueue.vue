<!-- resources/js/components/livraison/campagnes/EquipeMembresQueue.vue -->
<!--
    Écran d'admin pour peupler campagne_equipe_membres — voir le prompt
    du 07/09/2026 (§1) et App\Http\Controllers\Admin\Livraison\
    EquipeMembresController. Même architecture que
    BenevoleDisponibiliteQueue.vue (liste + formulaire d'ajout + retrait
    par ligne) mais sans file/statut à suivre : ici on affecte
    simplement des personnes à des rôles, il n'y a rien à "traiter".

    Décisions du 08/09/2026 (prompt de cette date) :
      - PersonPicker sans prop `role` : n'importe quelle Personne staff
        Familles peut être affectée, pas seulement celles ayant déjà le
        rôle global equipe_* correspondant (voir docblock du contrôleur).
      - Retirer un rôle n'affecte jamais les relevés déjà saisis par
        cette personne (campagne_arrivees/donations.logge_par) — aucune
        confirmation "cela supprimera aussi..." n'est nécessaire ici.

    Section E4 du refactor (16/09/2026) : ce composant n'est plus un îlot
    monté par app.ts sur #vue-livraison-equipe-membres, mais un enfant
    normal de resources/js/pages/Livraison/Equipes.vue. Les data-* lues
    jusqu'ici sur le point de montage sont devenues des props, et la
    liste arrive directement en prop de page (voir
    EquipeMembresController::index()) au lieu d'être rechargée en XHR au
    montage — d'où la disparition des états "Chargement…"/"Liste
    indisponible", qui ne peuvent plus se produire au premier rendu.
    Après ajout/retrait, router.reload({ only: ['lignes'] }) rejoue
    l'action côté serveur et ne resérialise que cette prop (pas de
    rechargement complet de page). Les mutations elles-mêmes restent en
    XHR JSON (apiPost/apiDelete inchangés) : elles renvoient
    { success: true } sans redirection, et le toast d'erreur champ par
    champ d'api.ts reste le meilleur retour utilisateur ici.

    Contrairement à DetailPanel.vue, aucun repli dataset n'est conservé :
    cet écran est le seul consommateur de ce composant (vérifié par grep
    sur EquipeMembresQueue avant conversion), il n'y a donc pas de page
    Blade non migrée à faire coexister.
-->
<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useToast } from '@amana/shared-ui';
import { apiPost, apiDelete } from '../shared/api';
import PersonPicker from '../shared/PersonPicker.vue';
import { EQUIPE_ROLES, type Campagne, type EquipeRole, type PersonneResume } from '../shared/types';

export interface LigneEquipe {
    id_personne: number;
    nom: string;
    prenom: string;
    roles: EquipeRole[];
}

const props = defineProps<{
    campagne: Campagne;
    lignes: LigneEquipe[];
    ajouterUrl: string;
    retirerUrlTemplate: string;
}>();

const toast = useToast();

function urlRetirer(idPersonne: number, role: EquipeRole): string {
    return props.retirerUrlTemplate.replace('__ID__', String(idPersonne)).replace('__ROLE__', role);
}

/**
 * Rechargement partiel Inertia plutôt qu'un apiGet() sur un endpoint
 * dédié : même effet (la liste à jour après mutation), une seule source
 * de vérité côté serveur (EquipeMembresController::index()), et l'ancien
 * endpoint `equipes/liste` disparaît avec son unique appelant.
 */
function rechargerListe() {
    router.reload({ only: ['lignes'] });
}

// ── Formulaire d'ajout ───────────────────────────────────────────────
const personneChoisie = ref<PersonneResume | null>(null);
const roleChoisi = ref<EquipeRole | ''>('');
const ajoutEnCours = ref(false);

const peutAjouter = computed(() => personneChoisie.value !== null && roleChoisi.value !== '');

async function ajouter() {
    if (!peutAjouter.value || !personneChoisie.value || !roleChoisi.value) return;

    ajoutEnCours.value = true;
    const resultat = await apiPost<{ success: boolean }>(props.ajouterUrl, {
        id_personne: personneChoisie.value.id,
        role: roleChoisi.value,
    });
    ajoutEnCours.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    toast.success('Affectation ajoutée.');
    personneChoisie.value = null;
    roleChoisi.value = '';
    rechargerListe();
}

async function retirer(ligne: LigneEquipe, role: EquipeRole) {
    const resultat = await apiDelete<{ success: boolean }>(urlRetirer(ligne.id_personne, role));

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    rechargerListe();
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="font-heading text-xl font-semibold text-ink">Équipes — {{ campagne.type }}</h1>
            <p class="text-[13px] text-ink-muted mt-1">
                Affectez les membres du staff aux postes réception / pesée / packaging / chargement de cette campagne précisément
                — indépendant du rôle global qu'ils peuvent avoir sur d'autres campagnes.
            </p>
        </div>

        <div class="bg-surface border border-surface-border rounded-xl p-4 space-y-3">
            <h2 class="text-[13.5px] font-semibold text-ink">Ajouter une affectation</h2>
            <div class="flex flex-col sm:flex-row gap-2">
                <div class="flex-1">
                    <PersonPicker placeholder="Rechercher une personne…" v-model="personneChoisie" />
                </div>
                <select v-model="roleChoisi"
                    class="rounded-lg border border-surface-border px-3 py-2 text-[14px] min-h-[2.5rem] sm:w-56">
                    <option value="" disabled>Rôle…</option>
                    <option v-for="(libelle, code) in EQUIPE_ROLES" :key="code" :value="code">{{ libelle }}</option>
                </select>
                <button type="button" :disabled="!peutAjouter || ajoutEnCours" @click="ajouter"
                    class="min-h-[2.5rem] text-[13px] px-4 py-2 rounded-lg bg-accent text-white disabled:opacity-40 disabled:cursor-not-allowed shrink-0">
                    {{ ajoutEnCours ? 'Ajout…' : 'Ajouter' }}
                </button>
            </div>
        </div>

        <div>
            <p v-if="lignes.length === 0" class="text-[13px] text-ink-muted">Personne n'est encore affecté à cette campagne.</p>

            <div v-else class="space-y-2">
                <div v-for="ligne in lignes" :key="ligne.id_personne"
                    class="flex items-center justify-between gap-3 bg-surface border border-surface-border rounded-xl p-3">
                    <span class="text-[14px] font-medium text-ink">{{ ligne.prenom }} {{ ligne.nom }}</span>
                    <div class="flex flex-wrap gap-1.5 justify-end">
                        <span v-for="role in ligne.roles" :key="role"
                            class="inline-flex items-center gap-1.5 text-[12px] px-2.5 py-1 rounded-full bg-accent/10 text-accent">
                            {{ EQUIPE_ROLES[role] }}
                            <button type="button" @click="retirer(ligne, role)"
                                class="text-accent/70 hover:text-accent" :aria-label="`Retirer ${EQUIPE_ROLES[role]}`">
                                ✕
                            </button>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
