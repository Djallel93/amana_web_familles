#!/usr/bin/env bash
# e2e-compare.sh — compare visuel de deux états d'amana_web_familles (ex. Tailwind 3 → 4).
#
# À lancer depuis la racine du projet, arbre de travail propre :
#
#     ./e2e-compare.sh                          # sans argument : HEAD~1 (avant) et HEAD (après)
#     ./e2e-compare.sh <sha-avant> <sha-après> [baseline|compare|all]
#     ./e2e-compare.sh baseline|compare|all     # idem sans argument, une seule étape
#
# <sha-après> doit contenir les fichiers Playwright (playwright.config.ts, e2e/, database/seeders/Docker),
# c'est le cas si le patch familles y est appliqué. Ils sont copiés temporairement sur <sha-avant>.
#
# Variables : E2E_BASE_URL (défaut http://localhost:8000).
# Ne pousse rien. Ne modifie pas l'historique : tout se fait en HEAD détaché, puis retour à la branche d'origine.

set -euo pipefail

case "${1:-}" in baseline|compare|all) STAGE="$1"; shift; set -- HEAD~1 HEAD ;; esac
BEFORE="${1:-HEAD~1}"
AFTER="${2:-HEAD}"
STAGE="${STAGE:-${3:-all}}"
export E2E_BASE_URL="${E2E_BASE_URL:-http://localhost:8000}"
SEEDER='Database\Seeders\Docker\E2eRolesSeeder'
E2E_FILES=(playwright.config.ts e2e .gitignore database/seeders/Docker)

say() { printf '\n\033[1;36m== %s\033[0m\n' "$*"; }
die() { printf '\n\033[1;31mERREUR : %s\033[0m\n' "$*" >&2; exit 1; }

[ -f artisan ] && [ -f package.json ] || die "À lancer depuis la racine du projet familles."
git diff --quiet && git diff --cached --quiet || die "Des modifications non commitées existent. Commitez ou stashez, puis relancez."
B_SHA="$(git rev-parse --verify -q "$BEFORE^{commit}")" || die "Référence inconnue : $BEFORE"
A_SHA="$(git rev-parse --verify -q "$AFTER^{commit}")"  || die "Référence inconnue : $AFTER"
git cat-file -e "$A_SHA:playwright.config.ts" 2>/dev/null || die "$AFTER ne contient pas les fichiers Playwright (le patch familles est-il appliqué dans ce commit ?)."

echo "Avant : $(git log -1 --format='%h %s' "$B_SHA")"
echo "Après : $(git log -1 --format='%h %s' "$A_SHA")"

START_REF="$(git branch --show-current)"; START_REF="${START_REF:-$(git rev-parse HEAD)}"
SERVER_PID=""
cleanup() {
    [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
    git reset -q --hard 2>/dev/null || true      # annule fichiers e2e copiés / lockfiles modifiés (arbre propre au départ)
    git switch -q "$START_REF" 2>/dev/null || git switch -q --detach "$START_REF" 2>/dev/null || true
}
trap cleanup EXIT

ensure_app() {
    reachable() { curl -fsS -o /dev/null --max-time 5 "$E2E_BASE_URL/login"; }
    if ! reachable; then
        if [ "$E2E_BASE_URL" = "http://localhost:8000" ] && command -v php >/dev/null; then
            echo "Rien n'écoute sur 8000 : démarrage de « php artisan serve »…"
            php artisan serve --host=127.0.0.1 --port=8000 >/tmp/familles-serve.log 2>&1 &
            SERVER_PID=$!
            for _ in $(seq 1 30); do reachable && break; sleep 1; done
        fi
        reachable || die "L'application ne répond pas sur $E2E_BASE_URL/login.
Lancez votre serveur local (ou E2E_BASE_URL=http://votre-url) et vérifiez la base dans .env. Log : /tmp/familles-serve.log"
    fi
    composer dump-autoload -q
    php artisan db:seed --class="$SEEDER" --force
}

prepare() {   # $1 = sha, $2 = "avec-e2e" pour copier les fichiers Playwright
    git reset -q --hard
    git switch -q --detach "$1"
    [ "${2:-}" = "avec-e2e" ] && git checkout "$A_SHA" -- "${E2E_FILES[@]}"
    composer install --no-interaction || composer update amana/shared --no-interaction
    npm ci || npm install --no-save
    npm install --no-save @playwright/test @types/node
    npx playwright install chromium
    rm -f public/hot
    npm run build
    ensure_app
}

baseline() {
    say "RÉFÉRENCE : $BEFORE ($(git rev-parse --short "$B_SHA"))"
    prepare "$B_SHA" avec-e2e
    rm -rf e2e/__screenshots__ e2e/__computed__
    npx playwright test --update-snapshots
    E2E_RECORD=1 npx playwright test computed-style
    say "Référence enregistrée (dossiers e2e/__screenshots__ et e2e/__computed__, ignorés par git)."
}

compare() {
    say "COMPARAISON : $AFTER ($(git rev-parse --short "$A_SHA"))"
    [ -d e2e/__screenshots__ ] || die "Pas de référence : lancez d'abord l'étape « baseline »."
    prepare "$A_SHA"
    set +e; npx playwright test; CODE=$?; set -e
    say "Terminé (code $CODE). Rapport : npx playwright show-report e2e/report"
    echo "Triez les différences : attendues (curseur, outline, palette, halo shadow-glow) ou régressions."
}

case "$STAGE" in
    baseline) baseline ;;
    compare)  compare ;;
    all)      baseline; compare ;;
    *) die "Étape inconnue : $STAGE" ;;
esac