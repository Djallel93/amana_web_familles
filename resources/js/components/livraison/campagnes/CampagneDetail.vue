<!-- resources/js/components/livraison/campagnes/CampagneDetail.vue -->
<!--
    Hub de la campagne (03/10/2026) — remplace l'ancien écran détail (qui
    portait HQ, journées, sélection des familles, génération des routes et
    une rangée de pastilles). Le gestionnaire/admin y voit d'un coup d'œil
    où en sont les processus et y accède par de grandes cartes, groupées par
    phase :

      Avant la campagne : sélection des familles, suivi des contacts, suivi
                          des bénévoles ;
      Préparation       : réception, pesée, packaging ;
      Génération des routes (17/10/2026, section à part) : « Démarrer la
                          campagne » puis, une fois démarrée, « Génération des
                          routes » (assistant en fenêtre) avec, à côté du statut,
                          le nombre de familles encore sans tournée ;
      Livraison         : chargement, suivi livraison, retrait QG — grisés
                          tant que la campagne n'est pas démarrée ;
      Incidents         : section repliable avec badge du nombre d'incidents
                          ouverts (06/10/2026, remplace la carte et la page).

    Ce qui a quitté cet écran : barre de progression (supprimée), sélection
    des familles (page dédiée + barre latérale), HQ/journées/équipes (page
    Paramètres). La génération des routes, qui vivait sur Suivi livraison
    (03/10/2026), est revenue ici le 06/10/2026 sous forme d'assistant.
    Statistiques, Paramètres et Terminer/Rouvrir sont en boutons en haut à
    droite. Les badges viennent de /avancement, rafraîchi toutes les 20 s.
-->
<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useConfirm, useToast } from "@amana/shared-ui";
import { apiGet, apiPost } from "../shared/api";
import {
    CAMPAGNE_TYPES,
    type AvancementCampagne,
    type Campagne,
    type GenerationUrls,
    type IncidentsUrls,
    type Organisation,
    type Quartier,
    type Secteur,
    type Ville,
} from "../shared/types";
import { CAMPAGNE_STATUT_LABELS, formatDateFr } from "./campagneStyles";
import CampagneIncidentsSection from "./CampagneIncidentsSection.vue";
import ClotureDialog from "./ClotureDialog.vue";
import GenererRoutesWizard from "./GenererRoutesWizard.vue";

interface UrlsHub {
    statistiques: string;
    parametres: string;
    familles: string;
    contacts: string;
    benevoles: string;
    reception: string;
    pesee: string;
    packaging: string;
    chargement: string;
    retraitHq: string;
    suiviLivraison: string;
}

const props = defineProps<{
    campagne: Campagne;
    avancementUrl: string;
    clotureUrl: string;
    terminerUrl: string;
    rouvrirUrl: string;
    forcerIncidentsUrl: string;
    demarrerUrl: string;
    urls: UrlsHub;
    generationUrls: GenerationUrls;
    incidentsUrls: IncidentsUrls;
    villes: Ville[];
    secteurs: Secteur[];
    quartiers: Quartier[];
    organisations: Organisation[];
}>();

const toast = useToast();
const confirmDialog = useConfirm();

const statut = ref(props.campagne.statut);
const terminee = computed(() => statut.value === "terminee");
// Démarrée = « en cours » (ou terminée, que l'on peut encore consulter) :
// tant que ce n'est pas le cas, chargement/suivi/retrait QG sont grisés.
const demarree = computed(() => statut.value === "en_cours" || statut.value === "terminee");

// ── Avancement (badges des cartes) ──────────────────────────────────────
const avancement = ref<AvancementCampagne | null>(null);

async function chargerAvancement() {
    const resultat = await apiGet<AvancementCampagne>(props.avancementUrl);
    if (!resultat.ok) return;
    avancement.value = resultat.data;
    // Le statut peut changer ailleurs (démarrage depuis un autre onglet).
    if (resultat.data.statut) statut.value = resultat.data.statut;
}

