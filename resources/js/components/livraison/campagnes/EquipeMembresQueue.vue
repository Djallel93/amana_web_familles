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
-->
<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useToast } from '@amana/shared-ui';
import { apiGet, apiPost, apiDelete } from '../shared/api';
import PersonPicker from '../shared/PersonPicker.vue';
import { EQUIPE_ROLES, type Campagne, type EquipeRole, type PersonneResume } from '../shared/types';

interface LigneEquipe {
    id_personne: number;
    nom: string;
    prenom: string;
    roles: EquipeRole[];
}

const toast = useToast();

const el = document.getElementById('vue-livraison-equipe-membres')!;
const campagne = ref<Campagne>(JSON.parse(el.dataset.campagne ?? '{}'));
const listeUrl = el.dataset.listeUrl ?? '';
const ajouterUrl = el.dataset.ajouterUrl ?? '';
const retirerUrlTemplate = el.dataset.retirerUrlTemplate ?? '';

function urlRetirer(idPersonne: number, role: EquipeRole): string {
    return retirerUrlTemplate.replace('__ID__', String(idPersonne)).replace('__ROLE__', role);
}

const lignes = ref<LigneEquipe[]>([]);
const chargement = ref(true);
const erreur = ref(false);

async function chargerListe() {
    chargement.value = true;
    erreur.value = false;
    const resultat = await apiGet<LigneEquipe[]>(listeUrl);
    chargement.value = false;

    if (!resultat.ok) {
        erreur.value = true;
        return;
    }
    lignes.value = resultat.data;
}

onMounted(chargerListe);

// ── Formulaire d'ajout ───────────────────────────────────────────────
const personneChoisie = ref<PersonneResume | null>(null);
const roleChoisi = ref<EquipeRole | ''>('');
const ajoutEnCours = ref(false);

const peutAjouter = computed(() => personneChoisie.value !== null && roleChoisi.value !== '');

async function ajouter() {
    if (!peutAjouter.value || !personneChoisie.value || !roleChoisi.value) return;

    ajoutEnCours.value = true;
    const resultat = await apiPost<{ success: boolean }>(ajouterUrl, {
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
    await chargerListe();
}

async function retirer(ligne: LigneEquipe, role: EquipeRole) {
    const resultat = await apiDelete<{ success: boolean }>(urlRetirer(ligne.id_personne, role));

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    await chargerListe();
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
            <p v-if="chargement" class="text-[13px] text-ink-muted">Chargement…</p>
            <p v-else-if="erreur" class="text-[13px] text-rose-600">Liste indisponible, réessayez.</p>
            <p v-else-if="lignes.length === 0" class="text-[13px] text-ink-muted">Personne n'est encore affecté à cette campagne.</p>

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
