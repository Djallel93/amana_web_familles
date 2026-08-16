# AMANA — Deployment & Environment Secrets Guide

Applies to both **`amana_web_familles`** and **`amana_web_planning`**. The
mechanism is identical for both apps; where something differs (which
secrets exist, which branches are live), it's called out explicitly.

---

## 1. The three environments

| Environment | Trigger           | Workflow file                           | GitHub Environment | Purpose                                                                |
| ----------- | ----------------- | --------------------------------------- | ------------------ | ---------------------------------------------------------------------- |
| Local       | —                 | —                                       | —                  | Your machine (Pop!\_OS)                                                |
| Preprod     | push to `develop` | `.github/workflows/deploy-preprod.yaml` | `preprod`          | Test deploys on a disposable subdomain/DB before they reach real users |
| Production  | push to `main`    | `.github/workflows/deploy.yaml`         | `production`       | The live app                                                           |

Both workflows are near-identical copies of each other — same build steps,
same deploy mechanism (SFTP/SSH to IONOS) — they just differ in which
branch triggers them and which GitHub Environment they read secrets from.

**familles** currently has no `main` branch at all — it's never been
deployed to production yet, so its `deploy.yaml` is effectively dormant
until that first release.
**planning** is live: `main` is production, `develop` is active
development, and `main` is currently a bit behind `develop` (missing the
`amana/shared` migration, still merged as its own copy of tables like
`password_reset_tokens`).

---

## 2. Why GitHub Environments instead of one flat secret list

A GitHub **Environment** (Settings → Environments in each repo) is a named
bucket of secrets/vars that a job only reads from if it explicitly
declares `environment: <name>`. This lets `preprod` and `production` use
**the exact same secret names** (`DB_HOST`, `APP_KEY`, `IONOS_SSH_HOST`,
…) with **different values**, without ever touching the workflow YAML —
the environment a job runs under decides which set of values it sees.

Repository-level secrets (the old flat list, Settings → Secrets and
variables → Actions, no environment attached) still work and are visible
to _every_ job regardless of environment — they act as a fallback. That's
why adding `environment: production` to a workflow that previously had no
environment at all is a safe, zero-downtime change: any secret name not
yet defined inside the new `production` Environment just falls through to
the existing repo-level secret of the same name.

---

## 3. What needs to exist, per app

Names are auto-discovered by parsing `secrets.NAME` / `vars.NAME`
references directly out of each workflow file (see §5) — this table is a
snapshot for reference, not the source of truth. If it drifts from the
actual workflow, trust the workflow.

### amana_web_familles — 26 secrets + 4 vars

| Secrets                         |                                 |                               |                                 |
| ------------------------------- | ------------------------------- | ----------------------------- | ------------------------------- |
| `AMANA_REPOS_PAT`               | `APP_KEY`                       | `DB_COMMUN_HOST`              | `DB_COMMUN_NAME`                |
| `DB_COMMUN_PASSWORD`            | `DB_COMMUN_USERNAME`            | `DB_HOST`                     | `DB_NAME`                       |
| `DB_PASSWORD`                   | `DB_USERNAME`                   | `GOOGLE_CONTACTS_CLIENT_ID`   | `GOOGLE_CONTACTS_CLIENT_SECRET` |
| `GOOGLE_MAPS_EMBED_API_KEY`     | `GOOGLE_MAPS_GEOCODING_API_KEY` | `GOOGLE_MAPS_PLACES_API_KEY`  | `IONOS_SSH_HOST`                |
| `IONOS_SSH_PRIVATE_KEY`         | `IONOS_SSH_USER`                | `MAIL_HOST`                   | `MAIL_PASSWORD`                 |
| `MAIL_PORT`                     | `MAIL_USERNAME`                 | `MAKE_CONTACT_WEBHOOK_APIKEY` | `MAKE_CONTACT_WEBHOOK_URL`      |
| `MAKE_GEOCODING_WEBHOOK_APIKEY` | `MAKE_GEOCODING_WEBHOOK_URL`    |                               |                                 |

