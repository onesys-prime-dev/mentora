# CI/CD overview

What runs automatically, and what it protects. See `CONTRIBUTING.md` for the
day-to-day workflow.

## Pipelines

| Workflow | File | Runs when | Purpose |
|---|---|---|---|
| CI | `.github/workflows/ci.yml` | PR into `staging` / `main`, push to `main` | Prove the change is sound |
| Deploy | `.github/workflows/deploy.yml` | push to `staging` | Build, back up the database, copy the app, migrate, health-check (not gated on CI yet; see `deploy.md`) |

## CI jobs (all must pass)

| Job | Check | Why |
|---|---|---|
| PHP syntax (8.2, 8.5) | `php -l` on every file in `app/`, `tests/`, `public/` and `spark` | 8.2 is the minimum in `composer.json` and the local version; 8.5 is the version the staging site runs |
| Composer | `composer validate --strict`, `composer install`, `composer audit` | The manifest and lock agree, dependencies install, no known vulnerable packages |
| Tests on MySQL (8.2, 8.5) | A MySQL 8.4 container, `php spark migrate --all` on the empty database (then checked: the history table must hold a row for every App migration), then PHPUnit. On 8.2 the build also fails if line coverage drops below `.github/coverage-floor` | Migrations work on a fresh install, the test suite passes on the minimum and the production PHP, and coverage can only go up |
| Frontend build and audit | `npm ci`, `npm audit --omit=dev --audit-level=high`, `npm run build`, manifest exists | The lockfile installs cleanly on Linux (where the server builds), no high-severity advisories, assets build |
| Secret scan | gitleaks over the full history | No leaked passwords or keys |
| **CI passed** | Fails if any job above failed | The single check that branch protection requires |

Dependabot opens weekly update PRs for Composer, npm and GitHub Actions
against `staging`; they run the same checks.

## What stops a bad change (and what does not)

**Nothing enforces it yet.** GitHub does not enforce rulesets or branch
protection on a private repository on a free plan, and the deploy is not gated
on CI. So today nothing technically prevents a direct push to `staging` or
`main`, merging a PR with red checks, or deploying a commit that failed CI. The
PR workflow in `CONTRIBUTING.md` is followed by habit: open a PR, wait for
**CI passed**, then merge.

Options when that is not enough: make `deploy.yml` call `ci.yml` first and
deploy only if it passes (a deploy gate), and/or turn on rulesets if the repo
becomes public or moves to a paid plan/organization (one each for `staging` and
`main`: require a pull request, require **CI passed**, require linear history,
block force pushes and deletions). Revisit when a second developer joins or a
production server exists.

## What is not here yet

Tracked in the plan: the domain-by-domain tests (see `docs/testing.md`), static
analysis (PHPStan, Semgrep), release folders with automatic rollback, a CI gate
on the deploy, end-to-end tests, and the production environment with approval.
The deploy itself is described in `docs/deploy.md`.
