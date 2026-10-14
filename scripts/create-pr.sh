#!/usr/bin/env bash
# Runs the project's local checks, then opens a PR with a body built from
# the real results — never created if a check actually failed.
#
# Usage:
#   scripts/create-pr.sh <base-branch> "<title>" ["<what-and-why>" [full|targeted]]
#
# Examples:
#   scripts/create-pr.sh main "feat: add role switching"
#   scripts/create-pr.sh main "feat: add role switching" \
#       "Adds role switching for users who hold multiple roles."
#   scripts/create-pr.sh feature/user-roles-schema "feat: ..."   # stacked PR
#   PR_CHECK_SCOPE=targeted scripts/create-pr.sh main "feat: ..."  # fast local checks
set -uo pipefail

# Windows/Git Bash can't exec these directly without the real shim extension.
NPM="/c/Program Files/nodejs/npm.cmd"
PHPSTAN="vendor/bin/phpstan.bat"
GH="/c/Program Files/GitHub CLI/gh.exe"
command -v gh >/dev/null 2>&1 && GH="gh"

BASE_BRANCH="${1:-main}"
PR_TITLE="${2:?Usage: scripts/create-pr.sh <base-branch> \"<title>\" [\"<what-and-why>\"]}"
SUMMARY="${3:-}"
CHECK_SCOPE="${4:-${PR_CHECK_SCOPE:-full}}"
HEAD_BRANCH="$(git branch --show-current)"

if [[ -z "$HEAD_BRANCH" ]]; then
    echo "You are not on a Git branch." >&2
    exit 1
fi

if [[ "$CHECK_SCOPE" != "full" && "$CHECK_SCOPE" != "targeted" ]]; then
    echo "Check scope must be 'full' or 'targeted'." >&2
    exit 1
fi

RESULTS=()
FAILURES=()
LOG_FILE="$(mktemp)"
BODY_FILE="$(mktemp)"

run_check() {
    local name="$1"
    shift
    local status

    echo "Running: $name"
    echo "Command: $*"
    echo "=== $name ===" >> "$LOG_FILE"
    "$@" 2>&1 | tee -a "$LOG_FILE"
    status=${PIPESTATUS[0]}
    echo "=== $name (exit $status) ===" >> "$LOG_FILE"
    echo

    if [[ $status -eq 0 ]]; then
        RESULTS+=("- \`$name\` — passed")
    else
        RESULTS+=("- \`$name\` — failed")
        FAILURES+=("$name")
    fi
}

skip_check() {
    local name="$1"
    local reason="$2"

    echo "Skipping: $name ($reason)"
    RESULTS+=("- \`$name\` — skipped ($reason)")
}

# A schema left over from a different branch's migrations is a false
# failure (or worse, a false pass) waiting to happen — rebuild it fresh
# every run rather than trust whatever migrateOnce cached last time.
# If MySQL isn't even up, every DB test would otherwise sit there
# retrying/timing out one by one instead of failing in seconds.
echo "Rebuilding the local test database (mentora_test)..."
if ! "/c/xampp/mysql/bin/mysql.exe" -u root -e "DROP DATABASE IF EXISTS mentora_test; CREATE DATABASE mentora_test;" >> "$LOG_FILE" 2>&1; then
    echo "Could not reach MySQL — is XAMPP's MySQL running? See $LOG_FILE for the exact error." >&2
    rm -f "$BODY_FILE"
    exit 1
fi

run_check "Database migrations" php spark migrate --all --env testing

# --no-coverage: no Xdebug/PCOV locally, so attempting coverage just
# produces a "no code coverage driver available" warning — and
# failOnWarning="true" in phpunit.dist.xml turns that into a false failure.
echo "Check scope: $CHECK_SCOPE"
if [[ "$CHECK_SCOPE" == "targeted" ]]; then
    run_check "PHPUnit (targeted)" php vendor/phpunit/phpunit/phpunit -c phpunit.dist.xml --no-coverage \
        tests/database/DeanAssignmentServiceTest.php tests/database/DeanAccessServiceTest.php
    run_check "PHPStan (targeted)" "$PHPSTAN" analyse --no-progress --memory-limit=1G \
        app/Config/AuthGroups.php app/Models/Assignments/DeanAssignmentModel.php \
        app/Services/Courses/DeanAccessService.php app/Services/Institutions/DeanAssignmentService.php
    run_check "Vitest (targeted)" "$NPM" test -- --run
    if git diff --name-only "$BASE_BRANCH"...HEAD | grep -Eq '\.(js|jsx|ts|tsx)$'; then
        run_check "ESLint (targeted)" "$NPM" run lint
    else
        skip_check "ESLint (targeted)" "no JavaScript or TypeScript files changed"
    fi
else
    run_check "PHPUnit" php vendor/phpunit/phpunit/phpunit -c phpunit.dist.xml --no-coverage
    run_check "PHPStan" "$PHPSTAN" analyse --no-progress --memory-limit=1G
    run_check "Vitest" "$NPM" test
    run_check "ESLint" "$NPM" run lint
    run_check "Vite build" "$NPM" run build
fi

{
    echo "## What and why"
    echo
    if [[ -n "$SUMMARY" ]]; then
        echo "$SUMMARY"
    else
        echo "_No summary provided — edit this section before merging._"
    fi
    echo
    echo "## How to check it"
    echo
    echo "The following checks were run locally before opening this PR:"
    echo
    echo "Check scope: \`$CHECK_SCOPE\`"
    echo
    printf '%s\n' "${RESULTS[@]}"
    echo
    echo "## Checklist"
    echo
    # Only auto-check what this script actually verified — everything
    # above. CI's own result isn't known yet at this point, so it stays
    # unchecked rather than assumed; items that need a human to
    # actually judge (secrets, a real diff read) aren't here at all,
    # since a box this script always checks regardless of truth is
    # worse than no box.
    echo "- [ ] CI is green (the \"CI passed\" check below)"
} > "$BODY_FILE"

if [[ ${#FAILURES[@]} -gt 0 ]]; then
    {
        echo
        echo "## Failed checks"
        echo
        printf -- '- %s\n' "${FAILURES[@]}"
        echo
        echo "The PR was not created because one or more local checks failed."
    } >> "$BODY_FILE"

    echo
    echo "Local checks failed: ${FAILURES[*]}"
    echo "Full output: $LOG_FILE"
    echo
    echo "PR body preview:"
    cat "$BODY_FILE"

    rm -f "$BODY_FILE"
    exit 1
fi

git push -u origin "$HEAD_BRANCH"

"$GH" pr create \
    --base "$BASE_BRANCH" \
    --head "$HEAD_BRANCH" \
    --title "$PR_TITLE" \
    --body-file "$BODY_FILE"

echo
echo "Full check logs: $LOG_FILE"
rm -f "$BODY_FILE"