| Vars      |                                |                      |                     |
| --------- | ------------------------------ | -------------------- | ------------------- |
| `APP_URL` | `GOOGLE_CONTACTS_REDIRECT_URI` | `IONOS_PHP_CLI_PATH` | `IONOS_REMOTE_PATH` |

`DB_COMMUN_*` is the shared `amana_commun` database connection
(`ref_personnes`, `ref_roles`, `audit_logs`, …). `GOOGLE_*` covers Contacts
OAuth sync and Maps autocomplete/embed/geocoding. `AMANA_REPOS_PAT` is
needed here because `composer.json`/`package.json` pull `amana/shared` and
`@amana/shared-ui` from private GitHub repos (see §6).

### amana_web_planning — 15 secrets + 4 vars (on `develop`; `main` is missing `AMANA_REPOS_PAT` until the shared migration lands)

| Secrets                 |                  |                       |                    |
| ----------------------- | ---------------- | --------------------- | ------------------ |
| `APP_EMERGENCY_KEY`     | `APP_KEY`        | `CACHE_CLEAR_TOKEN`   | `DB_HOST`          |
| `DB_NAME`               | `DB_PASSWORD`    | `DB_USERNAME`         | `IONOS_SSH_HOST`   |
| `IONOS_SSH_PRIVATE_KEY` | `IONOS_SSH_USER` | `MAIL_HOST`           | `MAIL_PASSWORD`    |
| `MAIL_PORT`             | `MAIL_USERNAME`  | `MAKE_WEBHOOK_APIKEY` | `MAKE_WEBHOOK_URL` |

| Vars      |                   |                      |                     |
| --------- | ----------------- | -------------------- | ------------------- |
| `APP_URL` | `CACHE_CLEAR_URL` | `IONOS_PHP_CLI_PATH` | `IONOS_REMOTE_PATH` |

No `DB_COMMUN_*` here yet — `develop` hasn't merged the `amana/shared`
migration, so there's no `commun` database connection or shared-repo
dependency (and therefore no `AMANA_REPOS_PAT` need) on this app for now.

---

## 4. `IONOS_SSH_PRIVATE_KEY` and `AMANA_REPOS_PAT`, at a glance

- **`IONOS_SSH_PRIVATE_KEY`** — the full **private** key file content
  (`-----BEGIN OPENSSH PRIVATE KEY-----…`), not the `ssh-ed25519 AAAA…`
  public line. It's loaded into `ssh-agent` during deploy, which only ever
  holds private keys. Generate a dedicated, **passphrase-less** deploy key
  (`ssh-keygen -t ed25519 -f ./ionos_deploy_key -N ""`) rather than reusing
  a personal key that has a passphrase — there's no interactive prompt in
  CI. Add the matching `.pub` to IONOS's `authorized_keys`.

- **`AMANA_REPOS_PAT`** — a GitHub fine-grained Personal Access Token,
  **read-only**, scoped to **Contents** on just `amana_shared` and
  `amana_shared_ui`. It authenticates the anonymous `git clone` Composer/npm
  do when pulling those private repos over HTTPS during the build step.
  Generate at GitHub → Settings → Developer settings → Personal access
  tokens → Fine-grained tokens, resource owner `Djallel93`, repository
  access limited to those two repos, `Contents: Read-only`. This is a
  **repository-level** secret (same value for preprod and production), not
  environment-scoped — set it once per repo:

    ```bash
    gh secret set AMANA_REPOS_PAT --repo Djallel93/amana_web_familles
    ```

---

## 5. Creating/updating secrets & vars: `sync-github-env.sh`

Rather than clicking through the GitHub UI for 20–30 entries per
environment (and re-doing it by hand every time the app grows), a script
parses the workflow file for every `secrets.NAME` / `vars.NAME` it
references and bulk-uploads matching values from a local file via the
official `gh` CLI (which handles the required encryption itself — no
manual libsodium/API work).

### One-time setup

```bash
# Install the GitHub CLI (Pop!_OS / Ubuntu apt repo)
./install-gh-cli.sh
gh auth login   # GitHub.com → HTTPS → Login with a web browser
```

