// e2e/screenshots.spec.ts
// Captures pleine page : clair/sombre × 390/1280 px (un projet Playwright par combinaison).
// Références : `npm run test:e2e -- --update-snapshots` SUR LE COMMIT TAILWIND 3.
// Comparaison : `npm run test:e2e` après la migration. Seuil : voir playwright.config.ts.
// Rôle « admin » pour toutes les pages ; « membre » en plus pour les pages communes.

import { test, expect, settle, authFile } from './fixtures';
import { AUTH_PAGES, PUBLIC_PAGES } from './pages';

test.describe('captures — pages publiques', () => {
    for (const p of PUBLIC_PAGES) {
        test(p.slug, async ({ page }) => {
            await page.goto(p.path);
            await settle(page);
            await expect(page).toHaveScreenshot(`public-${p.slug}.png`, { fullPage: true });
        });
    }
});

for (const role of ['admin', 'membre'] as const) {
    test.describe(`captures — ${role}`, () => {
        test.use({ storageState: authFile(role) });

        for (const p of AUTH_PAGES.filter((x) => x.roles.includes(role))) {
            test(p.slug, async ({ page }) => {
                await page.goto(p.path);
                await settle(page);
                await expect(page).toHaveScreenshot(`${role}-${p.slug}.png`, { fullPage: true });
            });
        }
    });
}
