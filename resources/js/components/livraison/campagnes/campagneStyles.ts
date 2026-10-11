// resources/js/components/livraison/campagnes/campagneStyles.ts
//
// Présentation partagée entre la liste des campagnes (CampagnesIndex.vue) et
// la page de création (CampagneCreerForm.vue) — extraite de CampagnesIndex.vue
// le 03/10/2026 quand le formulaire a déménagé sur sa propre page : un type
// de campagne garde ainsi la même couleur aux deux endroits (prompt du
// 08/09/2026 §2.2.1/§2.3).

import type { SelectMenuOption } from "@amana/shared-ui";
import { CAMPAGNE_TYPES, type CampagneType } from "../shared/types";

export const CAMPAGNE_TYPE_STYLES: Record<CampagneType, { pastille: string; pastilleActive: string }> = {
    zakat_el_fitr: {
        pastille: "bg-emerald-100 text-emerald-700 border-emerald-200",
        pastilleActive: "bg-emerald-600 text-white border-emerald-600",
    },
    collecte_alimentaire: {
        pastille: "bg-amber-100 text-amber-700 border-amber-200",
        pastilleActive: "bg-amber-600 text-white border-amber-600",
    },
    don_ponctuel: {
        pastille: "bg-sky-100 text-sky-700 border-sky-200",
        pastilleActive: "bg-sky-600 text-white border-sky-600",
    },
};

/**
 * Options du menu « Type » de la page de création (SelectMenu, amana_shared_ui v1.12.0, 10/10/2026) :
 * mêmes couleurs que les pastilles ci-dessus, pour qu'un type garde sa teinte partout.
 */
export const CAMPAGNE_TYPE_OPTIONS: SelectMenuOption<CampagneType>[] = [
    { value: "zakat_el_fitr", label: CAMPAGNE_TYPES.zakat_el_fitr, icon: "🌙", tone: "emerald" },
    { value: "collecte_alimentaire", label: CAMPAGNE_TYPES.collecte_alimentaire, icon: "🧺", tone: "amber" },
    { value: "don_ponctuel", label: CAMPAGNE_TYPES.don_ponctuel, icon: "🎁", tone: "sky" },
];

/** Libellés des statuts de campagne (Campagne::STATUTS). */
export const CAMPAGNE_STATUT_LABELS: Record<string, string> = {
    preparation: "Préparation",
    collecte: "Collecte",
    en_cours: "En cours",
    terminee: "Terminée",
};

/**
 * 'YYYY-MM-DD' ou 'YYYY-MM-DDTHH:mm:ss…' → 'JJ/MM/AAAA'. On ne prend que la
 * partie calendaire pour éviter tout décalage de fuseau lié à un objet Date.
 */
export function formatDateFr(iso: string): string {
    const [annee, mois, jour] = iso.split("T")[0].split("-");
    return `${jour}/${mois}/${annee}`;
}