### Per environment, per app

```bash
# 1. Generate a local values file with every NAME= the workflow needs.
#    Safe to re-run any time the workflow gains new secrets/vars — only
#    appends what's missing, never touches values you've already filled in.
./sync-github-env.sh init \
  .github/workflows/deploy-preprod.yaml \
  .github/deploy/preprod.local.env

# 2. Fill in the real values in that file.

# 3. Dry run — prints exactly what would be created/updated, and flags
#    anything still empty. Sends nothing to GitHub.
./sync-github-env.sh push Djallel93/amana_web_familles \
  .github/workflows/deploy-preprod.yaml preprod \
  .github/deploy/preprod.local.env

# 4. For real — also creates the GitHub Environment itself if missing.
./sync-github-env.sh push Djallel93/amana_web_familles \
  .github/workflows/deploy-preprod.yaml preprod \
  .github/deploy/preprod.local.env --apply
```

Same two commands for `production` — just point at `deploy.yaml` and use
`environment production` instead, and for `amana_web_planning`, swap the
repo name and run from its own checkout.

**`preprod.local.env` / `production.local.env` must never be committed** —
add them to `.gitignore`. They hold real secret values on disk; the only
place those values ever leave your machine is the encrypted `gh secret
set` call.

### Behavior worth knowing

- **Re-running is how you update.** `gh secret set` / `gh variable set`
  always overwrite unconditionally — there's no "unchanged, skip" logic on
  GitHub's side. Editing a value locally and re-running `push --apply` is
  the update mechanism.
- **Leaving a value empty is treated as "don't touch it."** The script
  skips any name with no value in the local file — it never sends an empty
  value, so an existing secret on GitHub is left exactly as it was. The
  risk is the opposite case: a **brand-new** name you forgot to fill in
  never gets created at all, and GitHub Actions silently resolves an
  undefined secret to an empty string rather than failing the workflow.
  Always check the "Referenced in workflow but empty/missing" list the
  script prints before deploying with `--apply`.
- **Watch for merged lines when hand-editing the values file.** If two
  entries end up on the same line (a stray copy-paste that drops the
  newline between them), the first swallows the second's name into its own
  value and the second ends up empty — silently. The script detects this
  pattern and prints the exact line number/content before doing anything
  else; fix any lines it flags before continuing.

---

## 6. On merging the app DB and `commun` DB (preprod only)

For a disposable preprod environment, pointing `DB_COMMUN_HOST/NAME/USERNAME/PASSWORD`
at the same values as `DB_HOST/NAME/USERNAME/PASSWORD` is fine — no code
change needed, Laravel just resolves both named connections to the same
physical schema. Two things to know before doing it:

1. **Table name collisions.** `amana_shared` migrations own tables like
   `password_reset_tokens`. familles already dropped its own copy of that
   migration in favor of the shared one, so there's no clash there — but
   any future table added to the app side with a name that collides with
   something in `amana_shared` (`ref_personnes`, `audit_logs`, …) will
   fail once both live in the same schema.
2. **`migrate:fresh` drops the _entire_ schema, not just "its" tables.**
   Both the automatic first-deploy `migrate:fresh --seed` and
   `amana:migrate-shared --fresh` drop every table in whichever database
   the connection points to, regardless of which migration path is used to
   rebuild. If app and commun share a schema, running either one wipes
   both. Fine for disposable preprod data — **do not replicate this merge
   in production**, since production's `commun` DB is genuinely shared
   across both live apps, and a fresh-migrate on one app's connection would
   destroy the other app's shared reference data too.

---

## 7. Quick reference — files involved

```txt
.github/
├── workflows/
│   ├── deploy.yaml            # main → production
│   └── deploy-preprod.yaml    # develop → preprod
└── deploy/
    ├── .env.production.template   # rendered via envsubst at deploy time
    ├── .env.preprod.template
    ├── preprod.local.env          # LOCAL ONLY — gitignored, real values
    └── production.local.env       # LOCAL ONLY — gitignored, real values
```
