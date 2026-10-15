// e2e/computed-style.spec.ts
// Styles calculés d'éléments représentatifs (plan de régression §7.4).
//
// Mode « référence » (sur le commit Tailwind 3) :  E2E_RECORD=1 npm run test:e2e -- computed-style
//   → écrit e2e/__computed__/<projet>.json (ignoré par git)
// Mode « comparaison » (après la migration)      :  npm run test:e2e -- computed-style
//   → rayon et ombre exacts ; couleurs comparées en RVB à ±1 (v4 peut sérialiser en oklab/color-mix).

import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { test, expect, settle, authFile } from './fixtures';

const SELECTORS = [
    'button[type=submit]', 'input[type=email]', 'input[type=password]', 'input[type=checkbox]',
    'select', 'textarea', 'aside', '#mainSidebar', '.btn-touch', '.hamburger-bar',
    'nav a', 'nav a.active', 'table', 'table th', 'table td', 'h1', 'h2',
    '.filter-chip', '.filter-chip.active',
    '.rounded', '.rounded-lg', '.rounded-xl', '.shadow', '.shadow-sm', '.shadow-lg',
    '.bg-accent', '.bg-surface', '.border', '.border-b', '.divide-y > *',
];
const PROPS = [
    'border-radius', 'box-shadow', 'border-top-color', 'border-top-width', 'color', 'background-color',
    'outline-style', 'outline-width', 'font-family', 'padding', 'transition-property',
];
const PAGES = ['/login', '/', '/admin/personnes', '/settings'];

type Snapshot = Record<string, Record<string, string>>;

const rgb = (v: string) => (v.match(/[\d.]+/g) ?? []).slice(0, 4).map(Number);
function same(prop: string, a: string, b: string): boolean {
    if (a === b) return true;
    if (/color$/.test(prop) || prop === 'background-color') {
        const x = rgb(a), y = rgb(b);
        return x.length >= 3 && y.length >= 3 && x.slice(0, 3).every((n, i) => Math.abs(n - y[i]) <= 1);
    }
    return false;
}

test.use({ storageState: authFile('admin') });

test('styles calculés', async ({ page }, info) => {
    const snap: Snapshot = {};
    for (const path of PAGES) {
        await page.goto(path);
        await settle(page);
        const data = await page.evaluate(({ sels, props }) => {
            const out: Record<string, Record<string, string>> = {};
            for (const s of sels) {
                const el = document.querySelector(s);
                if (!el) continue;
                const cs = getComputedStyle(el);
                out[s] = Object.fromEntries(props.map((p) => [p, cs.getPropertyValue(p)]));
            }
            return out;
        }, { sels: SELECTORS, props: PROPS });
        for (const [s, v] of Object.entries(data)) snap[`${path} ${s}`] = v;
    }

    const file = `e2e/__computed__/${info.project.name}.json`;
    if (process.env.E2E_RECORD) {
        mkdirSync('e2e/__computed__', { recursive: true });
        writeFileSync(file, JSON.stringify(snap, null, 1));
        expect(Object.keys(snap).length).toBeGreaterThan(10);
        return;
    }
    test.skip(!existsSync(file), `Pas de référence ${file} — lancer d'abord avec E2E_RECORD=1 sur Tailwind 3.`);
    const ref: Snapshot = JSON.parse(readFileSync(file, 'utf8'));
    const diffs: string[] = [];
    for (const [key, props] of Object.entries(ref)) {
        for (const [p, v] of Object.entries(props)) {
            const now = snap[key]?.[p];
            if (now === undefined) diffs.push(`${key} : élément absent`);
            else if (!same(p, v, now)) diffs.push(`${key} ${p} : ${v} → ${now}`);
        }
    }
    expect(diffs, diffs.slice(0, 40).join('\n')).toEqual([]);
});
