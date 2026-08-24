# Local development with Docker (Windows + Pop!_OS)

This gives you one identical setup — PHP 8.4, Node 22, MySQL 8, Mailpit —
on both machines, so switching between Windows and Pop!_OS mid-project is
just `git pull` + `docker compose up`.

**Scope: local dev only.** Nothing here touches how the app ships.
`deploy.yaml` (`main` → production) and `deploy-preprod.yaml` (`develop` →
preprod) still run on `ubuntu-latest`, build with `shivammathur/setup-php`
and `actions/setup-node`, and `rsync` straight to IONOS over SSH — exactly
as today. Docker never appears in `.github/workflows/`.

---

## 1. What's in this setup

| Service   | Image              | Purpose                                              | Port                     |
| --------- | ------------------ | ----------------------------------------------------- | ------------------------ |
| `app`     | custom (PHP 8.4)   | `composer install`, migrations, `php artisan serve`   | `localhost:8000`         |
| `vite`    | `node:22-alpine`   | `npm install`, Vite dev server (HMR)                  | `localhost:5173`         |
| `mysql`   | `mysql:8.4`        | two DBs: `amana_familles` (app) + `amana_commun` (shared) | `localhost:3306`     |
| `mailpit` | `axllent/mailpit`  | catches outgoing mail instead of sending it            | UI `localhost:8025`      |
| `queue`   | same image as `app`| `php artisan queue:work` — optional, `--profile worker` | —                       |

