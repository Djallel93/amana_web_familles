<!-- resources/js/components/livraison/campagnes/EquipeMembresQueue.vue -->
<!--
    Écran d'admin pour peupler campagne_equipe_membres — voir le prompt
    du 07/09/2026 (§1) et App\Http\Controllers\Admin\Livraison\
    EquipeMembresController. Même architecture que
    BenevoleDisponibiliteQueue.vue (liste + formulaire d'ajout + retrait
    par ligne) mais sans file/statut à suivre : ici on affecte
    simplement des personnes à des rôles, il n'y a rien à "traiter".

    29/09/2026 (prompt de cette date §3) : une SECTION par rôle (Réception,
    Pesée, Packaging, Chargement), chacune avec ses membres et son propre
    sélecteur d'ajout — plus de formulaire global avec choix du rôle (le rôle
    est celui de la section). Le sélecteur est PersonSelect (dropdown avec
    recherche par début de prénom/nom), le même composant que l'assignation
    de contacts. Les données ne changent pas : `lignes` reste « une ligne par
    personne, rôles groupés », les sections la filtrent côté client.

    Décisions du 08/09/2026 (prompt de cette date) :
      - PersonSelect sans prop `role` : n'importe quelle Personne staff
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
import { reactive, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useToast } from '@amana/shared-ui';
import { apiPost, apiDelete } from '../shared/api';
import PersonSelect from '../shared/PersonSelect.vue';
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
const roles = Object.keys(EQUIPE_ROLES) as EquipeRole[];

// Un sélecteur et un état « ajout en cours » par rôle (section indépendante).
const personneChoisie = reactive<Record<EquipeRole, PersonneResume | null>>({
    equipe_reception: null,
    equipe_pesee: null,
    equipe_packaging: null,
    equipe_chargement: null,
});
const ajoutEnCours = reactive<Record<EquipeRole, boolean>>({
    equipe_reception: false,
    equipe_pesee: false,
    equipe_packaging: false,
    equipe_chargement: false,
});

/** Membres d'une section : les lignes qui portent ce rôle, triées nom/prénom. */
const membresParRole = computed(() => {
    const parRole = {} as Record<EquipeRole, LigneEquipe[]>;
    for (const role of roles) {
        parRole[role] = props.lignes
            .filter((ligne) => ligne.roles.includes(role))
            .sort((a, b) => a.nom.localeCompare(b.nom, 'fr') || a.prenom.localeCompare(b.prenom, 'fr'));
    }
    return parRole;
});

async function ajouter(role: EquipeRole) {
    const personne = personneChoisie[role];
    if (!personne) return;

    ajoutEnCours[role] = true;
    const resultat = await apiPost<{ success: boolean }>(props.ajouterUrl, {
        id_personne: personne.id,
        role,
    });
    ajoutEnCours[role] = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    toast.success(`${personne.prenom} ${personne.nom} ajouté(e) — ${EQUIPE_ROLES[role]}.`);
    personneChoisie[role] = null;
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

        <section v-for="role in roles" :key="role" class="bg-surface border border-surface-border rounded-xl p-4 space-y-3">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-[14px] font-semibold text-ink">{{ EQUIPE_ROLES[role] }}</h2>
                <span class="text-[12px] text-ink-muted">{{ membresParRole[role].length }} membre(s)</span>
            </div>

            <div class="flex flex-col sm:flex-row gap-2">
                <div class="flex-1">
                    <PersonSelect placeholder="Ajouter une personne…" v-model="personneChoisie[role]" />
                </div>
                <button type="button" :disabled="!personneChoisie[role] || ajoutEnCours[role]" @click="ajouter(role)"
                    class="min-h-[2.5rem] text-[13px] px-4 py-2 rounded-lg bg-accent text-white disabled:opacity-40 disabled:cursor-not-allowed shrink-0">
                    {{ ajoutEnCours[role] ? 'Ajout…' : 'Ajouter' }}
                </button>
            </div>

            <p v-if="membresParRole[role].length === 0" class="text-[13px] text-ink-muted">Personne n'est encore affecté à ce poste.</p>

            <div v-else class="space-y-2">
                <div v-for="ligne in membresParRole[role]" :key="ligne.id_personne"
                    class="flex items-center justify-between gap-3 bg-surface-2 border border-surface-border rounded-lg px-3 py-2">
                    <span class="text-[14px] font-medium text-ink">{{ ligne.prenom }} {{ ligne.nom }}</span>
                    <button type="button" @click="retirer(ligne, role)"
                        class="text-[12px] text-ink-muted hover:text-rose-600 min-h-[2rem] px-2" :aria-label="`Retirer ${ligne.prenom} ${ligne.nom} — ${EQUIPE_ROLES[role]}`">
                        Retirer
                    </button>
                </div>
            </div>
        </section>
    </div>
</template>
