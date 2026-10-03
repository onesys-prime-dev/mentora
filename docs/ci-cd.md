# CI/CD overview

What runs automatically, and what it protects. See `CONTRIBUTING.md` for the
day-to-day workflow.

## Pipelines

| Workflow | File | Runs when | Purpose |
|---|---|---|---|
| CI | `.github/workflows/ci.yml` | PR into `staging` / `main`, push to `main`, and called by Deploy | Prove the change is sound |
| Deploy | `.github/workflows/deploy.yml` | push to `staging` | Run CI first; only if it passes, build and copy the app to the staging server |

## CI jobs (all must pass)

| Job | Check | Why |
|---|---|---|
| PHP syntax (8.2, 8.5) | `php -l` on every file in `app/`, `tests/`, `public/` and `spark` | 8.2 is the minimum in `composer.json` and the local version; 8.5 is the version the staging site runs |
| Composer | `composer validate --strict`, `composer install`, `composer audit` | The manifest and lock agree, dependencies install, no known vulnerable packages |
| Tests on MySQL (8.2, 8.5) | A MySQL 8.4 container, `php spark migrate --all -g tests` on the empty database, then PHPUnit (coverage report on 8.2) | Migrations work on a fresh install, and the test suite passes on the minimum and the production PHP |
| Frontend build and audit | `npm ci`, `npm audit --omit=dev --audit-level=high`, `npm run build`, manifest exists | The lockfile installs cleanly on Linux (where the server builds), no high-severity advisories, assets build |
| Secret scan | gitleaks over the full history | No leaked passwords or keys |
| **CI passed** | Fails if any job above failed | The single check that branch protection requires |

Dependabot opens weekly update PRs for Composer, npm and GitHub Actions
against `staging`; they run the same checks.

## What stops a bad change (and what does not)

**The deploy gate (active).** `deploy.yml` calls `ci.yml` first and deploys only
if **CI passed**. A broken push to `staging` therefore never reaches the demo
server, however it got there.

**Branch rules (not enforced).** GitHub does not enforce rulesets or branch
protection on a private repository on a free plan. So today nothing technically
prevents a direct push to `staging` or `main`, or merging a PR with red checks;
the PR workflow in `CONTRIBUTING.md` is followed by habit, and the deploy gate
is the backstop. If rulesets ever become enforceable (public repo, or a paid
plan/organization), create one each for `staging` and `main`: require a pull
request, require the status check **CI passed** (up to date with the base
branch), require linear history, block force pushes and deletions. Revisit this
when a second developer joins or a production server exists.

## What is not here yet

Tracked in the plan: the domain-by-domain tests (see `docs/testing.md`), static analysis (PHPStan, Semgrep),
migrations and backups on deploy, release folders with rollback, a health
check, end-to-end tests, and the production environment with approval.
Until the deploy rewrite, the Deploy workflow still copies files straight
into the live folder.
