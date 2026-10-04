# Dependency security

How AMANA Familles detects known vulnerabilities (CVE / GHSA) in its PHP
(Composer) and JavaScript (npm) dependencies, and what to do about them.
Same arrangement as amana_web_planning.

## 1. What watches what

| Mechanism | What it does | When | Where |
|---|---|---|---|
| Weekly audit (`.github/workflows/security.yaml`) | `composer audit --locked` + `npm audit --omit=dev --audit-level=high` (+ an informational `npm audit` on dev tooling) | Mondays 06:00 UTC, or manually | Actions tab > Security audit > Run workflow |
| Quality gate (`tests.yaml`) | Tests, static analysis, formatting, lint, types. **No audit, on purpose.** | every push | CI / deployments |

The audit is kept out of the quality gate so that a vulnerability published
tomorrow does not block the deployment of an unrelated change. A failing audit
sends a GitHub e-mail and leaves deployments untouched.

## 2. Handling a failing audit

- Read the advisory, check whether the vulnerable code path is reachable.
- Update the **single** affected package (`composer update vendor/package`,
  `npm update package`); let the quality gate validate it.
- Do not run `npm audit fix --force` or a global `composer update` just to
  silence a warning: that bundles unreviewed major upgrades into one change.
- Dev-tooling findings (the informational step) are never deployed; fix them
  when convenient.

## 3. Local commands

```bash
composer audit --locked   # PHP dependencies, from composer.lock
npm audit --omit=dev      # production JavaScript dependencies (deployed)
npm audit                 # + dev tooling (build only)
```
