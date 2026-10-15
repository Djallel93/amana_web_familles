// e2e/smoke.spec.ts
// Fumée : chaque page répond sans erreur serveur ni erreur JavaScript.

import { test, expect, settle, ROLES, authFile, PASSWORD, emailOf } from './fixtures';
import { AUTH_PAGES, PUBLIC_PAGES } from './pages';

test.describe('pages publiques', () => {
    for (const p of PUBLIC_PAGES) {
        test(p.slug, async ({ page }) => {
            const errors: string[] = [];
            page.on('pageerror', (e) => errors.push(e.message));
            const res = await page.goto(p.path);
            expect(res?.status(), p.path).toBeLessThan(400);
            await settle(page);
            expect(errors).toEqual([]);
        });
    }
});

for (const role of ROLES) {
    test.describe(`rôle ${role}`, () => {
        test.use({ storageState: authFile(role) });

        for (const p of AUTH_PAGES.filter((x) => x.roles.includes(role))) {
            test(p.slug, async ({ page }) => {
                const errors: string[] = [];
                page.on('pageerror', (e) => errors.push(e.message));
                const res = await page.goto(p.path);
                expect(res?.status(), `${role} ${p.path}`).toBeLessThan(400);
                await settle(page);
                expect(errors).toEqual([]);
            });
        }

        for (const p of AUTH_PAGES.filter((x) => !x.roles.includes(role))) {
            test(`${p.slug} interdit`, async ({ page }) => {
                const res = await page.goto(p.path);
                const refused = (res?.status() ?? 0) >= 400 || !page.url().endsWith(p.path);
                expect(refused, `${role} ne devrait pas accéder à ${p.path}`).toBe(true);
            });
        }
    });
}

test('compte « en attente » : connexion refusée', async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[name=email]', emailOf('attente'));
    await page.fill('input[name=password]', PASSWORD);
    await page.click('button[type=submit]');
    await settle(page);
    await expect(page).toHaveURL(/\/login/);
});