This mirrors production's driver choices (MySQL, database-backed
queue/cache/session — see `config/queue.php`, `.env.example`) rather than
the SQLite fallback, so anything that touches the `commun` connection
(`amana/shared`'s `ref_personnes`, `ref_roles`, `audit_logs`, ...) behaves
the same locally as on IONOS.

### Expected folder layout

Both `docs/local-development.md` (composer path repo, `npm link`) and this
Docker setup assume the three repos are cloned as **siblings**:

```
amana/
├── amana_web_familles/   ← this repo — docker-compose.yml lives here
├── amana_shared/
└── amana_shared_ui/
```

`docker-compose.yml` bind-mounts the whole `amana/` parent folder into
every container, so relative paths (`../amana_shared`) resolve identically
inside and outside Docker. You only strictly need `amana_web_familles/`
cloned to run the app against the tagged GitHub versions of the shared
packages — but clone all three now, since you'll want the local-linking
workflow (§6) sooner or later.

---

## 2. Install Docker — Windows

1. **Enable WSL2** (if not already). PowerShell as Administrator:
   ```powershell
   wsl --install -d Ubuntu
   ```
   Reboot when prompted, then finish the Ubuntu first-run (set a
   username/password).

2. **Install Docker Desktop** from <https://www.docker.com/products/docker-desktop/>.
   During setup, confirm **"Use the WSL 2 based engine"** is selected
   (Settings → General). Under Settings → Resources → WSL Integration,
   enable integration with your Ubuntu distro.

3. **Do all your work from the Ubuntu (WSL2) shell**, not PowerShell/CMD —
   open it via Windows Terminal → Ubuntu, or `wsl` from any terminal. This
   matters for two reasons:
   - **Performance**: bind-mounting a project that lives on the Windows
     filesystem (`/mnt/c/...`) into a Linux container is slow (every file
     read crosses the 9P/WSL bridge). Cloning inside the WSL2 filesystem
     (`~/amana/...`) avoids that entirely.
   - **Line endings**: the repos enforce LF (`.gitattributes`:
     `* text=auto eol=lf`). Git installed inside WSL2/Ubuntu defaults to LF
     correctly; Git for Windows can silently convert to CRLF depending on
     `core.autocrlf`, which then shows every line of every file as changed.

4. Inside the WSL2 Ubuntu shell, install `git` and clone there:
   ```bash
   sudo apt update && sudo apt install -y git make
   mkdir -p ~/amana && cd ~/amana
   git clone --branch develop https://github.com/Djallel93/amana_web_familles.git
   git clone https://github.com/Djallel93/amana_shared.git
   git clone https://github.com/Djallel93/amana_shared_ui.git
   ```
   (All three repos are private — cloning over HTTPS will prompt for
   GitHub auth; a browser-based `gh auth login` or a credential-manager
   PAT both work. See §4 for the *separate* token used by Composer/npm
   inside the containers.)

Docker Desktop, once running on the Windows side, is automatically
reachable as `docker`/`docker compose` from inside the WSL2 shell — no
separate Linux install needed.

---

## 3. Install Docker — Pop!_OS

Pop!_OS is Ubuntu-based, so the official Docker Engine `apt` repo works
directly (avoid the Snap-packaged `docker` some distros ship — it has
known issues with bind mounts and networking):

```bash
# Remove any old/distro docker packages first
sudo apt remove -y docker docker-engine docker.io containerd runc 2>/dev/null || true

# Add Docker's official apt repo
sudo apt update
sudo apt install -y ca-certificates curl gnupg
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin make
```

Let your user run Docker without `sudo`, then log out/in (or reboot) for
the group change to apply:

```bash
sudo usermod -aG docker $USER
```

Clone the three sibling repos, same as above:

```bash
mkdir -p ~/amana && cd ~/amana
git clone --branch develop https://github.com/Djallel93/amana_web_familles.git
git clone https://github.com/Djallel93/amana_shared.git
git clone https://github.com/Djallel93/amana_shared_ui.git
```

---

## 4. GitHub token for the private shared repos

`amana_shared` and `amana_shared_ui` are private. Composer (VCS repo) and
npm (`git+https://...` dependency in `package.json`) both need to
authenticate to clone them — same requirement as CI's `AMANA_REPOS_PAT`
(see `docs/composer-auth.md`), just supplied to Docker instead of a GitHub
Actions secret.

1. Create a **fine-grained PAT**: GitHub → Settings → Developer settings →
   Personal access tokens → Fine-grained tokens → repository access limited
   to `amana_shared` + `amana_shared_ui`, permission **Contents:
   Read-only**. (You can reuse the same one across both PCs.)

2. Put it in a `.env.docker.local` file **at the same level as your clones**
   (`~/amana/.env.docker.local`, **not** inside `amana_web_familles/` — this
   keeps it out of any repo and out of `git status` entirely):
   ```bash
   echo "AMANA_REPOS_PAT=github_pat_xxxxxxxxxxxx" > ~/amana/.env.docker.local
   ```
   `docker-compose.yml` reads `AMANA_REPOS_PAT` from your shell environment
   (`${AMANA_REPOS_PAT:-}`), so export it before running compose — easiest
   is to source the file in your shell profile, or just run:
   ```bash
   export $(cat ~/amana/.env.docker.local | xargs)
   ```
   before `docker compose up` in any new terminal session. Do this on both
   Windows (WSL2 shell) and Pop!_OS.

If you're doing the local-linking workflow (§6) exclusively and never need
Composer/npm to hit GitHub, you can skip this entirely — just leave
`AMANA_REPOS_PAT` unset and don't create `composer.local.json`/`npm link`'s
absence will simply fail with an auth error the moment something needs the
real git remote, which tells you plainly if/when you need the token.

---

## 5. First-time project setup (both OS, identical from here on)

Everything from here is run from inside `amana_web_familles/` in a WSL2
Ubuntu shell (Windows) or a native terminal (Pop!_OS) — the commands are
character-for-character identical on both.

1. **Copy the Docker files into the repo** (the ones this guide ships
   alongside — `docker-compose.yml`, `docker/`, `.env.docker.example`,
   `Makefile`, `.dockerignore`, `composer.local.json.dist`) into your
   `amana_web_familles/` checkout if they aren't already committed there.

2. **Set up `.env`:**
   ```bash
   cd ~/amana/amana_web_familles
   cp .env.docker.example .env
   ```
   This is `.env.example` with the Docker-specific overrides already
   applied (`DB_HOST=mysql`, `DB_COMMUN_HOST=mysql`, `MAIL_HOST=mailpit`,
   `APP_URL=http://localhost:8000`). Fill in the Google Maps/Contacts keys
   yourself if you need those features locally — see
   `docs/google_contacts_oauth_setup.md`; everything else works without
   them.

3. **One small `vite.config.ts` edit**, so the browser (on your host OS)
   can actually reach the Vite dev server running inside the container —
   without this, Hot Module Reload silently fails to connect:
   ```ts
   export default defineConfig({
       plugins: [
           vue(),
           laravel({
               input: ['resources/css/app.css', 'resources/js/app.ts'],
               refresh: true,
           }),
       ],
       resolve: {
           alias: { '@': '/resources/js' },
       },
       server: {
           host: '0.0.0.0',
           port: 5173,
           strictPort: true,
           hmr: { host: 'localhost' },
       },
   });
   ```
   (Only the `server: {...}` block is new — add it as a sibling to
   `resolve`.) This is dev-only config; it has no effect on `npm run build`
   or the deployed artifact.

4. **UID/GID**, so the one-time `chown` the `app` container's entrypoint
   does on `storage/`/`bootstrap/cache/` hands them back to you instead of
   root:
   ```bash
   echo "UID=$(id -u)" >> .env
   echo "GID=$(id -g)" >> .env
   ```
   (On a fresh WSL2 Ubuntu these are usually `1000`/`1000` anyway, but
   setting them explicitly costs nothing and avoids surprises. The
   container itself always runs as root — see the comment at the top of
   `docker/php/Dockerfile` for why — this step only affects ownership of
   the couple of bind-mounted folders Laravel writes into.)

5. **Build and start everything:**
   ```bash
   export $(cat ../.env.docker.local | xargs)   # loads AMANA_REPOS_PAT, see §4
   docker compose up -d --build
   ```
   First run takes a few minutes (PHP extensions compile, `composer
   install`, `npm install`, MySQL initializes). Watch progress with:
   ```bash
   docker compose logs -f app vite
   ```
   The `app` entrypoint automatically: writes `.env` if missing, runs
   `composer install`, generates `APP_KEY` if empty, waits for MySQL, runs
   `php artisan migrate`, and links `storage`. You should not need to run
   any of those by hand on first boot.

6. **Open the app:** <http://localhost:8000>. Mailpit's UI (catches every
   outgoing email instead of sending it): <http://localhost:8025>.

