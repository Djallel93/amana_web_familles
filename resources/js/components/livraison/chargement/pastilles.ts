// resources/js/components/livraison/chargement/pastilles.ts
//
// Pastilles d'une famille (octobre 2026) : « émoji + nombre (quand il y en a un) + libellé »,
// au singulier / pluriel — « 👶 3 enfants », « 📦 1 colis », « 🎓 Étudiant ». Une seule règle
// pour tous les écrans chargement.

export interface PastilleFamille {
    cle: string;
    texte: string;
    style: string;
}

export interface EntreePastilles {
    etudiant: boolean;
    estHotel: boolean;
    nombreEnfant: number;
    colis: number;
}

/** « 1 enfant », « 3 enfants » — pluriel dès 2. */
export function libelleNombre(nombre: number, singulier: string, pluriel: string): string {
    return `${nombre} ${nombre > 1 ? pluriel : singulier}`;
}

export function pastillesFamille(entree: EntreePastilles): PastilleFamille[] {
    const pastilles: PastilleFamille[] = [];

    if (entree.etudiant) {
        pastilles.push({ cle: "etudiant", texte: "🎓 Étudiant", style: "bg-sky-50 text-sky-700" });
    }
    if (entree.estHotel) {
        pastilles.push({ cle: "hotel", texte: "🏨 Hôtel", style: "bg-amber-50 text-amber-700" });
    }
    if (entree.nombreEnfant > 0) {
        pastilles.push({
            cle: "enfants",
            texte: `👶 ${libelleNombre(entree.nombreEnfant, "enfant", "enfants")}`,
            style: "bg-violet-50 text-violet-700",
        });
    }
    if (entree.colis > 0) {
        pastilles.push({
            cle: "colis",
            texte: `📦 ${libelleNombre(entree.colis, "colis", "colis")}`,
            style: "bg-stone-100 text-stone-700",
        });
    }

    return pastilles;
}
