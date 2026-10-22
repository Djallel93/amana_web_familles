#!/usr/bin/env bash
# gh-claude-token.sh
# Prepares a scoped, short-lived GitHub fine-grained token for ONE repo, validates it,
# creates a dedicated work branch (so main is never touched), and prints a ready-to-paste
# AI prompt that explains how to use the token.
#
# GitHub exposes NO API to create, delete or regenerate fine-grained tokens, so those
# steps are done on github.com. The script opens the right pages (pre-filled where
# GitHub allows it), walks you through them, then takes over once you paste the token.
#
# Requirements (curl, jq, xdg-utils, a clipboard tool) are installed with apt if missing.
#
# Reruns: the script remembers (name, dates, permissions; never the secret) which token
# it prepared for a repo, and on rerun offers to REGENERATE it or DELETE & RECREATE it.

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: gh-claude-token.sh -r <repo-url> [options]

Required:
    -r, --repo URL                Repo URL (https://github.com/owner/repo[.git] or git@github.com:owner/repo.git)

Options:
    -n, --name NAME             Token name (default: claude-<repo>; letters, digits . _ - ; max 40 chars)
    -b, --branch NAME         Work branch to create (default: claude/review-YYYYMMDD)
    -f, --from REF                Branch, tag or commit SHA to start from (default: repo default branch)
    -e, --expires DAYS        Token lifetime in days, 1-30 (default: 7)
            --pr                            Also grant Pull requests: write (so a PR can be opened)
            --workflows             Also grant Workflows: write (needed to edit .github/workflows/*)
            --regenerate            On rerun: regenerate the existing token (same repo + permissions, new secret/expiry)
            --recreate                On rerun: delete the existing token, then create a fresh one
            --no-branch             Validate the token only; don't create the branch
            --no-open                 Don't try to open the browser
    -h, --help                        Show this help

Examples:
    ./gh-claude-token.sh -r https://github.com/Djallel93/my-repo --pr --workflows
    ./gh-claude-token.sh -r git@github.com:Djallel93/my-repo.git -n claude-myrepo -b claude/fixes -f a1b2c3d --regenerate
EOF
}

REPO_URL=""; BRANCH=""; BASE=""; DAYS=7; NAME=""; MODE=""
WANT_PR=0; WANT_WF=0; NO_BRANCH=0; NO_OPEN=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        -r|--repo)        REPO_URL="${2:-}"; shift 2 ;;
        -n|--name)        NAME="${2:-}"; shift 2 ;;
        -b|--branch)    BRANCH="${2:-}"; shift 2 ;;
        -f|--from)        BASE="${2:-}"; shift 2 ;;
        -e|--expires) DAYS="${2:-}"; shift 2 ;;
        --pr)                 WANT_PR=1; shift ;;
        --workflows)    WANT_WF=1; shift ;;
        --regenerate) MODE="regenerate"; shift ;;
        --recreate)     MODE="recreate"; shift ;;
        --no-branch)    NO_BRANCH=1; shift ;;
        --no-open)        NO_OPEN=1; shift ;;
        -h|--help)        usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage; exit 1 ;;
    esac
done

[[ -n "$REPO_URL" ]] || { echo "Error: --repo is required." >&2; usage; exit 1; }
[[ "$DAYS" =~ ^[0-9]+$ && "$DAYS" -ge 1 && "$DAYS" -le 30 ]] || { echo "Error: --expires must be 1-30." >&2; exit 1; }

# --- Dependencies: install whatever is missing via apt (Debian/Ubuntu/Pop!_OS) ---
ensure_deps() {
    local pkgs=() sudo_cmd=""
    command -v curl         >/dev/null || pkgs+=(curl)
    command -v jq             >/dev/null || pkgs+=(jq)
    command -v xdg-open >/dev/null || pkgs+=(xdg-utils)
    for c in base64 date mktemp; do command -v "$c" >/dev/null || { pkgs+=(coreutils); break; }; done
    command -v sed    >/dev/null || pkgs+=(sed)
    command -v grep >/dev/null || pkgs+=(grep)
    if ! command -v wl-copy >/dev/null && ! command -v xclip >/dev/null && ! command -v xsel >/dev/null; then
        if [[ "${XDG_SESSION_TYPE:-}" == "wayland" ]]; then pkgs+=(wl-clipboard); else pkgs+=(xclip); fi
    fi

    if [[ ${#pkgs[@]} -gt 0 ]]; then
        echo "Installing missing packages: ${pkgs[*]}"
        if command -v apt-get >/dev/null; then
            if [[ $EUID -ne 0 ]]; then sudo_cmd="sudo"; fi
            if ! { $sudo_cmd apt-get update -qq && $sudo_cmd apt-get install -y "${pkgs[@]}"; }; then
                echo "WARNING: apt install failed; continuing if the essentials are present." >&2
            fi
        else
            echo "WARNING: apt-get not found; install manually: ${pkgs[*]}" >&2
        fi
        echo
    fi

    for cmd in curl jq; do
        command -v "$cmd" >/dev/null || { echo "Error: '$cmd' is required and could not be installed." >&2; exit 1; }
    done
}
ensure_deps

# --- Parse owner/repo from https or ssh URL ---------------------------------
SLUG=$(printf '%s' "$REPO_URL" | sed -E 's#^(https?://github\.com/|git@github\.com:|ssh://git@github\.com/)##; s#\.git/?$##; s#/$##')
OWNER=${SLUG%%/*}
REPO=${SLUG#*/}
if [[ -z "$OWNER" || -z "$REPO" || "$OWNER" == "$SLUG" || "$REPO" == */* ]]; then
    echo "Error: could not parse owner/repo from '$REPO_URL'." >&2; exit 1
fi

[[ -n "$NAME" ]] || NAME="claude-${REPO}"
[[ "$NAME" =~ ^[A-Za-z0-9._-]{1,40}$ ]] || { echo "Error: token name must be 1-40 chars of letters, digits, '.', '_' or '-'." >&2; exit 1; }

[[ -n "$BRANCH" ]] || BRANCH="claude/review-$(date +%Y%m%d)"
case "$BRANCH" in main|master|develop|release*|production) echo "Error: refusing to use '$BRANCH' as the work branch." >&2; exit 1 ;; esac

# --- Helpers -----------------------------------------------------------------
LIST_URL="https://github.com/settings/personal-access-tokens"

PERMS_TXT="Contents: Read and write"
if [[ $WANT_PR -eq 1 ]]; then PERMS_TXT+=", Pull requests: Read and write"; fi
if [[ $WANT_WF -eq 1 ]]; then PERMS_TXT+=", Workflows: Read and write"; fi

PARAMS="name=${NAME}&description=Scoped+access+for+review+and+fixes+on+${REPO}&target_name=${OWNER}&expires_in=${DAYS}&metadata=read&contents=write"
if [[ $WANT_PR -eq 1 ]]; then PARAMS+="&pull_requests=write"; fi
if [[ $WANT_WF -eq 1 ]]; then PARAMS+="&workflows=write"; fi
TOKEN_URL="https://github.com/settings/personal-access-tokens/new?${PARAMS}"

open_url() {
    echo "    -> $1"
    if [[ $NO_OPEN -eq 0 ]] && command -v xdg-open >/dev/null; then
        xdg-open "$1" >/dev/null 2>&1 || true
    fi
}

# State: remembers which token was prepared (no secret stored)
STATE_DIR="${XDG_CONFIG_HOME:-$HOME/.config}/gh-claude-token"
STATE_FILE="${STATE_DIR}/${OWNER}__${REPO}__${NAME}.state"
state_get() { grep -m1 "^$1=" "$STATE_FILE" 2>/dev/null | cut -d= -f2- || true; }
fmt_date() { if [[ "${1:-0}" -gt 0 ]]; then date -d "@$1" '+%Y-%m-%d %H:%M'; else echo "unknown"; fi; }

echo "Repo:        ${OWNER}/${REPO}"
echo "Token:     ${NAME}"
echo "Branch:    ${BRANCH}    (from: ${BASE:-default branch})"
echo "Rights:    ${PERMS_TXT}"
echo

# --- Rerun handling ----------------------------------------------------------
if [[ -f "$STATE_FILE" ]]; then
    PREV_EXP=$(state_get expires); PREV_PR=$(state_get pr); PREV_WF=$(state_get workflows)
    echo "A token named '${NAME}' was already prepared for ${OWNER}/${REPO}."
    echo "    created: $(state_get created)     expires: $(fmt_date "${PREV_EXP:-0}")"
    echo

    if [[ -z "$MODE" ]]; then
        echo "    [r] Regenerate: same repo and permissions, new secret and new expiry (quickest)"
        echo "    [d] Delete and recreate: needed if you want different permissions"
        read -rp "Choice [r/d]: " CHOICE
        case "$CHOICE" in r|R) MODE="regenerate" ;; d|D) MODE="recreate" ;; *) echo "Aborted."; exit 1 ;; esac
    fi

    if [[ "$MODE" == "regenerate" && ( "${PREV_PR:-0}" != "$WANT_PR" || "${PREV_WF:-0}" != "$WANT_WF" ) ]]; then
        echo "Permissions differ from the existing token (regenerate keeps the old ones): switching to delete and recreate."
        MODE="recreate"
    fi
else
    MODE="create"
fi

case "$MODE" in
    create)
        echo "On the GitHub page:"
        echo "    1. Resource owner: ${OWNER}"
        echo "    2. Repository access -> 'Only select repositories' -> choose ${REPO} (and nothing else)"
        echo "    3. Check the pre-filled permissions (${PERMS_TXT}), then 'Generate token' and copy it."
        echo "         If the form isn't pre-filled, set the name, expiry and permissions by hand."
        open_url "$TOKEN_URL"
        ;;
    regenerate)
        echo "On the GitHub page:"
        echo "    1. Click the token '${NAME}'"
        echo "    2. Click 'Regenerate token', choose an expiry (${DAYS} days), then 'Generate token' and copy it."
        open_url "$LIST_URL"
        ;;
    recreate)
        echo "Step 1 - delete the old token:"
        echo "    Click '${NAME}' -> 'Delete' -> confirm."
        open_url "$LIST_URL"
        read -rp "Press Enter once it is deleted... " _
        echo
        echo "Step 2 - create the new one:"
        echo "    Resource owner: ${OWNER}; Repository access: 'Only select repositories' -> ${REPO} only."
        echo "    Check the pre-filled permissions (${PERMS_TXT}), then 'Generate token' and copy it."
        open_url "$TOKEN_URL"
        ;;
esac

echo
read -rsp "Paste the token here (hidden): " TOKEN; echo
[[ -n "$TOKEN" ]] || { echo "No token entered." >&2; exit 1; }

# --- API helper: token passed via curl config, never on the command line ----
HDRS=$(mktemp); trap 'rm -f "$HDRS"' EXIT
api() { # method path [json]
    local method=$1 path=$2 data=${3:-}
    if [[ -n "$data" ]]; then
        curl -sS -X "$method" -D "$HDRS" --config <(printf 'header = "Authorization: Bearer %s"\n' "$TOKEN") \
            -H "Accept: application/vnd.github+json" -H "X-GitHub-Api-Version: 2022-11-28" \
            -w $'\n%{http_code}' -d "$data" "https://api.github.com$path"
    else
        curl -sS -X "$method" -D "$HDRS" --config <(printf 'header = "Authorization: Bearer %s"\n' "$TOKEN") \
            -H "Accept: application/vnd.github+json" -H "X-GitHub-Api-Version: 2022-11-28" \
            -w $'\n%{http_code}' "https://api.github.com$path"
    fi
}
split() { CODE=${1##*$'\n'}; BODY=${1%$'\n'*}; }

# --- 1. Validate access ------------------------------------------------------
split "$(api GET "/repos/${OWNER}/${REPO}")"
[[ "$CODE" == "200" ]] || { echo "Error: cannot access ${OWNER}/${REPO} (HTTP $CODE). Check the repo selection." >&2; exit 1; }

PUSH=$(jq -r '.permissions.push // false' <<<"$BODY")
DEFAULT_BRANCH=$(jq -r '.default_branch' <<<"$BODY")
[[ "$PUSH" == "true" ]] || { echo "Error: token has no write access to this repo. Need Contents: Read and write." >&2; exit 1; }
echo "OK: write access to ${OWNER}/${REPO} (default branch: ${DEFAULT_BRANCH})."

# Real expiry, as reported by GitHub
EXP_RAW=$(grep -i '^github-authentication-token-expiration:' "$HDRS" | head -1 | cut -d: -f2- | tr -d '\r' | sed 's/^ *//' || true)
EXP_EPOCH=0
if [[ -n "$EXP_RAW" ]]; then EXP_EPOCH=$(date -d "$EXP_RAW" +%s 2>/dev/null || echo 0); fi
EXP_TXT="no expiry reported"
if [[ "$EXP_EPOCH" -gt 0 ]]; then EXP_TXT="$(fmt_date "$EXP_EPOCH")"; fi

# Warn if the token can see more than this one repo
split "$(api GET "/user/repos?per_page=100&affiliation=owner,collaborator,organization_member")"
if [[ "$CODE" == "200" ]]; then
    N=$(jq 'length' <<<"$BODY")
    if [[ "$N" -gt 1 ]]; then echo "WARNING: token appears to reach ${N} repos. Redo it with 'Only select repositories' = ${REPO}."; fi
fi

# --- 2. Create the work branch ----------------------------------------------
BASE_USED=${BASE:-$DEFAULT_BRANCH}
if [[ $NO_BRANCH -eq 0 ]]; then
    split "$(api GET "/repos/${OWNER}/${REPO}/commits/${BASE_USED}")"
    [[ "$CODE" == "200" ]] || { echo "Error: base ref '${BASE_USED}' not found (HTTP $CODE)." >&2; exit 1; }
    SHA=$(jq -r '.sha' <<<"$BODY")

    split "$(api GET "/repos/${OWNER}/${REPO}/git/ref/heads/${BRANCH}")"
    if [[ "$CODE" == "200" ]]; then
        echo "Branch '${BRANCH}' already exists; leaving it as is."
    else
        split "$(api POST "/repos/${OWNER}/${REPO}/git/refs" "$(jq -nc --arg r "refs/heads/${BRANCH}" --arg s "$SHA" '{ref:$r,sha:$s}')")"
        [[ "$CODE" == "201" ]] || { echo "Error: could not create branch (HTTP $CODE): $(jq -r '.message // empty' <<<"$BODY")" >&2; exit 1; }
        echo "Created '${BRANCH}' at ${SHA:0:7} (from ${BASE_USED})."
    fi
fi

# --- 3. Remember what was prepared (no secret) -------------------------------
mkdir -p "$STATE_DIR"
{
    printf 'name=%s\n' "$NAME"
    printf 'created=%s\n' "$(date '+%Y-%m-%d %H:%M')"
    printf 'expires=%s\n' "$EXP_EPOCH"
    printf 'pr=%s\n' "$WANT_PR"
    printf 'workflows=%s\n' "$WANT_WF"
} > "$STATE_FILE"

# --- 4. Build the AI prompt ---------------------------------------------------
if [[ $WANT_PR -eq 1 ]]; then
    PR_RULE="- When finished, open a pull request from ${BRANCH} into ${BASE_USED} (POST /repos/${OWNER}/${REPO}/pulls) with a clear title and description. Do not merge it."
else
    PR_RULE="- Do not open a pull request and do not merge anything: I will merge ${BRANCH} myself."
fi
if [[ $WANT_WF -eq 1 ]]; then
    WF_RULE="- The token may modify .github/workflows/*, but flag any workflow change explicitly in your summary."
else
    WF_RULE="- The token cannot push changes to .github/workflows/*. Do not modify those files; describe the needed changes in your summary instead."
fi

build_prompt() {
cat <<PROMPT_EOF
You have write access to one private GitHub repository through a fine-grained access token. Use it exactly as described below.

REPOSITORY: https://github.com/${OWNER}/${REPO}
WORK BRANCH: ${BRANCH} (already exists, created from ${BASE_USED})
GITHUB TOKEN: ${TOKEN}
TOKEN SCOPE: this repository only; ${PERMS_TXT}; expires ${EXP_TXT}.

SETUP (do this in your sandbox)
1. AUTH=\$(printf 'x-access-token:%s' '${TOKEN}' | base64 -w0)
2. git -c http.extraheader="Authorization: Basic \$AUTH" clone https://github.com/${OWNER}/${REPO}.git
3. cd ${REPO} && git config http.extraheader "Authorization: Basic \$AUTH" && git checkout ${BRANCH}
4. git config user.name "Claude" && git config user.email "noreply@anthropic.com"

RULES
- Push only to ${BRANCH}. Never push to ${BASE_USED}, main or any other branch. No force-push, no branch or tag deletion.
- Never print, log, echo, commit or write the token anywhere (files, code, commit messages, notes, memory) and never repeat it in your replies. Use it only for this repository.
${PR_RULE}
${WF_RULE}

COMMIT MESSAGES
Follow the Conventional Commits 1.0.0 specification (https://www.conventionalcommits.org/en/v1.0.0/) with one emoji right after the colon:
        <type>(<scope>): <emoji> <Description>
Example: fix(index): 🐛 Reorder list
- Types and emojis: feat ✨, fix 🐛, docs 📝, style 💄, refactor ♻️, perf ⚡, test ✅, build 📦, ci 👷, chore 🔧, revert ⏪
- The scope is the module, file or area touched. Keep it short, and omit the parentheses only if no scope applies.
- The description is imperative ("Add", "Fix", "Reorder"), starts with a capital letter, has no trailing period, and keeps the whole first line under 72 characters.
- Breaking change: add ! after the scope (feat(api)!: 💥 Remove v1 routes) and a "BREAKING CHANGE: ..." footer.
- Add a body (blank line, then the why) only when the change is not obvious. One logical change per commit.

WORKFLOW
1. Analyze first: read the structure, then give me a short summary of findings (bugs, inconsistencies, missing pieces) before making large changes.
2. Apply the corrections, then the additions. Run the tests, linters or build if the repo has them.
3. Make small, logical commits that follow the COMMIT MESSAGES convention above, and push them to ${BRANCH} regularly.
4. When done: run git config --unset http.extraheader, then summarize the commits, the changed files, how to test, and anything that needs my decision.

MY TASK:

PROMPT_EOF
}
PROMPT=$(build_prompt)

copy_clip() {
        if command -v wl-copy >/dev/null; then printf '%s' "$PROMPT" | wl-copy
        elif command -v xclip >/dev/null; then printf '%s' "$PROMPT" | xclip -selection clipboard
        elif command -v xsel >/dev/null; then printf '%s' "$PROMPT" | xsel --clipboard --input
        else return 1
        fi
}

echo
echo "================ Copy this at the start of your prompt ================"
printf '%s\n' "$PROMPT"
echo "========================================================================"
if copy_clip 2>/dev/null; then echo "(Also copied to your clipboard.)"; fi
echo
echo "The prompt contains the token: don't share it elsewhere, and revoke the token when finished:"
echo "    $LIST_URL"

unset TOKEN