If you use Google Contacts sync locally, update the OAuth redirect URI in
Google Cloud Console to `http://localhost:8000/admin/google-contacts/callback`
and set `GOOGLE_CONTACTS_REDIRECT_URI` accordingly in `.env`.

---

## 6. Daily workflow

```bash
docker compose up -d          # start (no rebuild) — do this each session
docker compose logs -f app vite   # tail logs
docker compose down           # stop everything (data in mysql/db_data persists)
```

Common one-off commands, run through the running `app` container (or via
the `Makefile` shortcuts):

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan test
docker compose exec app php artisan tinker
docker compose exec app composer require some/package
docker compose exec vite npm install some-package

# Makefile shortcuts for the above:
make artisan migrate
make test
make shell        # drop into a bash shell in the app container
make npm install some-package
```

Run the queue worker (needed to actually test `SynchroniserContactGoogle`
/ `ResoudreAdresseFamille` jobs rather than relying on inline execution):

```bash
docker compose --profile worker up -d
# or: make worker
```

---

## 7. Working on `amana_shared` / `amana_shared_ui` locally

This is the Docker equivalent of `docs/local-development.md` — same
mechanism, just run inside the containers instead of on the bare host,
because that's where `vendor/` and `node_modules/` actually live (they're
named Docker volumes, not bind mounts — see §8 for why).

**Composer (`amana/shared`):**
```bash
cp composer.local.json.dist composer.local.json
docker compose exec app composer install
```
`composer.local.json` is gitignored — nothing to undo before pushing.
Edit files under `../amana_shared` on your host as normal; the symlink
Composer creates inside the container (`vendor/amana/shared`) picks up
changes immediately, no reinstall needed.

**npm (`@amana/shared-ui`):**
```bash
docker compose exec vite sh -c "cd ../amana_shared_ui && npm link"
docker compose exec vite npm link @amana/shared-ui
```
The container's entrypoint script detects this symlink on every restart
and skips `npm install` so it isn't clobbered (see
`docker/node/entrypoint.sh`) — the one gotcha `docs/local-development.md`
calls out for the non-Docker workflow doesn't apply here. To go back to
the tagged git version: remove the symlink and reinstall —
```bash
docker compose exec vite npm unlink @amana/shared-ui
docker compose exec vite npm install
```

To go back to normal Composer mode: delete `composer.local.json` and
`docker compose exec app composer install`.

---

## 8. Why `vendor/` and `node_modules/` are Docker volumes, not bind mounts

`docker-compose.yml` mounts the whole `amana/` folder into every
container, **except** `vendor/` and `node_modules/`, which are separate
named volumes layered on top. This is deliberate, not an oversight:

- **Cross-platform binary compatibility.** Some npm packages (`esbuild`,
  `vue-tsc`'s toolchain, etc.) install different native binaries per OS.
  If `node_modules/` were a plain bind mount, whichever OS ran `npm
  install` most recently would leave binaries the *other* OS's container
  can't execute — exactly the two-PC situation you're in. Keeping
  `node_modules/` in a Linux-only Docker volume means it's always built
  for the container's Linux, regardless of which host (Windows or
  Pop!_OS) triggered the install.
- **Performance**, especially on Windows: bind-mount I/O across the
  WSL2/Windows boundary is slow for the tens of thousands of small files
  in `vendor/`/`node_modules/`; a native Docker volume avoids that
  entirely.

Practical effect: `vendor/` and `node_modules/` **do not appear on your
host filesystem** — only inside the containers. Your IDE's
autocomplete/"go to definition" for Composer/npm packages won't work
against them directly. If that matters to you, either point your editor
at a remote/container-attached mode (e.g. VS Code's "Dev Containers"
extension, opening the workspace inside the `app` container), or run
`docker compose exec app composer install` a second time targeting a
bind-mounted path — not covered here since it reintroduces the
cross-platform binary problem above; only do this if you're committed to
one OS being "primary".

---

## 9. Troubleshooting

**`git config` / private repo auth fails inside a container**
`AMANA_REPOS_PAT` isn't set in your shell before `docker compose up`. Rerun
`export $(cat ../.env.docker.local | xargs)` in the terminal you're
running compose from — it doesn't persist across new terminal windows
unless you add it to your shell profile.

**Vite HMR doesn't connect / page doesn't auto-refresh**
Check the `server: {...}` block was added to `vite.config.ts` (§5 step 3).
Also confirm nothing else on your host is already using port 5173.

**"Permission denied" writing to `storage/` (mostly Pop!_OS)**
The `app` container runs as root and its entrypoint `chown`s
`storage/`/`bootstrap/cache/` back to `HOST_UID`/`HOST_GID` as its last
step (read from `.env`'s `UID`/`GID`, see §5 step 4) — confirm those are
set correctly, then restart:
```bash
docker compose restart app
```

**MySQL container is healthy but `php artisan migrate` still fails on
first boot**
The `app` container's healthcheck-based `depends_on` waits for MySQL to
accept connections, but on a very slow first boot (creating both
databases via `init.sql`) it can occasionally race. Re-run:
```bash
docker compose exec app php artisan migrate
```

**Composer/npm still fetching from GitHub instead of the local path/link**
For Composer: confirm `composer.local.json` exists (it's not the same as
`composer.local.json.dist`) and re-run `composer install`. For npm:
confirm `node_modules/@amana/shared-ui` is actually a symlink —
`docker compose exec vite ls -la node_modules/@amana | grep shared-ui`.

**Starting fresh** (nukes containers + all local data, keeps your code):
```bash
docker compose down -v
docker compose up -d --build
```

---

## 10. Files this guide adds to the repo

```
amana_web_familles/
├── docker-compose.yml
├── .dockerignore
├── .env.docker.example
├── composer.local.json.dist        (recreated — referenced by docs/local-development.md
│                                     but missing from this checkout; restore it)
├── Makefile
└── docker/
    ├── php/
    │   ├── Dockerfile
    │   └── entrypoint.sh
    ├── node/
    │   └── entrypoint.sh
    └── mysql/
        └── init.sql
```

None of this is referenced by `.github/workflows/*.yaml` — commit it
freely, it's inert as far as CI/CD and the IONOS deploy are concerned.
