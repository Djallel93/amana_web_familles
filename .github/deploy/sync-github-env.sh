#!/usr/bin/env bash
#
# sync-github-env.sh — bulk create/update GitHub Actions Environment
# secrets & variables, sourced from a local dotenv-style file, targeting
# exactly the names your workflow actually references.
#
# Rather than maintaining a manual list of "what needs to exist in
# preprod", this parses ${{ secrets.NAME }} / ${{ vars.NAME }} references
# straight out of the workflow YAML, so it stays correct as the apps grow.
#
# Requires: GitHub CLI (`gh`), authenticated (`gh auth login`), with
# admin/write access to the repo (needed to manage Environments).
#
# ── Subcommands ──────────────────────────────────────────────────────────
#
#     init <workflow-file> <values-file>
#             Scans the workflow for secrets./vars. references and appends any
#             NAME= line that's missing from <values-file> (creating it if
#             needed). Never overwrites a value you've already filled in — safe
#             to re-run every time you add a new secret/var to the workflow.
#
#     push <owner/repo> <workflow-file> <environment> <values-file> [--apply]
#             Reads <values-file>, keeps only the names actually referenced in
#             <workflow-file>, and uploads them as Environment secrets/vars via
#             `gh secret set --env-file` / `gh variable set --env-file` (gh
#             handles the libsodium encryption for secrets — nothing to do
#             yourself). Defaults to a DRY RUN that just prints what would be
#             pushed; pass --apply to actually call the GitHub API. Also
#             creates the Environment itself if it doesn't exist yet.
#
# ── Examples ─────────────────────────────────────────────────────────────
#
#     ./sync-github-env.sh init \
#             .github/workflows/deploy-preprod.yaml .github/deploy/preprod.local.env
#
#     ./sync-github-env.sh push Djallel93/amana_web_familles \
#             .github/workflows/deploy-preprod.yaml preprod \
#             .github/deploy/preprod.local.env --apply
#
# The values file is a plain KEY=VALUE dotenv file. Keep it OUT of git
# (add it to .gitignore) — it holds real secret values on your disk only,
# and only ever leaves your machine via the encrypted `gh secret set` call.
#
set -euo pipefail

usage() {
    grep '^#' "$0" | sed -e 's/^#//' -e 's/^ //'
    exit 1
}

discover_names() {
    # $1 = workflow file, $2 = "secrets" or "vars" -> prints unique NAMEs
    grep -oE "\b${2}\.[A-Za-z0-9_]+" "$1" | sed "s/${2}\.//" | sort -u
}

cmd_init() {
    local workflow="${1:?workflow file required}"
    local values_file="${2:?values file required}"
    [[ -f "$workflow" ]] || { echo "Workflow file not found: $workflow" >&2; exit 1; }
    touch "$values_file"

    mapfile -t secret_names < <(discover_names "$workflow" secrets)
    mapfile -t var_names        < <(discover_names "$workflow" vars)

    local added=0
    for name in "${secret_names[@]}" "${var_names[@]}"; do
        if ! grep -qE "^${name}=" "$values_file"; then
            echo "${name}=" >> "$values_file"
            added=$((added + 1))
        fi
    done

    echo "Discovered ${#secret_names[@]} secret(s) + ${#var_names[@]} var(s) in $workflow"
    echo "Added $added new empty entr$([ "$added" = 1 ] && echo y || echo ies) to $values_file"
    echo "→ Open $values_file and fill in the values, then run the 'push' subcommand."
}

cmd_push() {
    local repo="${1:?owner/repo required}"
    local workflow="${2:?workflow file required}"
    local environment="${3:?environment name required}"
    local values_file="${4:?values file required}"
    local apply="false"
    [[ "${5:-}" == "--apply" ]] && apply="true"

    [[ -f "$workflow" ]] || { echo "Workflow file not found: $workflow" >&2; exit 1; }
    [[ -f "$values_file" ]] || { echo "Values file not found: $values_file" >&2; exit 1; }
    command -v gh >/dev/null || { echo "gh CLI not found — https://cli.github.com" >&2; exit 1; }

    mapfile -t secret_names < <(discover_names "$workflow" secrets)
    mapfile -t var_names        < <(discover_names "$workflow" vars)

    local secret_tmp var_tmp
    secret_tmp="$(mktemp)"
    var_tmp="$(mktemp)"
    # Not using a trap here: it would fire on script EXIT, by which point
    # these locals have already gone out of scope under `set -u`. Cleaned
    # up explicitly at the end of this function instead.

    local -a missing=()
    local -a to_set_secrets=()
    local -a to_set_vars=()

    extract_value() {
        # last matching KEY= line wins, so re-runs after edits behave sanely
        grep -E "^${1}=" "$values_file" | tail -n1 | cut -d'=' -f2-
    }

    for name in "${secret_names[@]}"; do
        val="$(extract_value "$name")"
        if [[ -z "$val" ]]; then
            missing+=("secret:$name")
            continue
        fi
        echo "${name}=${val}" >> "$secret_tmp"
        to_set_secrets+=("$name")
    done

    for name in "${var_names[@]}"; do
        val="$(extract_value "$name")"
        if [[ -z "$val" ]]; then
            missing+=("var:$name")
            continue
        fi
        echo "${name}=${val}" >> "$var_tmp"
        to_set_vars+=("$name")
    done

    echo "Target: $repo [$environment]"
    echo
    echo "Secrets to set (${#to_set_secrets[@]}):"
    printf '     - %s\n' "${to_set_secrets[@]}"
    echo
    echo "Vars to set (${#to_set_vars[@]}):"
    printf '     - %s\n' "${to_set_vars[@]}"

    if [[ ${#missing[@]} -gt 0 ]]; then
        echo
        echo "⚠️    Referenced in workflow but empty/missing in $values_file (skipped):"
        printf '     - %s\n' "${missing[@]}"
    fi

    if [[ "$apply" != "true" ]]; then
        echo
        echo "(dry run — nothing was sent to GitHub. Re-run with --apply to push for real.)"
        rm -f "$secret_tmp" "$var_tmp"
        return 0
    fi

    echo
    echo "→ Ensuring Environment '$environment' exists on $repo ..."
    gh api --method PUT "repos/${repo}/environments/${environment}" --silent

    if [[ -s "$secret_tmp" ]]; then
        echo "→ Uploading $(wc -l < "$secret_tmp") secret(s) ..."
        gh secret set --repo "$repo" --env "$environment" --env-file "$secret_tmp"
    fi

    if [[ -s "$var_tmp" ]]; then
        echo "→ Uploading $(wc -l < "$var_tmp") var(s) ..."
        gh variable set --repo "$repo" --env "$environment" --env-file "$var_tmp"
    fi

    rm -f "$secret_tmp" "$var_tmp"
    echo "Done."
}

case "${1:-}" in
    init) shift; cmd_init "$@" ;;
    push) shift; cmd_push "$@" ;;
    *) usage ;;
esac