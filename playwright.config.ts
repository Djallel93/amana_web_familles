// playwright.config.ts
// Tests visuels / de non-régression Tailwind (14/10/2026, décisions D15, D17).
// LOCAL UNIQUEMENT : pas de job CI. Les captures de référence ne sont PAS
// commitées (e2e/__screenshots__ est ignoré par git) ; voir docs/e2e-playwright.md.

import { defineConfig, devices } from '@playwright/test';
import type { Theme } from './e2e/fixtures';

const baseURL = process.env.E2E_BASE_URL ?? 'http://localhost:8000';

const desktop = { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } };
const mobile = { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 }, isMobile: false, hasTouch: false };

const view = (name: string, device: object, theme: Theme) => ({
    name,
    testMatch: /.*\.spec\.ts/,
    dependencies: ['setup'],
    use: { ...device, theme },
});

export default defineConfig<{ theme: Theme }>({
    testDir: './e2e',
    outputDir: './e2e/test-results',
    snapshotPathTemplate: 'e2e/__screenshots__/{projectName}/{testFilePath}/{arg}{ext}',
    fullyParallel: false,
    workers: 1, // données partagées et sessions : exécution séquentielle, plus stable
    retries: 0,
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'e2e/report' }]],
    expect: {
        toHaveScreenshot: {
            threshold: 0.1, // tolérance par pixel (pixelmatch)
            maxDiffPixelRatio: 0.002, // 0,2 % de pixels différents au maximum
            animations: 'disabled',
            caret: 'hide',
        },
    },
    use: {
        baseURL,
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
        reducedMotion: 'reduce',
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        view('desktop-light', desktop, 'light'),
        view('desktop-dark', desktop, 'dark'),
        view('mobile-light', mobile, 'light'),
        view('mobile-dark', mobile, 'dark'),
    ],
});
