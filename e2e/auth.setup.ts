// e2e/auth.setup.ts
// Une connexion par rôle, session enregistrée dans e2e/.auth/<rôle>.json (ignoré par git).
// Comptes créés par `make fresh` (database/seeders/Docker/E2eRolesSeeder.php).

import { test as setup, expect } from '@playwright/test';
import { ROLES, PASSWORD, authFile, emailOf } from './fixtures';

for (const role of ROLES) {
    setup(`connexion ${role}`, async ({ page }) => {
        await page.goto('/login');
        await page.fill('input[name=email]', emailOf(role));
        await page.fill('input[name=password]', PASSWORD);
        await page.click('button[type=submit]');
        await page.waitForURL((url) => !url.pathname.startsWith('/login'));
        await expect(page).not.toHaveURL(/\/login/);
        await page.context().storageState({ path: authFile(role) });
    });
}
