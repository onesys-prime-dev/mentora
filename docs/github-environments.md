# GitHub Environments: staging and production

A GitHub **Environment** is a named place you deploy to (here `staging` and
`production`). Each one has its own secrets, its own variables and its own
rules about who may deploy. The deploy workflows are written around them:

| Workflow | Starts when | Deploys to | Waits for |
|---|---|---|---|
| `deploy.yml` | a merge to `main` (or a manual run) | `staging` | CI passing |
| `deploy-production.yml` | a version tag like `v1.2.3` is pushed (or a manual run from the tag) | `production` | the tag being on `main`, CI passing, then **an approval** |

Both call `deploy-reusable.yml`, which holds the actual steps (build, back up
the database, copy, migrate, health check, smoke test). See `deploy.md` for
what those steps do and how to read a failure.

## Before you start

- **Plan check.** On a *private* repository, Environments need GitHub Pro,
  Team or Enterprise (they work on public repositories on every plan). If
  Settings has no **Environments** entry, or the protection rules are greyed
  out, you are on a plan without them: see "If your plan has no Environments".
- You need admin rights on the repository.
- Production needs its own server (or at least its own folder, database and
  `.env`). Never point two environments at the same folder.

## 1. Create the `staging` environment

1. Repository **Settings** > **Environments** > **New environment**.
2. Name it exactly `staging` (the workflow uses this name) > **Configure environment**.
3. **Deployment branches and tags**: choose *Selected branches and tags* >
   **Add deployment branch or tag rule** > Ref type *Branch*, pattern `main`.
   (Staging can then only be deployed from `main`.)
4. Leave *Required reviewers* off, so every merge to `main` still deploys by itself.
5. Under **Environment secrets** > **Add environment secret**, add:

   | Secret | Value |
   |---|---|
   | `VPS_PRIVATE_KEY` | the SSH private key whose public half is in the staging server user's `authorized_keys` |
   | `VPS_USERNAME` | `mentora` |
   | `VPS_HOST` | the staging server's host name or IP |
   | `VPS_KNOWN_HOSTS` | the `ssh-ed25519 ...` line from `ssh-keyscan -t ed25519 <host>` (see `deploy.md`) |

6. Under **Environment variables** > **Add environment variable**, add:

   | Variable | Value |
   |---|---|
   | `DEPLOY_PATH` | `/home/mentora/htdocs/mentora` |
   | `SITE_URL` | `https://mentora.srv1404227.hstgr.cloud` (no trailing slash) |
   | `SSH_PORT` | `22` |
   | `PHP_BIN` | `php8.5` (the PHP binary the site runs on, used for `spark migrate`) |
   | `TLS_INSECURE` | `true` while the site has a self-signed certificate; set `false` once it has a real one |

   Staging still works before you add the variables (it falls back to these
   same values), but add them so the settings live in one visible place.

## 2. Create the `production` environment

1. **New environment**, name exactly `production` > **Configure environment**.
2. **Required reviewers**: tick it and add the people who may approve a
   production deploy (up to six; one approval is enough).
   - **Prevent self-review**: tick it when someone other than the person who
     pushes the tag can approve. If you are the only person on the project,
     leave it **off**, or you could never approve your own release.
   - *Wait timer* is optional (a delay, in minutes, before the deploy may start).
3. **Deployment branches and tags**: *Selected branches and tags* >
   **Add deployment branch or tag rule** > Ref type *Tag*, pattern `v*`.
   Production can then only be deployed from a version tag.
4. **Environment secrets**: the same four names as staging, with the
   **production** server's values (`VPS_PRIVATE_KEY`, `VPS_USERNAME`,
   `VPS_HOST`, `VPS_KNOWN_HOSTS`). Use a separate SSH key for production, so
   revoking one never affects the other.
5. **Environment variables**: `DEPLOY_PATH`, `SITE_URL` (production's
   `https://` address), `SSH_PORT`, `PHP_BIN`, and `TLS_INSECURE` = `false`.
   Production has no fallback values: if one is missing the deploy stops at
   its first step and says which one.

## 3. Remove the old repository-level secrets

Until now `VPS_*` were **repository** secrets (Settings > Secrets and
variables > Actions). An environment's own secret wins over a repository one
with the same name, but a *missing* environment secret silently falls back to
the repository one. For production that would mean deploying with staging's
key. So once both environments hold their own copies, **delete the four
repository-level `VPS_*` secrets**.

## 4. Prepare the production server

The workflow refuses to deploy unless the folder already looks like a live
Mentora install. Once, on the production server, as the site user:

1. Create the site folder and give it `writable/` and a `.env` (copy `env` from
   the repo and fill it in: `CI_ENVIRONMENT = production`, `app.baseURL`, the
   database, the encryption key, and the `email.*` lines from `mail.md`).
2. Create the database and a user for it.
3. Run the first deploy (below), then create the first administrator on the
   server (see "The first system administrator" in `deploy.md`).

## Releasing to production

Production only ever gets code that is already on `main` and already ran on
staging.

```bash
git checkout main && git pull
git tag v1.0.0            # the next number; vMAJOR.MINOR.PATCH
git push origin v1.0.0
```

Then open the **Actions** tab:

1. The *Check the tag* job confirms the tag is a `vX.Y.Z` tag on a commit that is on `main`.
2. The *CI* job re-runs the whole test suite on that exact commit.
3. The *Production* job shows **Waiting** and GitHub notifies the reviewers.
   A reviewer opens the run > **Review deployments** > tick `production` >
   **Approve and deploy** (or **Reject**).
4. Only after approval do the build, database backup, copy, migration, health
   check and smoke test run.

If a production deploy fails or is rejected, fix what is needed and re-run: in
**Actions** > *Deploy Mentora to production* > **Run workflow**, choose the
**tag** (not a branch) under *Use workflow from*. The run asks for approval again.

To undo a bad release, see "Rolling back code" in `deploy.md`; a revert goes
through `main` and a new tag.

## If your plan has no Environments

Everything above still has a workable stand-in, with less protection:

- Keep the secrets at repository level, but give them different names per
  server (for example `STAGING_VPS_HOST`, `PRODUCTION_VPS_HOST`) and ask for the
  workflow to be changed to read them. Say so and it will be done.
- There is then no approval gate on production. The only protections are that
  the workflow starts from a `v*` tag, the tag must be on `main`, and CI must
  pass. Limit who can push tags by limiting who has write access to the repository.
- Making the repository public, or moving it to a paid plan or organization
  plan, unlocks Environments without changing the workflow files.

## Checking it worked

- After creating `staging`: Actions > *Deploy Mentora* > **Run workflow**. The
  run page shows `staging` under *Environment*, and the deployment appears in
  the repository's **Deployments** list with the site's link.
- After creating `production`: push a tag as above. Confirm the run **stops and
  waits** at *Production* until you approve. If it runs straight through,
  *Required reviewers* is not set on the environment.
- A deploy that stops at *Check this environment is configured* means a secret
  or variable is missing; the message names it.
