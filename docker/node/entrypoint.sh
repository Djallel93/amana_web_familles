#!/bin/sh
# docker/node/entrypoint.sh
# Reference only — not executed. The actual startup logic for the `vite`
# service lives inline in docker-compose.yml's `command:` (see the comment
# there for why). Kept here purely as a readable copy of the same script.
set -e

# node:22-alpine has no git preinstalled — Composer's own image ships it,
# npm's doesn't.
apk add --no-cache git > /dev/null

cd /var/www/amana/amana_web_familles

# Same URL-rewrite trick as the PHP container — only actually contacts
# GitHub if package.json still points at the git+https dependency (i.e.
# you have NOT run `npm link @amana/shared-ui`, see
# docs/local-development.md).
if [ -n "$AMANA_REPOS_PAT" ]; then
    git config --global url."https://x-access-token:${AMANA_REPOS_PAT}@github.com/".insteadOf "https://github.com/"
fi
git config --global --add safe.directory /var/www/amana/amana_web_familles
git config --global --add safe.directory /var/www/amana/amana_shared_ui

# Respect an existing `npm link @amana/shared-ui` (docs/local-development.md
# §npm). Re-running `npm install` would silently overwrite that symlink with
# the git-fetched version again — the exact pitfall the docs warn about.
if [ -L node_modules/@amana/shared-ui ]; then
    echo "==> @amana/shared-ui is npm-linked, skipping npm install"
    echo "    (run 'docker compose exec vite npm install' manually if other deps changed)"
else
    echo "==> npm install"
    npm install --no-audit --no-fund
fi

echo "==> starting Vite dev server on 0.0.0.0:5173"
exec npm run dev -- --host 0.0.0.0 --port 5173
