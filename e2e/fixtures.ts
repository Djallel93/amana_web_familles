// e2e/fixtures.ts
// Fixtures communes : thème (clair/sombre), session par rôle, horloge figée.

import { test as base, expect, type Page } from '@playwright/test';

export type Theme = 'light' | 'dark';
export type Role = 'admin' | 'gestionnaire' | 'membre' | 'benevole' | 'gestionnaire_externe';

export const ROLES: Role[] = ['admin', 'gestionnaire', 'membre', 'benevole', 'gestionnaire_externe'];
export const PASSWORD = process.env.E2E_PASSWORD ?? 'e2e-password';
export const authFile = (role: Role) => `e2e/.auth/${role}.json`;
export const emailOf = (role: string) => `e2e-${role}@amana.test`;

// Date figée (un vendredi) : listes, campagnes et statistiques dépendent de « aujourd'hui ».
const FROZEN_NOW = new Date('2026-10-16T09:00:00+02:00').valueOf();

export const test = base.extend<{ theme: Theme }>({
    theme: ['light', { option: true }],

    page: async ({ page, theme }, use) => {
        // Même mécanisme que @amana/shared-ui (lib/theme.ts) : clé « amana-theme ».
        await page.addInitScript((t) => {
            try {
                localStorage.setItem('amana-theme', t);
            } catch {
                /* stockage indisponible */
            }
        }, theme);
        await page.clock.install({ time: FROZEN_NOW });
        await use(page);
    },
});

export { expect };

/** Attend polices, images et fin des requêtes avant une capture ou une mesure. */
export async function settle(page: Page): Promise<void> {
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => document.fonts.ready);
}
