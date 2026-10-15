# Visual regression tests with Playwright (local only)

Added for the Tailwind 3 → 4 migration (14/10/2026): compare *before* and
*after* with screenshots, computed styles and a per-role smoke run. **There is
no CI job** — everything runs locally against the Docker app
(see `docs/DOCKER.md`).

## 1. Prerequisites

- Node 22 (same as CI) and `npm ci` run in this repo.
- The app running with seeded data: `make up && make fresh`
  (http://localhost:8000). `make fresh` also creates one account per role
  (`e2e-<role>@amana.test`, password `e2e-password`).
- Playwright's Chromium, once: `npx playwright install chromium`.
- Internet access for Google Fonts (Sora, Plus Jakarta Sans). The *before* and
  *after* runs must be taken under the same conditions or comparisons fail.

Optional variables: `E2E_BASE_URL` (default `http://localhost:8000`),
`E2E_PASSWORD` (default `e2e-password`).

## 2. What is tested

| File | Content |
| --- | --- |
| `e2e/auth.setup.ts` | one login per role (admin, gestionnaire, membre, benevole, gestionnaire_externe); sessions saved in `e2e/.auth/` |
| `e2e/smoke.spec.ts` | every page answers < 400 with no JavaScript error; forbidden pages are refused; the "en attente" account cannot log in |
| `e2e/screenshots.spec.ts` | full-page screenshots: public pages, all admin pages, common pages as `membre` |
| `e2e/computed-style.spec.ts` | computed styles (radius, shadow, colours, outline, font, padding, transition) of ~35 selectors on 4 pages |
| `e2e/pages.ts` | page inventory and allowed roles (**confirm on the first run**) |

Four Playwright projects: `desktop-light`, `desktop-dark`, `mobile-light`,
`mobile-dark` (1280×800 and 390×844; theme via `localStorage['amana-theme']`).
Frozen clock (Friday 16/10/2026), reduced motion, animations disabled.

**Not covered yet:** pages that need a campaign / family id or membership of a
delivery team (`/familles/{id}`, `/livraison/campagnes/{campagne}/…`,
reception / weighing / packaging / loading stations). Add them to
`e2e/pages.ts` once the `LivraisonDemoSeeder` data is confirmed.

## 3. Before the migration: create the baselines (Tailwind 3 commit)

On the commit **before** the flip (no `@tailwindcss/vite`, `postcss.config.js`
still present), app running after `make fresh`:

```bash
git checkout <tailwind-3-commit>
npm ci && npx playwright install chromium
npm run test:e2e -- --update-snapshots           # reference screenshots
E2E_RECORD=1 npm run test:e2e -- computed-style  # reference computed styles
```

Baselines (`e2e/__screenshots__/`, `e2e/__computed__/`) are **git-ignored**:
keep that folder aside (or regenerate) until the migration is accepted. Specs
and config are committed.

## 4. After the migration: compare

```bash
npm ci
npm run test:e2e              # compares with the baselines
npm run test:e2e:report       # HTML report (e2e/report)
```

Thresholds (`playwright.config.ts`): 0.1 per pixel, at most 0.2 % differing
pixels. Computed styles: radius/shadow exact, colours compared as RGB ±1.
Expected differences: Tailwind 4's default palette (rose, sky, violet… and the
forms-plugin checkbox), arrow cursor on buttons (D11), `outline-hidden`
(`outline-style: none` instead of a transparent outline), `divide-y` (border on
the bottom of the previous child), `transition` now including `outline-color`,
and the `shadow-glow` fix (D13: a teal 3 px halo on focused/open shared-ui
`SearchableSelect` / `SelectMenu`). List and get each one approved.

## 5. Update baselines

After an intended change is approved:
`npm run test:e2e -- --update-snapshots <file>` (or `E2E_RECORD=1` for computed styles).

## 6. Read the report

`npm run test:e2e:report` opens the HTML report: for each failing screenshot
the *Diff*, *Actual*, *Expected* and *Side by side* tabs; traces
(`trace.zip`) are kept for failures.

## 7. Troubleshooting

- **Everything fails with 401 / redirect to /login:** rerun the setup project
  (`npm run test:e2e -- --project=setup`) and check `make fresh` (e2e accounts).
- **Flaky screenshots:** fonts not loaded yet (network), data not seeded
  identically (rerun `make fresh` before each series), remote images or
  animations; run twice before concluding.
- **Forbidden pages passing (or the reverse):** fix the `roles` in
  `e2e/pages.ts` according to `routes/*.php`.
- **Different port:** `E2E_BASE_URL=http://localhost:<port>`.
- **Chromium not found:** `npx playwright install chromium`.
