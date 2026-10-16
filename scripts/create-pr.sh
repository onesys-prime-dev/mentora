#!/usr/bin/env bash
# Pushes the current branch and opens a pull request into <base-branch>, with the
# repository's template filled in from the branch itself. It runs no checks: the
# tests, PHPStan and the rest run in CI on the pull request, and "CI passed" is the
# one result that gates the merge.
#
# Usage:
#   bash scripts/create-pr.sh <base-branch> "<title>" ["<what-and-why>"]
#
# Examples:
#   bash scripts/create-pr.sh main "feat: add role switching"
#   bash scripts/create-pr.sh main "feat: add role switching" \
#       "Adds role switching for users who hold multiple roles."
#   bash scripts/create-pr.sh feature/user-roles-schema "feat: ..."   # stacked PR
set -uo pipefail

GH="/c/Program Files/GitHub CLI/gh.exe"
command -v gh >/dev/null 2>&1 && GH="gh"

BASE_BRANCH="${1:-main}"
PR_TITLE="${2:?Usage: scripts/create-pr.sh <base-branch> \"<title>\" [\"<what-and-why>\"]}"
SUMMARY="${3:-}"
HEAD_BRANCH="$(git branch --show-current)"

if [[ -z "$HEAD_BRANCH" ]]; then
    echo "You are not on a Git branch." >&2
    exit 1
fi

if [[ "$HEAD_BRANCH" == "$BASE_BRANCH" ]]; then
    echo "You are on $BASE_BRANCH. Switch to the branch you want a pull request for." >&2
    exit 1
fi

if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
    echo "Note: there are uncommitted changes. They are not part of the pull request."
fi

# A branch gets one pull request: if there is already one, show it instead of failing.
EXISTING="$("$GH" pr view "$HEAD_BRANCH" --json url,state --jq 'select(.state == "OPEN") | .url' 2>/dev/null || true)"
if [[ -n "$EXISTING" ]]; then
    echo "A pull request is already open for $HEAD_BRANCH:"
    echo "$EXISTING"
    exit 0
fi

# Compare with the remote base when there is one, so a stale local base does not
# list other people's commits.
RANGE_BASE="$BASE_BRANCH"
git rev-parse --verify --quiet "origin/$BASE_BRANCH" >/dev/null && RANGE_BASE="origin/$BASE_BRANCH"

if [[ -z "$(git log "$RANGE_BASE..HEAD" --format=%h 2>/dev/null)" ]]; then
    echo "$HEAD_BRANCH has no commits that $BASE_BRANCH does not. There is nothing to open a pull request for." >&2
    exit 1
fi

BODY_FILE="$(mktemp)"
trap 'rm -f "$BODY_FILE"' EXIT

{
    echo "## What and why"
    echo
    if [[ -n "$SUMMARY" ]]; then
        echo "$SUMMARY"
    else
        echo "_No summary provided. Edit this section before merging._"
    fi
    echo
    echo "## How to check it"
    echo
    echo "No checks were run before opening this pull request. CI runs the full suite on it;"
    echo "wait for the \"CI passed\" check below."
    echo
    echo "Commits:"
    echo
    git log "$RANGE_BASE..HEAD" --reverse --format='- %s'
    echo
    echo "Changed: $(git diff --shortstat "$RANGE_BASE...HEAD")"
    echo
    echo "## Checklist"
    echo
    echo '- [ ] CI is green (the "CI passed" check below)'
    echo '- [ ] Tests are included, or the reason there are none is given here: <!-- reason -->'
    echo '- [ ] If this fixes a bug, I added a test that fails without the fix'
    echo '- [ ] If this changes the database, there is a migration and `php spark migrate --all` works on an empty database'
    echo '- [ ] No secrets, `.env` values or personal data are in the diff'
    echo '- [ ] I looked through the diff on this page once before asking for a merge'
} > "$BODY_FILE"

git push -u origin "$HEAD_BRANCH" || exit 1

"$GH" pr create \
    --base "$BASE_BRANCH" \
    --head "$HEAD_BRANCH" \
    --title "$PR_TITLE" \
    --body-file "$BODY_FILE"
