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
      Livraison         : chargement, suivi livraison, retrait QG, incidents.

    Ce qui a quitté cet écran : barre de progression (supprimée), sélection
    des familles (page dédiée + barre latérale), HQ/journées/équipes (page
    Paramètres), génération des routes (Suivi livraison). Statistiques,
    Paramètres et Terminer/Rouvrir sont en boutons en haut à droite. Les
    badges des cartes viennent de /avancement, rafraîchi toutes les 20 s
    (la carte Incidents change de couleur dès qu'un incident est ouvert).
-->
<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import { useConfirm, useToast } from "@amana/shared-ui";
import { apiGet, apiPost } from "../shared/api";
import { CAMPAGNE_TYPES, type AvancementCampagne, type Campagne } from "../shared/types";
import { CAMPAGNE_STATUT_LABELS, formatDateFr } from "./campagneStyles";
import ClotureDialog from "./ClotureDialog.vue";

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
    incidents: string;
}

const props = defineProps<{
    campagne: Campagne;
    avancementUrl: string;
    clotureUrl: string;
    terminerUrl: string;
    rouvrirUrl: string;
    forcerIncidentsUrl: string;
    urls: UrlsHub;
}>();

const toast = useToast();
const confirmDialog = useConfirm();

const statut = ref(props.campagne.statut);
const terminee = computed(() => statut.value === "terminee");

// ── Avancement (badges des cartes) ──────────────────────────────────────
const avancement = ref<AvancementCampagne | null>(null);

async function chargerAvancement() {
    const resultat = await apiGet<AvancementCampagne>(props.avancementUrl);
    if (resultat.ok) avancement.value = resultat.data;
}

let minuteur: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    void chargerAvancement();
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
    href: string;
    badge: string | null;
    ton: Ton;
}

const TON_BADGE: Record<Ton, string> = {
    ok: "bg-emerald-100 text-emerald-700",
    cours: "bg-amber-100 text-amber-700",
    neutre: "bg-stone-100 text-stone-600",
    alerte: "bg-rose-100 text-rose-700",
};

const a = computed(() => avancement.value);
const incidentsOuverts = computed(() => a.value?.compteurs.incidents_ouverts ?? 0);

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
          : { badge: "Routes à générer", ton: "neutre" as Ton };

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
                },
                {
                    cle: "pesee",
                    emoji: "⚖️",
                    titre: "Pesée",
                    href: u.pesee,
                    description: "Peser les dons collectés.",
                    ...etat(a.value?.pesee_demarree, "Démarrée", "À démarrer"),
                },
                {
                    cle: "packaging",
                    emoji: "📦",
                    titre: "Packaging",
                    href: u.packaging,
                    description: "Préparer les colis famille par famille.",
                    ...packaging,
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
                    description: "Charger les véhicules, tournée par tournée.",
                    ...etat(a.value?.chargement_termine, "Terminé", "En attente"),
                },
                {
                    cle: "suivi",
                    emoji: "🗺️",
                    titre: "Suivi livraison",
                    href: u.suiviLivraison,
                    description: "Générer les routes et suivre les tournées.",
                    ...suivi,
                },
                {
                    cle: "retrait",
                    emoji: "🏠",
                    titre: "Retrait QG",
                    href: u.retraitHq,
                    description: "Accueillir les familles qui se déplacent au QG.",
                    badge: null,
                    ton: "neutre",
                },
                {
                    cle: "incidents",
                    emoji: "⚠️",
                    titre: "Incidents",
                    href: u.incidents,
                    description: "Voir et traiter les incidents de tournée.",
                    badge: a.value
                        ? incidentsOuverts.value > 0
                            ? `${incidentsOuverts.value} ouvert(s)`
                            : "Aucun ouvert"
                        : null,
                    ton: incidentsOuverts.value > 0 ? "alerte" : "ok",
                },
            ],
        },
    ];
});

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
        message: "La campagne repasse en « Préparation » et réapparaît dans les listes de campagnes en cours.",
        confirmLabel: "Rouvrir",
    });
    if (!confirmed) return;

    const resultat = await apiPost<{ success: boolean }>(props.rouvrirUrl);
    if (!resultat.ok) {
        toast.error(resultat.message);
        return;
    }

    statut.value = "preparation";
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
            ✅ Campagne terminée. Vous pouvez encore consulter ses données ; « Rouvrir la campagne » la remet en
            préparation.
        </div>

        <section v-for="section in sections" :key="section.titre" class="mb-7">
            <h2 class="flex items-center gap-2 text-[13px] font-semibold uppercase tracking-wide text-ink-muted mb-3">
                <span>{{ section.emoji }}</span> {{ section.titre }}
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <a
                    v-for="carte in section.cartes"
                    :key="carte.cle"
                    :href="carte.href"
                    class="group flex flex-col gap-1.5 rounded-xl border-2 p-4 no-underline transition-all hover:shadow-md active:scale-[0.99]"
                    :class="
                        carte.cle === 'incidents' && incidentsOuverts > 0
                            ? 'bg-rose-50 border-rose-300 hover:border-rose-400'
                            : `bg-surface ${section.bordure} hover:border-accent`
                    "
                >
                    <div class="flex items-start justify-between gap-2">
                        <span class="text-[26px] leading-none">{{ carte.emoji }}</span>
                        <span
                            v-if="carte.badge"
                            class="text-[11px] font-semibold px-2 py-0.5 rounded-full"
                            :class="TON_BADGE[carte.ton]"
                        >
                            {{ carte.badge }}
                        </span>
                    </div>
                    <p class="text-[15px] font-semibold text-ink">{{ carte.titre }}</p>
                    <p class="text-[12.5px] text-ink-muted">{{ carte.description }}</p>
                </a>
            </div>
        </section>

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