let minuteur: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    void chargerAvancement();
    // Lien du rappel de l'écran Chargement (?generer=1) : ouvre l'assistant.
    if (new URLSearchParams(window.location.search).get("generer") === "1" && demarree.value && !terminee.value) {
        assistantOuvert.value = true;
    }
    minuteur = setInterval(() => {
        if (!document.hidden) void chargerAvancement();
    }, 20000);
});
onUnmounted(() => {
    if (minuteur) clearInterval(minuteur);
});

// ── Cartes ──────────────────────────────────────────────────────────────
type Ton = "ok" | "cours" | "neutre" | "alerte";

interface Carte {
    cle: string;
    emoji: string;
    titre: string;
    description: string;
    /** Lien — absent pour une carte-bouton (démarrer / générer) ou grisée. */
    href?: string;
    /** Carte-bouton : action déclenchée au clic. */
    action?: "demarrer" | "generer";
    /** Grisée : pas cliquable (campagne pas encore démarrée, ou démarrage refusé). */
    desactivee?: boolean;
    badge: string | null;
    ton: Ton;
    /** Seconde pastille à côté du statut (17/10/2026) : compteur (personnes, kg, colis, familles restantes). */
    extra?: { libelle: string; ton: Ton } | null;
    /** Carte mise en avant (bordure ambre) : il reste quelque chose à faire. */
    surbrillance?: boolean;
}

const TON_BADGE: Record<Ton, string> = {
    ok: "bg-emerald-100 text-emerald-700",
    cours: "bg-amber-100 text-amber-700",
    neutre: "bg-stone-100 text-stone-600",
    alerte: "bg-rose-100 text-rose-700",
};

const a = computed(() => avancement.value);
const incidentsOuverts = computed(() => a.value?.compteurs.incidents_ouverts ?? null);
// null = la campagne peut démarrer ; « Chargement… » tant que l'avancement n'est pas arrivé.
// (Pas de `??` : le serveur renvoie null quand tout est bon, ce qui grisait le bouton en permanence.)
const demarrageBloque = computed<string | null>(() => (a.value === null ? "Chargement…" : a.value.demarrage_bloque));

function etat(
    fait: boolean | undefined,
    libelleFait: string,
    libelleSinon: string,
): { badge: string | null; ton: Ton } {
    if (!a.value) return { badge: null, ton: "neutre" };
    return fait ? { badge: libelleFait, ton: "ok" } : { badge: libelleSinon, ton: "neutre" };
}

