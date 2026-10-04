// resources/js/components/familles/documentRowsSupport.ts
//
// Partagé par IntakeForm.vue (formulaire public) et DetailPanel.vue (fiche
// famille) pour les lignes de justificatifs (DocumentRows.vue) — 01/10/2026.

import type { DocumentRowsTone } from "./DocumentRows.vue";

/** Plafond de fichiers par section — miroir de FamilleDocument::MAX_PAR_TYPE côté PHP. */
export const MAX_DOCUMENTS = 5;

export type TypePieceIdentite = "nationalite" | "titre_sejour" | "demande_asile" | "autre";

/**
 * Une couleur par type de pièce d'identité (prompt du 01/10/2026 §4.2.1 —
 * avant ça, seules deux palettes existaient : CAF = bleu, AME = violet).
 * Classes écrites en toutes lettres (pas de construction dynamique) pour
 * rester détectables par le scanner JIT de Tailwind. `card` colore la
 * pastille radio, `dot` son repère, `tone` la zone de dépôt qui suit — la
 * même couleur confirme visuellement qu'on dépose dans la bonne branche.
 */
export const TONS_PIECE: Record<
    TypePieceIdentite,
    { card: string; dot: string; tone: DocumentRowsTone & { bg: string } }
> = {
    nationalite: {
        card: "border-emerald-400 bg-emerald-50",
        dot: "bg-emerald-500",
        tone: {
            border: "border-emerald-300",
            bg: "bg-emerald-50",
            text: "text-emerald-900",
            badge: "bg-emerald-600",
            button: "bg-emerald-600 text-white",
        },
    },
    titre_sejour: {
        card: "border-sky-400 bg-sky-50",
        dot: "bg-sky-500",
        tone: {
            border: "border-sky-300",
            bg: "bg-sky-50",
            text: "text-sky-900",
            badge: "bg-sky-600",
            button: "bg-sky-600 text-white",
        },
    },
    demande_asile: {
        card: "border-amber-400 bg-amber-50",
        dot: "bg-amber-500",
        tone: {
            border: "border-amber-300",
            bg: "bg-amber-50",
            text: "text-amber-900",
            badge: "bg-amber-600",
            button: "bg-amber-600 text-white",
        },
    },
    autre: {
        card: "border-violet-400 bg-violet-50",
        dot: "bg-violet-500",
        tone: {
            border: "border-violet-300",
            bg: "bg-violet-50",
            text: "text-violet-900",
            badge: "bg-violet-600",
            button: "bg-violet-600 text-white",
        },
    },
};

export const TON_NEUTRE: DocumentRowsTone = {
    border: "border-ink-faint",
    bg: "bg-surface-2",
    text: "text-ink",
    badge: "bg-ink-faint",
    button: "bg-accent text-white",
};

/**
 * Aperçu du nom que prendra le fichier — miroir simplifié de
 * App\Models\FamilleDocument::nomAvecLabel() (le serveur reste l'autorité :
 * il retire en plus les caractères interdits et tronque à 100 caractères).
 */
export function nomPrevisualise(label: string, nomFichier: string): string {
    const propre = label.trim();
    if (propre === "") return nomFichier;
    const point = nomFichier.lastIndexOf(".");
    const extension = point > 0 ? nomFichier.slice(point + 1).toLowerCase() : "";
    if (extension === "") return propre;
    // Extension déjà présente : normalisée en minuscules, jamais doublée.
    if (propre.toLowerCase().endsWith(`.${extension}`)) return propre.slice(0, -extension.length) + extension;
    return `${propre}.${extension}`;
}

/** Nom de fichier sans son extension — pré-remplit le libellé à l'édition. */
export function sansExtension(nomFichier: string): string {
    const point = nomFichier.lastIndexOf(".");
    return point > 0 ? nomFichier.slice(0, point) : nomFichier;
}
