import { onMounted, onUnmounted } from "vue";

/**
 * Rafraîchissement périodique « silencieux » d'un écran (01/10/2026) — même
 * garde-fous que le polling de LiveBoard.vue : pas de tick onglet masqué, pas
 * de tick tant que le précédent n'est pas terminé, rattrapage immédiat quand
 * l'onglet redevient visible.
 *
 * `rafraichir` doit être silencieux (pas de « Chargement… », pas de toast) et
 * ne pas toucher à l'état local en cours de saisie ; elle peut renvoyer
 * `false` pour signaler qu'un chargement plus récent est en cours (le tick est
 * alors simplement ignoré).
 */
export function usePolling(rafraichir: () => Promise<unknown> | unknown, intervalleMs = 15_000): void {
    let enCours = false;
    let minuterie: ReturnType<typeof setInterval> | undefined;

    async function tick(): Promise<void> {
        if (document.hidden || enCours) return;

        enCours = true;
        try {
            await rafraichir();
        } finally {
            enCours = false;
        }
    }

    function auChangementDeVisibilite(): void {
        if (!document.hidden) void tick();
    }

    onMounted(() => {
        minuterie = setInterval(tick, intervalleMs);
        document.addEventListener("visibilitychange", auChangementDeVisibilite);
    });

    onUnmounted(() => {
        if (minuterie) clearInterval(minuterie);
        document.removeEventListener("visibilitychange", auChangementDeVisibilite);
    });
}
