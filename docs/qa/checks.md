# Checks to run

| | |
|---|---|
| **Date** | 2026-10-28 (Wave 4 of the QA plan) |
| **Status** | CK-01 done (2026-10-28); CK-02 to CK-08 not run. |

These are the QA plan's checks (`CHK` in [`coverage-matrix.md`](coverage-matrix.md)): one-off investigations or runs whose answer changes other cases or the plan. They are not test cases. Run them in the execution phase, on the dedicated QA environment (register PLN-07), the baseline first (register PLN-05).

| ID | Check | Why it matters | How | Done when |
|---|---|---|---|---|
| CK-01 | **Done 2026-10-28** ([`baseline-2026-10-28.md`](baseline-2026-10-28.md)): PHP 7,510/7,510, Vitest 392/393 (one load-dependent timeout), Playwright 110/110. **Baseline run** of the PHP, Vitest and Playwright suites on `mentora_test` and `mentora_e2e` | Nothing in the catalog can be called passing until a run exists; it also settles every `FIX?` and `REG?` case | Follow register PLN-05: check the environment and database names first; record each suite's command, commit, versions, and passed, failed, skipped and errored counts | A dated results record exists and the counts in `docs/testing.md`, the audit and the inventory are reconciled |
| CK-02 | **A successful sign-in is recorded**, so a later success resets the failure count (PS-AUT-02) | TC-AUTH-06 depends on it (decision AMB-AUTH-01: it is) | Sign in with failures then a success on the test database; read `auth_logins` | A success row exists; if not, it is a defect against AMB-AUTH-01 |
| CK-03 | **Route sweep**: every route is public, known, or role-protected (PS-RBA-01) | Plans 4–9 added routes | Run `tests/feature/RouteAccessTest.php` and compare `php spark routes` with its lists | No unlisted route; any new one is added to the test |
| CK-04 | **Six assessment actions with no route** (publish, unpublish, archive, restore, duplicate, statistics) (PS-ASM-06) | Code exists that nothing can reach | Look for each in `app/Config/Routes.php` and the screens | Each is either wired (with a case) or removed |
| CK-05 | **`weight_percentage` on an assessment** is not used by any active workflow (PS-ASM-11) | Decision AMB-DB-01: the scheme's category weights are the only source | Search the code and the screens for reads and writes of the column | No calculation reads it; then it may be removed |
| CK-06 | **Migrations** apply from empty and on a copy with data; the timestamps that tie; each new migration's down and re-run (PS-DAT-01) | Seven migrations were added after `27f2012` | `php spark migrate:refresh --all` on an empty test database and on a copy of seeded data; `migrate:rollback` and `migrate` for each of the seven | All apply and roll back cleanly |
| CK-07 | **Production build**: a missing Vite manifest raises the "run npm run build" message; development uses the dev server (PS-FE-06) | A deploy without built assets must fail loudly | Remove `public/build/.vite/manifest.json` in a production setting and open a page; then development | The message appears; development works |
| CK-08 | **Deploy steps**: environment check, pre-deploy dump, rsync, migrate, worker restart, health poll; migrations are never rolled back automatically (PS-CI-02) | The deploy is the release gate | Read the workflow in `.github/workflows` and run it against a test server | Each step runs in order and a failure stops the deploy |
