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
<!--
    Octobre 2026 : ligne repliable redessinée — repliée : avatar du chauffeur, nom, véhicule et
    créneau, barre d'avancement, pastille de statut ; dépliée : boutons d'action (vue chauffeur,
    impression de la feuille de tournée, modification de la composition — de vrais boutons, plus de
    liens texte) puis un bloc par arrêt avec numéro d'ordre, famille, adresse et statut modifiable.
-->

<script setup lang="ts">
import { reactive } from "vue";
import { useToast } from "@amana/shared-ui";
import { apiPost } from "../shared/api";
import {
    CRENEAU_LIBELLES,
    LIBELLES_STATUT_ETAPE,
    LIBELLES_STATUT_ROUTE,
    STATUTS_ETAPE_MANUELS,
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
    /** Gabarit __ID__ vers la feuille de tournée imprimable (MaRouteController::imprimer(), octobre 2026). */
    urlImprimer: string;
}>();

const emit = defineEmits<{ changed: [] }>();

const toast = useToast();

// Pastilles de statut tournée/arrêt : palettes partagées dans shared/types.ts
// (STYLES_STATUT_ROUTE / STYLES_STATUT_ETAPE, une couleur par statut).

interface EtatRoute {
    statutEnCours: Record<number, boolean>;
    // Repliée par défaut (09/09/2026, prompt §5.2.3).
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

/** Arrêts réglés (livrés ou ignorés) — les arrêts annulés (tournée abandonnée) ne comptent pas. */
function avancement(route: RouteLivraison): { traitees: number; total: number; pct: number } {
    const familles = etapesFamilles(route).filter((e) => e.statut !== "annulee");
    const traitees = familles.filter((e) => e.statut === "livree" || e.statut === "ignoree").length;
    const total = familles.length;
    return { traitees, total, pct: total > 0 ? Math.round((traitees / total) * 100) : 0 };
}

function initiales(route: RouteLivraison): string {
    const b = route.benevole;
    if (!b) return "?";
    return `${b.prenom?.charAt(0) ?? ""}${b.nom?.charAt(0) ?? ""}`.toUpperCase() || "?";
}

function adresseFamille(e: Etape): string {
    const f = e.livraison?.famille;
    if (!f) return "";
    return [f.adresse, [f.code_postal, f.ville_texte].filter(Boolean).join(" ")].filter(Boolean).join(", ");
}

/**
 * Override manuel du statut d'un arrêt (09/09/2026, prompt §5.2.3 : "User needs to be able to
 * manually change these in case driver does not") — voir LiveBoardController::changerStatutEtape().
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

const BOUTON =
    "inline-flex items-center gap-1.5 min-h-[2.25rem] px-3.5 py-1.5 rounded-full border border-surface-border bg-surface text-[12.5px] font-medium text-ink no-underline hover:bg-surface-2 transition-colors";
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
                class="group bg-surface border border-surface-border rounded-2xl shadow-sm overflow-hidden"
                :class="route.statut === 'annulee' ? 'opacity-70' : ''"
                :open="etat(route.id).ouverte"
                @toggle="etat(route.id).ouverte = ($event.target as HTMLDetailsElement).open"
            >
                <summary
                    class="cursor-pointer list-none flex flex-wrap items-center gap-x-4 gap-y-2 select-none px-4 py-3 hover:bg-surface-2 transition-colors"
                >
                    <span
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-accent/10 text-accent text-[13px] font-semibold"
                        aria-hidden="true"
                        >{{ initiales(route) }}</span
                    >
                    <span class="min-w-0 flex-1 basis-48">
                        <span class="block truncate text-[14.5px] font-semibold text-ink">
                            {{
                                route.benevole
                                    ? `${route.benevole.prenom} ${route.benevole.nom}`
                                    : "Chauffeur non assigné"
                            }}
                        </span>
                        <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[12px] text-ink-muted">
                            <span>Tournée #{{ route.id }}</span>
                            <span v-if="route.vehicule_type">· 🚗 {{ route.vehicule_type.type }}</span>
                            <span>· 🕒 {{ route.creneau ? CRENEAU_LIBELLES[route.creneau] : "Imposée" }}</span>
                        </span>
                    </span>
                    <span class="flex w-36 shrink-0 flex-col gap-1">
                        <span class="flex items-baseline justify-between text-[11.5px] text-ink-muted">
                            <span>Avancement</span>
                            <span class="font-semibold text-ink">
                                {{ avancement(route).traitees }}/{{ avancement(route).total }}
                            </span>
                        </span>
                        <span class="h-1.5 w-full overflow-hidden rounded-full bg-stone-200" aria-hidden="true">
                            <span
                                class="block h-full rounded-full bg-emerald-500 transition-all"
                                :style="{ width: `${avancement(route).pct}%` }"
                            ></span>
                        </span>
                    </span>
                    <span class="flex shrink-0 items-center gap-2">
                        <span
                            class="text-[12.5px] font-medium px-3 py-1 rounded-full"
                            :class="STYLES_STATUT_ROUTE[route.statut]"
                        >
                            {{ LIBELLES_STATUT_ROUTE[route.statut] }}
                        </span>
                        <span
                            class="text-ink-muted text-[13px] transition-transform duration-200 group-open:rotate-90"
                            aria-hidden="true"
                            >▸</span
                        >
                    </span>
                </summary>

                <div class="border-t border-surface-border px-4 py-4">
                    <!-- Actions : de vrais boutons en pastille (octobre 2026), plus de liens texte. -->
                    <div class="mb-4 flex flex-wrap gap-2">
                        <a :href="urlVueChauffeur.replace('__ID__', String(route.id))" role="button" :class="BOUTON">
                            👁️ Vue chauffeur
                        </a>
                        <a
                            :href="urlImprimer.replace('__ID__', String(route.id))"
                            target="_blank"
                            rel="noopener"
                            role="button"
                            :class="BOUTON"
                        >
                            🖨️ Imprimer la tournée
                        </a>
                        <a :href="urlChargement" role="button" :class="BOUTON">✏️ Modifier la composition</a>
                    </div>

                    <p v-if="etapesFamilles(route).length === 0" class="text-[12.5px] text-ink-muted">
                        Aucun arrêt dans cette tournée.
                    </p>
                    <ol v-else class="space-y-2">
                        <li
                            v-for="e in route.etapes"
                            :key="e.id"
                            class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-surface-border bg-stone-50 px-3 py-2.5"
                        >
                            <span
                                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-ink text-[12px] font-semibold text-white"
                                >{{ e.ordre }}</span
                            >
                            <span class="min-w-0 flex-1 basis-48">
                                <span class="block truncate text-[13.5px] font-medium text-ink">
                                    {{
                                        e.livraison
                                            ? `${e.livraison.famille.prenom} ${e.livraison.famille.nom}`
                                            : "Retour QG"
                                    }}
                                </span>
                                <span v-if="e.livraison" class="block truncate text-[12px] text-ink-muted">
                                    {{ adresseFamille(e) }}
                                </span>
                            </span>
                            <!--
                                Pastille de statut modifiable à la main (09/09/2026). appearance-none +
                                pr-7 : @tailwindcss/forms dessine son propre chevron, la règle
                                .statut-select (bas du fichier) le réduit pour la pastille. Un arrêt
                                « annulée » (tournée abandonnée) est posé par le système : pastille fixe.
                            -->
                            <span
                                v-if="e.statut === 'annulee'"
                                class="text-[11.5px] font-medium rounded-full px-2.5 py-0.5"
                                :class="STYLES_STATUT_ETAPE.annulee"
                                >{{ LIBELLES_STATUT_ETAPE.annulee }}</span
                            >
                            <select
                                v-else-if="e.livraison"
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
                                <option v-for="s in STATUTS_ETAPE_MANUELS" :key="s" :value="s">
                                    {{ LIBELLES_STATUT_ETAPE[s] }}
                                </option>
                            </select>
                            <span v-else class="text-[11.5px] text-ink-muted">—</span>
                        </li>
                    </ol>
                </div>
            </details>
        </div>
    </div>
</template>

<style scoped>
/* Chevron @tailwindcss/forms adapté à une pastille : plus petit et plus près du bord que le
   1.5em / .5rem par défaut, pour ne jamais empiéter sur le libellé. */
.statut-select {
    background-size: 1.1em 1.1em;
    background-position: right 0.4rem center;
}
</style>
