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

add_unique() {
    local candidate="$1"
    local existing

    [[ -f "$candidate" ]] || return 0
    for existing in "${SELECTED_TESTS[@]:-}"; do
        [[ "$existing" == "$candidate" ]] && return 0
    done
    SELECTED_TESTS+=("$candidate")
}

discover_targeted_tests() {
    local file base match

    SELECTED_TESTS=()

    if [[ -n "${PR_TEST_FILES:-}" ]]; then
        read -r -a requested_tests <<< "$PR_TEST_FILES"
        for file in "${requested_tests[@]}"; do
            add_unique "$file"
        done
        return
    fi

    while IFS= read -r file; do
        case "$file" in
            tests/unit/*.php|tests/database/*.php|tests/feature/*.php|tests/session/*.php)
                add_unique "$file"
                ;;
        esac
    done < <(git diff --name-only "$BASE_BRANCH"...HEAD)

    while IFS= read -r file; do
        case "$file" in
            app/*.php|app/**/*.php)
                base="$(basename "$file" .php)"
                while IFS= read -r match; do
                    add_unique "$match"
                done < <(rg -l --fixed-strings "$base" tests --glob '*.php' 2>/dev/null || true)
                ;;
        esac
    done < <(git diff --name-only "$BASE_BRANCH"...HEAD)

    if [[ "${#SELECTED_TESTS[@]}" -eq 0 ]]; then
        while IFS= read -r file; do
            case "$file" in
                app/Database/Migrations/*.php)
                    add_unique tests/database/MigrationsTest.php
                    add_unique tests/database/TableTestInventoryTest.php
                    ;;
            esac
        done < <(git diff --name-only "$BASE_BRANCH"...HEAD)
    fi
}

echo "Check scope: $CHECK_SCOPE"

if [[ "$CHECK_SCOPE" == "targeted" ]]; then
    run_check "PR script syntax" bash -n scripts/create-pr.sh
    discover_targeted_tests

    if [[ "${#SELECTED_TESTS[@]}" -gt 0 ]]; then
        echo "Targeted PHPUnit files: ${SELECTED_TESTS[*]}"
        echo "Rebuilding the local test database (mentora_test)..."
        if ! "/c/xampp/mysql/bin/mysql.exe" -u root -e "DROP DATABASE IF EXISTS mentora_test; CREATE DATABASE mentora_test;" >> "$LOG_FILE" 2>&1; then
            echo "Could not reach MySQL — is XAMPP's MySQL running?" >&2
            rm -f "$BODY_FILE"
            exit 1
        fi
        run_check "Database migrations" php spark migrate --all --env testing
        run_check "PHPUnit (targeted)" php vendor/phpunit/phpunit/phpunit -c phpunit.dist.xml --no-coverage "${SELECTED_TESTS[@]}"
    else
        skip_check "PHPUnit (targeted)" "no related PHP tests were discovered; use PR_TEST_FILES to override"
    fi

    PHP_FILES=()
    JS_FILES=()
    VITEST_FILES=()
    FRONTEND_IMPACT=0
    if [[ -n "${PR_PHPSTAN_PATHS:-}" ]]; then
        read -r -a PHP_FILES <<< "$PR_PHPSTAN_PATHS"
    fi
    while IFS= read -r file; do
        case "$file" in
            app/*.php|app/**/*.php)
                [[ -n "${PR_PHPSTAN_PATHS:-}" ]] || PHP_FILES+=("$file")
                ;;
            resources/js/*|resources/js/**/*|tests/js/*|tests/js/**/*)
                JS_FILES+=("$file")
                FRONTEND_IMPACT=1
                if [[ "$file" == resources/js/* ]]; then
                    relative="${file#resources/js/}"
                    candidate="tests/js/${relative%.*}.test.js"
                    [[ -f "$candidate" ]] && VITEST_FILES+=("$candidate")
                elif [[ "$file" == tests/js/* ]]; then
                    VITEST_FILES+=("$file")
                fi
                ;;
            package.json|package-lock.json|vite.config.*|eslint.config.*)
                FRONTEND_IMPACT=1
                ;;
        esac
    done < <(git diff --name-only "$BASE_BRANCH"...HEAD)

    if [[ "${#PHP_FILES[@]}" -gt 0 ]]; then
        run_check "PHPStan (targeted)" "$PHPSTAN" analyse --no-progress --memory-limit=1G "${PHP_FILES[@]}"
    else
        skip_check "PHPStan (targeted)" "no PHP application files changed"
    fi

    if [[ "${#JS_FILES[@]}" -gt 0 || "$FRONTEND_IMPACT" -eq 1 ]]; then
        if [[ -n "${PR_VITEST_FILES:-}" ]]; then
            read -r -a VITEST_FILES <<< "$PR_VITEST_FILES"
        fi
        if [[ "${#VITEST_FILES[@]}" -gt 0 ]]; then
            run_check "Vitest (targeted)" "$NPM" test -- --run "${VITEST_FILES[@]}"
        else
            run_check "Vitest (targeted)" "$NPM" test -- --run
        fi
        run_check "ESLint (targeted)" "$NPM" run lint
        run_check "Vite build (targeted)" "$NPM" run build
    else
        skip_check "Vitest (targeted)" "no JavaScript test or source files changed"
        skip_check "ESLint (targeted)" "no JavaScript test or source files changed"
        skip_check "Vite build (targeted)" "no JavaScript test or source files changed"
    fi
else
    echo "Rebuilding the local test database (mentora_test)..."
    if ! "/c/xampp/mysql/bin/mysql.exe" -u root -e "DROP DATABASE IF EXISTS mentora_test; CREATE DATABASE mentora_test;" >> "$LOG_FILE" 2>&1; then
        echo "Could not reach MySQL — is XAMPP's MySQL running?" >&2
        rm -f "$BODY_FILE"
        exit 1
    fi
    run_check "Database migrations" php spark migrate --all --env testing
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
