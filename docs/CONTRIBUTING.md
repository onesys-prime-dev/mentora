# Contributing to Mentora

How changes get from your computer to the staging server. If you have never
used pull requests, read "The idea" first; the rest is a recipe.

## The idea

A **pull request (PR)** is a request to merge one branch into another, with a
pause in the middle. The pause is where automated checks (CI) run on your
change and where you, or a teammate, look at exactly what is changing. Nothing
reaches `staging` or `main` without going through that pause.

```
feature/my-change ──PR──▶ staging ──PR──▶ main
                           │              │
                     auto-deploys     deploys to production
                     to staging       after manual approval
                     (demo server)    (no production server yet)
```

- **`feature/*`** branches are where all work happens. One branch per change.
- **`staging`** is what the demo server runs. Merging a PR into it deploys it.
- **`main`** is the production line. It is only updated from `staging`.
- You never push directly to `staging` or `main`. GitHub does not enforce this
  on a private repo on a free plan, so it is a habit, not a lock. If you slip
  and push a broken commit to `staging`, the deploy refuses to run because CI
  fails, but the commit stays on the branch and has to be fixed or reverted.

## The recipe

1. **Start from an up-to-date `staging`.**
   ```bash
   git checkout staging
   git pull
   git checkout -b feature/short-description
   ```
2. **Work and commit** on your feature branch. Small commits with a clear
   message ("feat(courses): ...", "fix(grades): ...") make the PR easy to read.
3. **Push the branch.**
   ```bash
   git push -u origin feature/short-description
   ```
4. **Open the pull request** on GitHub (the page shows a "Compare & pull
   request" button after a push). Set the base branch to `staging`. Fill in the
   template: what and why, and how to check it.
5. **Wait for CI.** The PR page shows each check. The one that gates the merge
   is **CI passed**. Click "Details" on any red check to read why it failed.
6. **Fix and push again if something is red.** The PR updates by itself; CI
   re-runs on the new commit. You do not open a new PR.
7. **Read your own diff** under "Files changed" before merging. Most mistakes
   are caught this way.
8. **Merge only when CI is green.** GitHub will not stop you from merging a
   red PR (see above), so check the "CI passed" line yourself. Use
   "Squash and merge" so each PR becomes one clean commit on `staging`.
9. **Watch the deploy.** Merging into `staging` starts the Deploy workflow
   (Actions tab). When it is green the change is on the demo server.
10. **Clean up.** Delete the feature branch (GitHub offers a button), then
    `git checkout staging && git pull` locally.

### Releasing to production

Open a PR from `staging` into `main`. When merged, the production deploy waits
for an approval click (GitHub "Environments"). Production deploys are not set
up yet because there is no production server; the staging side works today.

## When CI fails

| Failing check | Usually means | What to do |
|---|---|---|
| PHP syntax | A PHP file has a syntax error | Run `php -l path/to/file.php` locally |
| Composer | `composer.json`/`composer.lock` disagree, or a dependency has a known vulnerability | Run `composer validate --strict` and `composer audit` locally |
| Frontend build and audit | The Vite build breaks, or an npm package has a high-severity advisory | Run `npm ci && npm run build && npm audit --omit=dev` locally |
| Secret scan | Something that looks like a password or key is in the code or its history | Remove it, rotate the secret, then ask before rewriting history |

## Rules of thumb

- A bug fix includes a test that fails without the fix.
- Database changes always come with a migration, and
  `php spark migrate --all` must work on an empty database.
- Never commit `.env`, keys, or real student data.
- If CI is red, do not merge "just this once". Fix it or ask.