const sections = computed<{ titre: string; emoji: string; bordure: string; cartes: Carte[] }[]>(() => {
    const u = props.urls;
    const contacts = !a.value
        ? { badge: null, ton: "neutre" as Ton }
        : !a.value.livraisons_generees
          ? { badge: "—", ton: "neutre" as Ton }
          : a.value.contacts_termines
            ? { badge: "Terminé", ton: "ok" as Ton }
            : { badge: a.value.contacts_en_cours ? "En cours" : "À faire", ton: "cours" as Ton };
    const packaging = !a.value
        ? { badge: null, ton: "neutre" as Ton }
        : a.value.packaging_termine
          ? { badge: "Terminé", ton: "ok" as Ton }
          : { badge: a.value.compteurs.livraisons_confirmees > 0 ? "En cours" : "—", ton: "cours" as Ton };
    const suivi = !a.value
        ? { badge: null, ton: "neutre" as Ton }
        : a.value.routes_generees
          ? {
                badge: `${a.value.compteurs.routes_terminees}/${a.value.compteurs.routes_total} tournées terminées`,
                ton: (a.value.terminee ? "ok" : "cours") as Ton,
            }
          : { badge: demarree.value ? "Routes à générer" : null, ton: "neutre" as Ton };

    // Carte « Chargement » (10/10/2026) : « X/Y chargées » — « Terminé » seulement une fois TOUTES les
    // tournées confirmées chargées (et non plus dès que les colis sont prêts).
    const chargement = !a.value
        ? { badge: null, ton: "neutre" as Ton }
        : a.value.chargement_termine
          ? { badge: "Terminé", ton: "ok" as Ton }
          : a.value.compteurs.routes_total > 0
            ? {
                  badge: `${a.value.compteurs.routes_chargees}/${a.value.compteurs.routes_total} chargées`,
                  ton: (a.value.compteurs.routes_chargees > 0 ? "cours" : "neutre") as Ton,
              }
            : { badge: "En attente", ton: "neutre" as Ton };

    // Carte « Retrait QG » (09/10/2026) : « X/Y retirés » comme « X/Y tournées terminées »
    // de Suivi livraison ; rien à afficher quand aucune famille ne se déplace.
    const retrait = !a.value
        ? { badge: null, ton: "neutre" as Ton }
        : a.value.compteurs.retraits_total === 0
          ? { badge: demarree.value ? "Aucun retrait" : null, ton: "neutre" as Ton }
          : {
                badge: `${a.value.compteurs.retraits_delivres}/${a.value.compteurs.retraits_total} retirés`,
                ton: (a.value.compteurs.retraits_delivres >= a.value.compteurs.retraits_total ? "ok" : "cours") as Ton,
            };

    // Compteurs à côté du statut (17/10/2026). Affichés seulement une fois le poste commencé :
    // un « 0 » avant tout démarrage n'apprend rien.
    const c = a.value?.compteurs;
    const receptionExtra =
        a.value && c && a.value.reception_demarree
            ? {
                  libelle: `👥 ${c.personnes_receptionnees} personne${c.personnes_receptionnees > 1 ? "s" : ""}`,
                  ton: "neutre" as Ton,
              }
            : null;
    const peseeExtra =
        a.value && c && a.value.pesee_demarree
            ? { libelle: `⚖️ ${c.poids_collecte_kg.toLocaleString("fr-FR")} kg`, ton: "neutre" as Ton }
            : null;
    const packagingExtra =
        c && c.colis_total > 0
            ? {
                  libelle: `📦 ${c.colis_prets}/${c.colis_total} colis`,
                  ton: (c.colis_prets >= c.colis_total ? "ok" : "cours") as Ton,
              }
            : null;
    // Familles confirmées sans tournée : orange tant qu'il en reste, vert quand tout est placé.
    const restantes = c?.familles_sans_route ?? 0;
    const routesExtra =
        a.value && c && demarree.value && c.livraisons_confirmees > 0
            ? restantes > 0
                ? { libelle: `${restantes} famille${restantes > 1 ? "s" : ""} sans route`, ton: "alerte" as Ton }
                : { libelle: "Toutes placées", ton: "ok" as Ton }
            : null;

    return [
        {
            titre: "Avant la campagne",
            emoji: "🧭",
            bordure: "border-sky-300",
            cartes: [
                {
                    cle: "familles",
                    emoji: "🧺",
                    titre: "Sélection des familles",
                    href: u.familles,
                    description: "Choisir les familles éligibles et générer leurs livraisons.",
                    badge: a.value
                        ? a.value.livraisons_generees
                            ? `${a.value.compteurs.livraisons_total} famille(s)`
                            : "À faire"
                        : null,
                    ton: a.value?.livraisons_generees ? "ok" : "neutre",
                },
                {
                    cle: "contacts",
                    emoji: "📞",
                    titre: "Suivi des contacts",
                    href: u.contacts,
                    description: "Joindre et confirmer chaque famille.",
                    ...contacts,
                },
                {
                    cle: "benevoles",
                    emoji: "👥",
                    titre: "Suivi des bénévoles",
                    href: u.benevoles,
                    description: "Notifier les bénévoles et suivre leurs disponibilités.",
                    ...etat(a.value?.benevoles_notifies, "Notifiés", "À notifier"),
                },
            ],
        },
        {
            titre: "Préparation",
            emoji: "📦",
            bordure: "border-violet-300",
            cartes: [
                {
                    cle: "reception",
                    emoji: "🧾",
                    titre: "Réception",
                    href: u.reception,
                    description: "Enregistrer les arrivées de dons.",
                    ...etat(a.value?.reception_demarree, "Démarrée", "À démarrer"),
                    extra: receptionExtra,
                },
                {
                    cle: "pesee",
                    emoji: "⚖️",
                    titre: "Pesée",
                    href: u.pesee,
                    description: "Peser les dons collectés.",
                    ...etat(a.value?.pesee_demarree, "Démarrée", "À démarrer"),
                    extra: peseeExtra,
                },
                {
                    cle: "packaging",
                    emoji: "📦",
                    titre: "Packaging",
                    href: u.packaging,
                    description: "Préparer les colis famille par famille.",
                    ...packaging,
                    extra: packagingExtra,
                },
            ],
        },
        {
            // Section à part (17/10/2026) : « Démarrer la campagne » avant le démarrage, puis
            // « Génération des routes » — une seule carte, le même emplacement qu'avant.
            titre: demarree.value ? "Génération des routes" : "Démarrage de la campagne",
            emoji: "🗺️",
            bordure: "border-orange-300",
            cartes: [
                // Une seule des deux : « Démarrer la campagne » disparaît une
                // fois la campagne démarrée, « Génération des routes » apparaît.
                demarree.value
                    ? {
                          cle: "generer",
                          emoji: "🗺️",
                          titre: "Génération des routes",
                          action: "generer",
                          desactivee: terminee.value,
                          description: "Créer les tournées par créneau, automatiquement ou à la main.",
                          badge: a.value
                              ? a.value.routes_generees
                                  ? `${a.value.compteurs.routes_total} tournée(s)`
                                  : "À faire"
                              : null,
                          ton: a.value?.routes_generees ? "ok" : "cours",
                          extra: routesExtra,
                          surbrillance: restantes > 0 && !terminee.value,
                      }
                    : {
                          cle: "demarrer",
                          emoji: "▶️",
                          titre: "Démarrer la campagne",
                          action: "demarrer",
                          desactivee: demarrageBloque.value !== null,
                          description:
                              demarrageBloque.value ??
                              "Lance la campagne : tournées imposées et rendez-vous de retrait au QG.",
                          badge: demarrageBloque.value !== null ? null : "Prête",
                          ton: "ok",
                      },
            ],
        },
        {
            titre: "Livraison",
            emoji: "🚚",
            bordure: "border-teal-300",
            cartes: [
                {
                    cle: "chargement",
                    emoji: "🚛",
                    titre: "Chargement",
                    href: u.chargement,
                    desactivee: !demarree.value,
                    description: "Charger les véhicules, tournée par tournée.",
                    ...chargement,
                },
                {
                    cle: "suivi",
                    emoji: "👀",
                    titre: "Suivi livraison",
                    href: u.suiviLivraison,
                    desactivee: !demarree.value,
                    description: "Suivre les tournées et corriger au besoin.",
                    ...suivi,
                },
                {
                    cle: "retrait",
                    emoji: "🏠",
                    titre: "Retrait QG",
                    href: u.retraitHq,
                    desactivee: !demarree.value,
                    description: "Accueillir les familles qui se déplacent au QG.",
                    ...retrait,
                },
            ],
        },
    ];
});

