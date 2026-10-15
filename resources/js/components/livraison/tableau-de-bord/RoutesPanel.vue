<!-- resources/js/components/livraison/tableau-de-bord/RoutesPanel.vue -->
<!--
    Tournées d'une campagne — remplace les inputs "ID livraison à
    ajouter" / "Nvl bénévole" / "Nvl véhicule" bruts de la version
    placeholder par le picker livraison sourcé sur non-couvertes (voir
    ShortfallPanel.vue, même liste) et PersonPicker pour la réassignation ;
    remplace chaque alert() par Toast + ConfirmDialog pour retirer/scinder/
    supprimer (destructifs).

    Révisé le 09/09/2026 (prompt de cette date §5.2) :
      - VehiculePicker RETIRÉ de la réassignation (§5.2.1 : "driver info
        already includes a vehicule") — le picker bénévole ne propose que
        des bénévoles ayant déclaré un véhicule (avec-vehicule sur
        PersonPicker), le véhicule est dérivé de son profil ;
      - bouton "Supprimer" AJOUTÉ (§5.2.2 : le endpoint routes.supprimer
        existait côté serveur mais n'était wiré à aucun bouton ici) — passe
        désormais la tournée en statut 'annulee' (soft-cancel, voir
        RouteMutationService::supprimer()) plutôt que de la faire
        disparaître ;
      - pastille de statut déplacée en haut à droite de chaque tournée,
        agrandie (§5.2.1bis, même traitement que Contacts/Packaging) ;
      - chaque tournée est désormais une ligne repliable (§5.2.3) :
        repliée = ID, bénévole, créneau, avancement, pastille ; dépliée =
        table des familles avec statut modifiable manuellement (nouvel
        endpoint changerStatutEtape(), pour couvrir "in case driver does
        not [update status]").

    Réordonnancement des étapes : toujours PAS implémenté (aucun endpoint
    ordre, voir commentaire d'origine) — liste affichée dans l'ordre reçu
    (celui du TSP), lecture seule côté ordre.

    Après toute mutation (ajouter/retirer/réassigner/scinder/supprimer/
    changer un statut d'étape), on émet 'changed' pour que LiveBoard.vue
    recharge la liste complète plutôt que de fusionner la réponse
    localement : RouteMutationService renvoie le modèle via ->fresh() SANS
    les relations (benevole/vehiculeType/etapes absents du JSON de
    réponse), un refetch est donc la seule façon fiable d'obtenir l'état à
    jour.
-->
<!--
    11/10/2026 : l'édition de la COMPOSITION des tournées (ajouter/retirer une famille,
    réassigner, supprimer) a déménagé sur l'écran chargement — voir
    pages/Livraison/Chargement.vue et ChargementEditionController. Ce panneau ne garde que le
    suivi du jour J : avancement, correction manuelle du statut d'un arrêt, lien vers l'écran
    chauffeur, et un lien vers le chargement pour modifier la composition.
-->
<script setup lang="ts">
import { reactive } from "vue";
import { useToast } from "@amana/shared-ui";
import { apiPost } from "../shared/api";
import {
    LIBELLES_STATUT_ETAPE,
    LIBELLES_STATUT_ROUTE,
    STATUTS_ETAPE,
    STYLES_STATUT_ETAPE,
    STYLES_STATUT_ROUTE,
    type Etape,
    type RouteLivraison,
    type StatutEtape,
} from "../shared/types";

const props = defineProps<{
    routes: RouteLivraison[];
    chargement: boolean;
    erreur: boolean;
    urlEtapeStatut: string;
    /** Écran chargement de la campagne : composition des tournées (11/10/2026). */
    urlChargement: string;
    /** Gabarit __ID__ vers l'écran chauffeur d'une tournée (MaRouteController::voirCommeChauffeur()). */
    urlVueChauffeur: string;
}>();

const emit = defineEmits<{ changed: [] }>();

const toast = useToast();

// Pastilles de statut tournée/arrêt : palettes partagées dans shared/types.ts
// (STYLES_STATUT_ROUTE / STYLES_STATUT_ETAPE, une couleur par statut —
// 29/09/2026, prompt §6.1/§6.3). Avant : définies ici, avec chargement/charge,
// livraisons_terminees/terminee et packaging_annule/annulee en doublon.

interface EtatRoute {
    statutEnCours: Record<number, boolean>;
    // Repliée par défaut (09/09/2026, prompt §5.2.3) — même patron
    // <details>/<summary> que FamilleFilterPanel.vue.
    ouverte: boolean;
}

const etats = reactive<Record<number, EtatRoute>>({});

function etat(routeId: number): EtatRoute {
    if (!etats[routeId]) {
        etats[routeId] = { statutEnCours: {}, ouverte: false };
    }
    return etats[routeId];
}

/** Étapes avec une livraison associée — un "retour QG" n'est pas une famille (voir Etape). */
function etapesFamilles(route: RouteLivraison): Etape[] {
    return route.etapes.filter((e) => e.livraison !== null);
}

/** "3/5" — avancement affiché en résumé de la ligne repliée (prompt §5.2.3). */
function avancement(route: RouteLivraison): string {
    const familles = etapesFamilles(route);
    const traitees = familles.filter((e) => e.statut === "livree" || e.statut === "ignoree").length;
    return `${traitees}/${familles.length}`;
}

/**
 * Override manuel du statut d'un arrêt (09/09/2026, prompt §5.2.3 : "User
 * needs to be able to manually change these in case driver does not") —
 * voir LiveBoardController::changerStatutEtape().
 */
async function changerStatutEtape(route: RouteLivraison, etape: Etape, statut: StatutEtape) {
    if (etape.statut === statut) return;

    const e = etat(route.id);
    e.statutEnCours[etape.id] = true;
    const resultat = await apiPost<{ success: boolean }>(
        props.urlEtapeStatut.replace("__ID__", String(route.id)).replace("__ETAPE__", String(etape.id)),
        { statut },
    );
    e.statutEnCours[etape.id] = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    emit("changed");
}
</script>

<template>
    <div class="mb-8">
        <p v-if="chargement" class="text-[13px] text-ink-muted">Chargement…</p>
        <p v-else-if="erreur" class="text-[13px] text-rose-600">Impossible de charger les tournées.</p>
        <p v-else-if="routes.length === 0" class="text-[13px] text-ink-muted">Aucune tournée générée.</p>

        <div v-else class="space-y-3">
            <details
                v-for="route in routes"
                :key="route.id"
                class="group bg-surface border border-surface-border rounded-xl p-4"
                :open="etat(route.id).ouverte"
                @toggle="etat(route.id).ouverte = ($event.target as HTMLDetailsElement).open"
            >
                <summary
                    class="cursor-pointer list-none flex items-center justify-between gap-2 select-none -mx-1 -my-1 px-1 py-1 mb-2 rounded-lg hover:bg-surface-2 transition-colors"
                >
                    <span class="flex items-center gap-2 text-[14px] font-medium text-ink min-w-0">
                        <span
                            class="text-ink-muted text-[13px] transition-transform duration-200 group-open:rotate-90 shrink-0"
                            >▸</span
                        >
                        <span class="truncate">
                            #{{ route.id }} — {{ route.benevole?.prenom ?? "" }} {{ route.benevole?.nom ?? "" }} ({{
                                route.creneau ?? "imposée"
                            }}) — {{ avancement(route) }}
                        </span>
                    </span>
                    <span class="flex items-center gap-2 shrink-0">
                        <!-- Accès admin à l'écran du chauffeur (29/09/2026, prompt §6.2) :
                             .stop pour ne pas replier/déplier le <details> au clic. -->
                        <a
                            :href="urlVueChauffeur.replace('__ID__', String(route.id))"
                            @click.stop
                            class="text-[12px] text-accent underline min-h-[1.75rem] inline-flex items-center px-1"
                        >
                            Vue chauffeur
                        </a>
                        <span
                            class="text-[13px] font-medium px-2.5 py-1 rounded-full"
                            :class="STYLES_STATUT_ROUTE[route.statut]"
                        >
                            {{ LIBELLES_STATUT_ROUTE[route.statut] }}
                        </span>
                    </span>
                </summary>

                <table class="w-full text-[12.5px] mb-3">
                    <thead>
                        <tr class="text-left text-ink-muted border-b border-surface-border">
                            <th class="py-1.5 font-medium">#</th>
                            <th class="py-1.5 font-medium">Famille</th>
                            <th class="py-1.5 font-medium">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in route.etapes" :key="e.id" class="border-b border-surface-border last:border-0">
                            <td class="py-1.5 text-ink-muted">{{ e.ordre }}</td>
                            <td class="py-1.5 text-ink">
                                {{
                                    e.livraison
                                        ? `${e.livraison.famille.prenom} ${e.livraison.famille.nom}`
                                        : "Retour QG"
                                }}
                            </td>
                            <td class="py-1.5">
                                <!--
                                    Ajouté le 09/09/2026 (prompt de cette
                                    date §3.1) : "the down arrow is
                                    overlapping on the text" — la pastille
                                    (rounded-full px-2 py-0.5) était trop
                                    étroite pour à la fois le texte et la
                                    flèche native du select. appearance-none
                                    retire cette flèche native (jamais
                                    réintroduite en CSS ici : la pastille
                                    colorée suffit déjà à signaler qu'il
                                    s'agit d'un contrôle cliquable, comme les
                                    autres pastilles de statut de l'app qui
                                    n'ont pas de flèche du tout) et pr-2
                                    remplace le pr-2 implicite qui laissait
                                    la place à cette flèche.

                                    Corrigé le 12/09/2026 (Section E4 du
                                    refactor) : ce commentaire utilisait par
                                    erreur la syntaxe Blade {{-- --}} au lieu
                                    d'un commentaire HTML/Vue — cassait le
                                    parsing TypeScript de tout le fichier
                                    (vue-tsc --noEmit : 18 erreurs en
                                    cascade à partir de cette seule ligne,
                                    seule source d'erreur de type-check dans
                                    tout le dépôt) et faisait échouer
                                    `npm run lint` (vue/no-parsing-error).

                                    Corrigé le 29/09/2026 (prompt §6.3 : « la flèche
                                    du dropdown chevauche le libellé ») : la
                                    supposition ci-dessus était fausse —
                                    appearance-none retire la flèche NATIVE mais
                                    @tailwindcss/forms dessine son propre chevron en
                                    background-image sur tout <select>, et pr-2
                                    écrasait le padding-right: 2.5rem prévu pour lui.
                                    Le chevron est donc bien là : pr-7 lui laisse la
                                    place, et la règle .statut-select (bas du
                                    fichier) le réduit/repositionne pour la pastille.
                                -->
                                <select
                                    v-if="e.livraison"
                                    :value="e.statut"
                                    :disabled="etat(route.id).statutEnCours[e.id]"
                                    @change="
                                        changerStatutEtape(
                                            route,
                                            e,
                                            ($event.target as HTMLSelectElement).value as StatutEtape,
                                        )
                                    "
                                    class="statut-select text-[11.5px] font-medium rounded-full pl-2.5 pr-7 py-0.5 border-0 appearance-none disabled:opacity-60 cursor-pointer"
                                    :class="STYLES_STATUT_ETAPE[e.statut]"
                                >
                                    <option v-for="s in STATUTS_ETAPE" :key="s" :value="s">
                                        {{ LIBELLES_STATUT_ETAPE[s] }}
                                    </option>
                                </select>
                                <span v-else class="text-[11.5px] text-ink-muted">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="border-t border-surface-border pt-3">
                    <a :href="urlChargement" class="text-[12px] text-accent underline">
                        Modifier la composition de la tournée → écran Chargement
                    </a>
                </div>
            </details>
        </div>
    </div>
</template>

<style scoped>
/* Chevron @tailwindcss/forms adapté à une pastille (voir le commentaire du
   <select> de statut ci-dessus) : plus petit et plus près du bord que le
   1.5em / .5rem par défaut, pour ne jamais empiéter sur le libellé. */
.statut-select {
    background-size: 1.1em 1.1em;
    background-position: right 0.4rem center;
}
</style>
