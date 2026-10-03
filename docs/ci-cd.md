# CI/CD overview

What runs automatically, and what it protects. See `CONTRIBUTING.md` for the
day-to-day workflow.

## Pipelines

| Workflow | File | Runs when | Purpose |
|---|---|---|---|
| CI | `.github/workflows/ci.yml` | PR into or push to `staging` / `main` | Prove the change is sound before it can merge |
| Deploy | `.github/workflows/deploy.yml` | push to `staging` | Build and copy the app to the staging server |

## CI jobs (all must pass)

| Job | Check | Why |
|---|---|---|
| PHP syntax (8.2, 8.5) | `php -l` on every file in `app/`, `tests/`, `public/` and `spark` | 8.2 is the minimum in `composer.json` and the local version; 8.5 is the version the staging site runs |
| Composer | `composer validate --strict`, `composer install`, `composer audit` | The manifest and lock agree, dependencies install, no known vulnerable packages |
| Frontend build and audit | `npm ci`, `npm audit --omit=dev --audit-level=high`, `npm run build`, manifest exists | The lockfile installs cleanly on Linux (where the server builds), no high-severity advisories, assets build |
| Secret scan | gitleaks over the full history | No leaked passwords or keys |
| **CI passed** | Fails if any job above failed | The single check that branch protection requires |

Dependabot opens weekly update PRs for Composer, npm and GitHub Actions
against `staging`; they run the same checks.

## Branch protection (set in GitHub, not in code)

Settings > Rules > Rulesets, for both `staging` and `main`:

- Require a pull request before merging (required approvals: 0 while you are
  the only developer; raise it when someone joins).
- Require status checks to pass: **CI passed**. Require branches to be up to
  date before merging.
- Block force pushes and branch deletion.

## What is not here yet

Tracked in the plan: tests on MySQL, static analysis (PHPStan, Semgrep),
migrations and backups on deploy, release folders with rollback, a health
check, end-to-end tests, and the production environment with approval.
Until the deploy rewrite, the Deploy workflow still copies files straight
into the live folder.