// ── Démarrage + assistant de génération (06/10/2026) ────────────────────
const assistantOuvert = ref(false);
const demarrageEnCours = ref(false);

async function demarrer() {
    const confirmed = await confirmDialog.ask({
        title: "Démarrer la campagne ?",
        message:
            "Les tournées des familles imposées sont créées et les familles qui se déplacent au QG reçoivent leur rendez-vous par email. Cette action n'est faite qu'une fois.",
        confirmLabel: "Démarrer",
    });
    if (!confirmed) return;

    demarrageEnCours.value = true;
    const resultat = await apiPost<{
        success: boolean;
        routes_imposees: number;
        retraits_planifies: number;
        emails_retrait_envoyes: number;
        emails_retrait_sans_adresse: number;
        emails_retrait_en_echec: number;
    }>(props.demarrerUrl);
    demarrageEnCours.value = false;

    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    statut.value = "en_cours";
    const {
        routes_imposees,
        retraits_planifies,
        emails_retrait_envoyes,
        emails_retrait_sans_adresse,
        emails_retrait_en_echec,
    } = resultat.data;
    const bilan = `Campagne démarrée — ${routes_imposees} tournée(s) imposée(s), ${retraits_planifies} retrait(s) QG planifié(s), ${emails_retrait_envoyes} email(s) envoyé(s).`;
    // Bilan des emails de rendez-vous (10/10/2026) : un envoi raté ne doit plus passer inaperçu.
    if (emails_retrait_en_echec > 0 || emails_retrait_sans_adresse > 0) {
        const details = [
            emails_retrait_en_echec > 0 ? `${emails_retrait_en_echec} échec(s) d'envoi` : null,
            emails_retrait_sans_adresse > 0
                ? `${emails_retrait_sans_adresse} famille(s) sans email (à prévenir par téléphone)`
                : null,
        ].filter(Boolean);
        toast.warning(`${bilan} Attention : ${details.join(", ")}.`);
    } else {
        toast.success(bilan);
    }
    void chargerAvancement();
}

