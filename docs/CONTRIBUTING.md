# Contributing to Mentora

How changes get from your computer to the staging server. If you have never
used pull requests, read "The idea" first; the rest is a recipe.

## The idea

Mentora uses **GitHub Flow**: one long-lived branch, `main`, and short-lived
branches for each change.

A **pull request (PR)** is a request to merge one branch into another, with a
pause in the middle. The pause is where automated checks (CI) run on your
change and where a teammate looks at exactly what is changing. Nothing reaches
`main` without going through that pause.

```
short branch ──PR──▶ main ──auto-deploy (after CI passes)──▶ staging server
                       │
                       └── tag v* / GitHub Release ──▶ production (separate server, not set up yet)
```

- **`main`** is the single trunk. It should always be deployable.
- **Branches** are short: a day or two at most, one change each. Name them
  `feat/...`, `fix/...`, `test/...`, `docs/...` or `ci/...`.
- **Environments are servers, not branches**: local, then staging, then
  production. Merging to `main` deploys to the staging server; production is a
  separate release step.
- **Tests go in the same PR as the change.** The author writes them, a second
  person reviews, and QA checks the result on staging.
- You never push directly to `main`. Until the `main` ruleset is switched back
  on (it needs a plan that enforces rules on private repositories), this is a
  habit, not a lock; the deploy workflow still runs CI first and refuses to
  deploy a red commit.

## The recipe

1. **Start from an up-to-date `main`.**
   ```bash
   git checkout main
   git pull
   git checkout -b feat/short-description
   ```
2. **Work and commit.** Small commits with a clear message ("feat(courses): ...",
   "fix(grades): ...") make the PR easy to read. Add or update tests as you go.
3. **Push the branch.**
   ```bash
   git push -u origin feat/short-description
   ```
4. **Open the pull request** on GitHub (the page shows a "Compare & pull
   request" button after a push). The base branch is `main`. Fill in the
   template: what and why, how to check it, and the checklist.
5. **Wait for CI.** The PR page shows each check. The one that gates the merge
   is **CI passed**. Click "Details" on any red check to read why it failed.
6. **Fix and push again if something is red.** The PR updates by itself; CI
   re-runs on the new commit. You do not open a new PR.
7. **Get a review and read your own diff** under "Files changed" before merging.
8. **Merge only when CI is green and someone else has looked at it.** Use
   "Squash and merge" so each PR becomes one clean commit on `main`.
9. **Watch the deploy.** Merging into `main` starts the Deploy workflow
   (Actions tab). It runs CI again on the merged commit and then deploys. When it
   is green the change is on the staging server, ready for QA.
10. **Clean up.** Delete the branch (GitHub offers a button), then
    `git checkout main && git pull` locally.

### Releasing to production

There is no production server yet. The plan: production is released by pushing a
`v*` tag or publishing a GitHub Release, which re-runs CI and then waits for an
approval step (a GitHub "Environment" with required reviewers, or a manual
"Run workflow" button if required reviewers is not available on the plan). See
`docs/deploy.md`.

## When CI fails

| Failing check | Usually means | What to do |
|---|---|---|
| PHP syntax | A PHP file has a syntax error | Run `php -l path/to/file.php` locally |
| Composer | `composer.json`/`composer.lock` disagree, or a dependency has a known vulnerability | Run `composer validate --strict` and `composer audit` locally |
| Static analysis (PHPStan) | Your change introduced a new problem (a wrong type, an undefined method, a condition that is always true or false) | Run `vendor/bin/phpstan analyse --memory-limit=1G` and fix what it lists; do not add it to the baseline |
| Tests on MySQL | A test fails, a migration breaks on an empty database, coverage fell below `.github/coverage-floor`, or the lines your PR changed are not covered enough (the log lists them: add tests for exactly those lines) | Run `composer test` locally (see `docs/testing.md`) |
| Frontend build and audit | A Vitest test fails, the Vite build breaks, or an npm package has a high-severity advisory | Run `npm ci && npm test && npm run build && npm audit --omit=dev` locally |
| Secret scan | Something that looks like a password or key is in the code or its history | Remove it, rotate the secret, then ask before rewriting history |

## Rules of thumb

- Tests go in the same PR as the change. A bug fix includes a test that fails
  without the fix.
- Never skip or loosen a test, or lower the coverage floor, to get green.
- Database changes always come with a migration, and
  `php spark migrate --all` must work on an empty database.
- Never commit `.env`, keys, or real student data.
- If CI is red, do not merge "just this once". Fix it or ask.
