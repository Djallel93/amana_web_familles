// resources/js/components/livraison/shared/api.ts
//
// Petit client fetch typé pour les quatre écrans livraison. Reprend le
// pattern csrfToken()/meta[name="csrf-token"] déjà utilisé par
// DetailPanel.vue et ImportManualGrid.vue (pas le trick #csrf-holder de
// la version placeholder, propre aux pages Blade sans Vue) et remplace
// chaque `alert(JSON.stringify(...))` par un résultat structuré que les
// composants peuvent afficher champ par champ (voir ApiError.errors).

export function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export interface ApiError {
    ok: false;
    status: number;
    message: string;
    /** Erreurs de validation Laravel (422), clé = nom de champ. */
    errors: Record<string, string[]>;
}

export interface ApiSuccess<T> {
    ok: true;
    data: T;
}

export type ApiResult<T> = ApiSuccess<T> | ApiError;

async function traiterReponse<T>(res: Response): Promise<ApiResult<T>> {
    let corps: unknown = null;
    try {
        corps = await res.json();
    } catch {
        // Réponse vide (ex. 204 sur DELETE) — pas une erreur en soi.
    }

    if (res.ok) {
        return { ok: true, data: corps as T };
    }

    const enveloppe = (corps ?? {}) as { message?: string; errors?: Record<string, string[]> };

    return {
        ok: false,
        status: res.status,
        message: enveloppe.message ?? messageParDefaut(res.status),
        errors: enveloppe.errors ?? {},
    };
}

function messageParDefaut(status: number): string {
    if (status === 422) return 'Certains champs sont invalides.';
    if (status === 403) return "Vous n'avez pas les droits nécessaires pour cette action.";
    if (status === 404) return 'Ressource introuvable.';
    return "Une erreur est survenue. Merci de réessayer.";
}

export async function apiGet<T>(url: string): Promise<ApiResult<T>> {
    try {
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        return await traiterReponse<T>(res);
    } catch {
        return { ok: false, status: 0, message: 'Connexion impossible. Vérifiez votre réseau.', errors: {} };
    }
}

export async function apiPost<T>(url: string, body?: unknown): Promise<ApiResult<T>> {
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken(),
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify(body ?? {}),
        });
        return await traiterReponse<T>(res);
    } catch {
        return { ok: false, status: 0, message: 'Connexion impossible. Vérifiez votre réseau.', errors: {} };
    }
}

export async function apiDelete<T>(url: string): Promise<ApiResult<T>> {
    try {
        const res = await fetch(url, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
        });
        return await traiterReponse<T>(res);
    } catch {
        return { ok: false, status: 0, message: 'Connexion impossible. Vérifiez votre réseau.', errors: {} };
    }
}

/** Construit une query string en omettant les valeurs vides/nulles. */
export function buildQuery(params: Record<string, string | number | boolean | null | undefined>): string {
    const usp = new URLSearchParams();
    for (const [cle, valeur] of Object.entries(params)) {
        if (valeur === null || valeur === undefined || valeur === '') continue;
        usp.set(cle, String(valeur));
    }
    const chaine = usp.toString();
    return chaine ? `?${chaine}` : '';
}