function clicCarte(carte: Carte) {
    if (carte.desactivee || demarrageEnCours.value) return;
    if (carte.action === "demarrer") void demarrer();
    else if (carte.action === "generer") assistantOuvert.value = true;
}

// ── Clôture ─────────────────────────────────────────────────────────────
const clotureOuverte = ref(false);

function surTerminee() {
    statut.value = "terminee";
    clotureOuverte.value = false;
    void chargerAvancement();
}

async function rouvrir() {
    const confirmed = await confirmDialog.ask({
        title: "Rouvrir la campagne ?",
        message: "La campagne redevient active et réapparaît dans les listes de campagnes en cours.",
        confirmLabel: "Rouvrir",
    });
    if (!confirmed) return;

    const resultat = await apiPost<{ success: boolean }>(props.rouvrirUrl);
    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    // Le serveur choisit : « en cours » si des tournées existent, sinon « préparation ».
    void chargerAvancement();
    toast.success("Campagne rouverte.");
}
</script>

<template>
    <div>
        <div class="flex flex-wrap items-start justify-between gap-3 mb-6">
            <div>
                <h1 class="font-heading text-xl font-semibold text-ink mb-1">
                    {{ CAMPAGNE_TYPES[campagne.type] ?? campagne.type }} — {{ formatDateFr(campagne.date_livraison) }}
                </h1>
                <p class="text-[13px] text-ink-muted">Statut : {{ CAMPAGNE_STATUT_LABELS[statut] ?? statut }}</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a
                    :href="urls.statistiques"
                    class="inline-flex items-center gap-1.5 text-[13px] font-medium px-3 py-2 rounded-lg border border-surface-border text-ink hover:bg-stone-50 no-underline"
                >
                    📊 Statistiques
                </a>
                <a
                    :href="urls.parametres"
                    class="inline-flex items-center gap-1.5 text-[13px] font-medium px-3 py-2 rounded-lg border border-surface-border text-ink hover:bg-stone-50 no-underline"
                >
                    ⚙️ Paramètres
                </a>
                <button
                    v-if="!terminee"
                    type="button"
                    @click="clotureOuverte = true"
                    class="inline-flex items-center gap-1.5 text-[13px] font-semibold px-3 py-2 rounded-lg bg-accent text-white hover:bg-accent-dark"
                >
                    ✅ Terminer la campagne
                </button>
                <button
                    v-else
                    type="button"
                    @click="rouvrir"
                    class="inline-flex items-center gap-1.5 text-[13px] font-semibold px-3 py-2 rounded-lg border border-surface-border text-ink hover:bg-stone-50"
                >
                    ↩️ Rouvrir la campagne
                </button>
            </div>
        </div>

        <div
            v-if="terminee"
            class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-[13.5px] text-emerald-800"
        >
            ✅ Campagne terminée. Vous pouvez encore consulter ses données ; « Rouvrir la campagne » la réactive.
        </div>

        <section v-for="section in sections" :key="section.titre" class="mb-7">
            <h2 class="flex items-center gap-2 text-[13px] font-semibold uppercase tracking-wide text-ink-muted mb-3">
                <span>{{ section.emoji }}</span> {{ section.titre }}
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <!-- Carte-lien, carte-bouton (démarrer / générer) ou carte grisée
                     (campagne pas encore démarrée). -->
                <component
                    :is="carte.href && !carte.desactivee ? 'a' : carte.action && !carte.desactivee ? 'button' : 'div'"
                    v-for="carte in section.cartes"
                    :key="carte.cle"
                    :href="carte.href && !carte.desactivee ? carte.href : undefined"
                    :type="carte.action && !carte.desactivee ? 'button' : undefined"
                    :aria-disabled="carte.desactivee ? 'true' : undefined"
                    :title="carte.desactivee && !carte.action ? 'Démarrez la campagne pour y accéder' : undefined"
                    @click="carte.action ? clicCarte(carte) : undefined"
                    class="group flex flex-col gap-1.5 rounded-xl border-2 p-4 text-left no-underline transition-all"
                    :class="
                        carte.desactivee
                            ? 'bg-stone-50 border-stone-200 opacity-60 cursor-not-allowed'
                            : carte.surbrillance
                              ? 'bg-amber-50 border-amber-400 hover:border-accent hover:shadow-md active:scale-[0.99] cursor-pointer'
                              : `bg-surface ${section.bordure} hover:border-accent hover:shadow-md active:scale-[0.99] cursor-pointer`
                    "
                >
                    <div class="flex items-start justify-between gap-2">
                        <span class="text-[26px] leading-none" :class="carte.desactivee ? 'grayscale' : ''">{{
                            carte.emoji
                        }}</span>
                        <!-- Statut + compteur (17/10/2026) : deux pastilles côte à côte. -->
                        <div class="flex flex-wrap justify-end gap-1">
                            <span
                                v-if="carte.badge && !carte.desactivee"
                                class="text-[11px] font-semibold px-2 py-0.5 rounded-full"
                                :class="TON_BADGE[carte.ton]"
                            >
                                {{ carte.badge }}
                            </span>
                            <span
                                v-if="carte.extra && !carte.desactivee"
                                class="text-[11px] font-semibold px-2 py-0.5 rounded-full"
                                :class="TON_BADGE[carte.extra.ton]"
                            >
                                {{ carte.extra.libelle }}
                            </span>
                        </div>
                    </div>
                    <p class="text-[15px] font-semibold text-ink">{{ carte.titre }}</p>
                    <p class="text-[12.5px] text-ink-muted">{{ carte.description }}</p>
                </component>
            </div>
        </section>

        <!-- Section Incidents repliable (06/10/2026) -->
        <CampagneIncidentsSection :urls="incidentsUrls" :ouverts="incidentsOuverts" @change="chargerAvancement" />

        <GenererRoutesWizard
            :open="assistantOuvert"
            :campagne="campagne"
            :urls="generationUrls"
            :villes="villes"
            :secteurs="secteurs"
            :quartiers="quartiers"
            :organisations="organisations"
            @close="assistantOuvert = false"
            @done="chargerAvancement"
        />

        <ClotureDialog
            :open="clotureOuverte"
            :cloture-url="clotureUrl"
            :terminer-url="terminerUrl"
            :forcer-incidents-url="forcerIncidentsUrl"
            :suivi-livraison-url="urls.suiviLivraison"
            @close="clotureOuverte = false"
            @terminee="surTerminee"
            @incidents-resolus="chargerAvancement"
        />
    </div>
</template>
