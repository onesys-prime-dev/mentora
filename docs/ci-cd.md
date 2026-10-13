# CI/CD overview

What runs automatically, and what it protects. See `CONTRIBUTING.md` for the
day-to-day workflow.

## Pipelines

| Workflow | File | Runs when | Purpose |
|---|---|---|---|
| CI | `.github/workflows/ci.yml` | PR into `main`; also called by Deploy | Prove the change is sound |
| Deploy (staging) | `.github/workflows/deploy.yml` | push to `main`, or a manual run | Run CI first (`needs: ci`); only if it passes, call `deploy-reusable.yml` for the `staging` environment: build, back up the database, copy the app, migrate, health-check, smoke-test (see `deploy.md`) |
| Deploy (production) | `.github/workflows/deploy-production.yml` | a `v*` tag, or a manual run from a tag | Check the tag is `vX.Y.Z` and on `main`, re-run CI, wait for the `production` environment's approval, then the same reusable deploy (see `github-environments.md`) |
| Deploy steps | `.github/workflows/deploy-reusable.yml` | called by the two above | The shared steps; runs inside the named GitHub Environment so it uses that environment's secrets and variables |
| Uptime check | `.github/workflows/uptime.yml` | hourly, or a manual run | Smoke-test staging; open an issue assigned to the alert owner when it is down, close it on recovery (see `uptime.md`, including what it costs in Actions minutes) |

## CI jobs (all must pass)

| Job | Check | Why |
|---|---|---|
| PHP syntax (8.2, 8.5) | `php -l` on every file in `app/`, `tests/`, `public/` and `spark` | 8.2 is the minimum in `composer.json` and the local version; 8.5 is the version the staging site runs |
| Composer | `composer validate --strict`, `composer install`, `composer audit` | The manifest and lock agree, dependencies install, no known vulnerable packages |
| Static analysis (PHPStan) | `phpstan analyse` at level 5 over `app/` on PHP 8.2. Problems that existed when it was introduced are listed in `phpstan-baseline.neon`; any other fails | Catches undefined methods, wrong argument types and dead conditions before they reach a user, without blocking on old code |
| Tests on MySQL (8.2, 8.5) | A MySQL 8.4 container, `php spark migrate --all` on the empty database (then checked: the history table must hold a row for every App migration), then PHPUnit. On 8.2 the build also fails if line coverage drops below `.github/coverage-floor`, and on a pull request if less than `.github/diff-coverage-threshold` percent of the executable lines the PR adds or changes under `app/` are covered (`.github/scripts/check-diff-coverage.php`; the uncovered lines are listed in the job log and summary) | Migrations work on a fresh install, the test suite passes on the minimum and the production PHP, and coverage can only go up |
| Frontend build and audit | `npm ci`, `npm audit --omit=dev --audit-level=high`, ESLint (`npm run lint`), Vitest, `npm run build`, manifest exists. ESLint covers `resources/js`, `tests/js` and the config files; the 10 problems that existed when it was added are recorded in `eslint-suppressions.json` | The lockfile installs cleanly on Linux (where the server builds), no high-severity advisories, assets build |
| End-to-end (Playwright) | Builds the frontend, starts a MySQL 8.4 container, then `npm run e2e`: the app is served on a freshly seeded database and Chromium runs the browser tests in `tests/e2e/` (see `testing.md`). A failed run uploads a report with screenshots and traces | The main journeys work in a real browser, which the PHP tests cannot see |
| Secret scan | gitleaks over the full history | No leaked passwords or keys |
| **CI passed** | Fails if any job above failed | The single check that branch protection requires |

Dependabot opens weekly update PRs for Composer, npm and GitHub Actions
against `main`; they run the same checks.

### Working with the PHPStan baseline

- Run it locally: `vendor/bin/phpstan analyse --memory-limit=1G`.
- A new finding is yours to fix in the same PR. Do not add it to the baseline
  and do not add `@phpstan-ignore` comments to get a build through.
- When you fix code that has a baseline entry, PHPStan reports the entry as
  unmatched and fails. Shrink the baseline with
  `vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon --memory-limit=1G`
  and check in `git diff` that it only lost entries.
- Raising the level (`phpstan.neon`) is a separate, deliberate PR: set the level,
  regenerate the baseline, and say in the PR how many findings it records.

### Working with the ESLint suppressions

- Run it locally: `npm run lint`. It checks `resources/js`, `tests/js` and the
  config files; `app/` (including CodeIgniter's own error-page script) and the
  Python service are out of scope.
- A new problem is yours to fix in the same PR. Do not add it to
  `eslint-suppressions.json` and do not add `eslint-disable` comments to get a
  build through.
- When you fix code that has a suppression, ESLint fails with "There are
  suppressions left that do not occur anymore". Remove it with
  `npx eslint --prune-suppressions` and check in `git diff` that the file only
  lost entries.
- Adding a rule is a separate, deliberate PR: turn the rule on, record what it
  finds with `npx eslint --suppress-all`, and say in the PR how many.
- Adding or updating an npm package: run `npm ci --dry-run` before pushing.
  On Windows, `npm install` can drop the optional `@emnapi/*` entries under
  `node_modules/@tailwindcss/oxide-wasm32-wasi/` from `package-lock.json`,
  which makes `npm ci` fail on the Linux runner. If it does, restore those two
  entries from `main`'s lock file.

## What stops a bad change (and what does not)

**The deploy is gated.** `deploy.yml` calls `ci.yml` and its `deploy` job
`needs: ci`, so a commit that fails CI is never deployed, even if it reached
`main` by a direct push. Production adds two more gates: the tag must be a
`vX.Y.Z` tag on a commit already on `main`, and the `production` environment
waits for a reviewer's approval.

**Merging is not yet enforced.** GitHub does not enforce rulesets or branch
protection on a private repository on a free plan. So nothing technically
prevents a direct push to `main` or merging a PR with red checks; the PR
workflow in `CONTRIBUTING.md` is followed by habit. When enforcement is
available (public repo, paid plan or organization), add one ruleset for `main`:
require a pull request, require **CI passed**, require linear history, block
force pushes and deletions.

## What is not here yet

Tracked in the plan: a higher PHPStan level, stricter ESLint rules, release
folders with automatic rollback, an external uptime service (the hourly check in `uptime.md` is the stopgap), more end-to-end
flows (one runs today).
The deploy itself is described in `docs/deploy.md`.
