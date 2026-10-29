# Institution data export

When a subscription ends, the institution's administrator can take a full copy of their data. This is the offboarding export (SA13).

## Who and when

- Only an **institutional administrator**, and only for **their own institution**.
- Only while the institution is **read-only** or **past**. An active or pending institution is refused with an explanation.
- The `institution` route group is deliberately **not** behind the `readonly` filter, so the export keeps working after the subscription lapses.
- An institutional administrator lands on their home, `/institution/dashboard`, after sign-in; the Apps menu has a "Data export" tile for this page.

## What is in the ZIP

One CSV per dataset (UTF-8, header row), plus a `README.txt` with row counts:

`institution`, `terms`, `departments`, `sub_departments`, `programs`, `courses`, `offerings`, `people`, `enrollments`, `final_grades`, `assessments`, `submissions`, `announcements`.

Everything is scoped through the institution's departments. Not included: uploaded files (documents, videos, submitted files) and passwords. People are listed with email, username, name, roles and active flag. Cells that would run as a spreadsheet formula are prefixed with an apostrophe (the same defusing as the user export).

## Prepared in the background (audit 12)

- **Create an export** only queues it (`InstitutionExport::request`). The server's `php spark exports:run`, every minute, prepares it (`runQueued`): `queued`, `running`, then `ready` or `failed`. The administrator can leave the page.
- **One at a time per institution** (12.3): a second request while one is queued or running is refused with "An export is already being prepared. You'll be told when it's ready." The database keeps it so (`open_marker`, unique with the institution).
- **Only the person who asked is told** (12.4), in the app and by email: "Your data export is ready" or "Your data export failed". Every administrator sees "Being prepared" and the result on the page.
- **A stalled run** (still `running` 30 minutes after it started, for example because the process died) is ended as failed, "The export stopped before it finished. Ask for a new one.", and a new one can be asked for.

## Scale (audit 12.1)

No table is read whole: each CSV is filled in keyset pages of 5,000 rows on its driving table's id, written to a temporary file, then added to the ZIP. Rows are therefore in the order they were recorded (the README says so). `InstitutionExportScaleTest` checks that memory does not grow between 20,000 and 80,000 submissions. The full target (20,000 students, five years, about 50 graded items per course: 2,000,000 submissions) is a manual check before go-live, with `ExportScaleSeeder`:

1. Create an empty database whose name contains `scale` (for example `mentora_scale`), and point `database.default.database` in `.env` at it (`.env` wins over a value set on the command line). The seeder refuses any other database.
2. `php spark migrate --all`, then `php spark db:seed ExportScaleSeeder` (about 5 minutes on the development laptop).
3. Queue an export for the seeded institution and run `php -d memory_limit=128M spark exports:run`. It must finish within the limit; record the time here.
4. Point `.env` back at the usual database and drop the scale one.

Not done yet. A first attempt (2026-10-27) was stopped after more than 10 minutes by the laptop running low on memory, so neither the time nor whether paging over 2 million rows needs faster queries is known.

## Storage and retention

- Files live in `writable/exports` (override with `export.path` in `.env`), named `<code>-<timestamp>-<random>.zip`.
- Each export is kept for **7 days** (`InstitutionExport::KEEP_DAYS`), then removed with its row the next time any export is built.
- The `institution_exports` table holds the history: who, when, size, row counts, expiry. Purging an institution removes its rows (the cascade); files are removed by expiry.

## Audit

- `institution.export_requested` when an export is asked for; `institution.exported` when it is ready (with file and row counts, the requester as actor).
- `institution.export_downloaded` on every download.
- If the request's audit entry cannot be written, nothing is queued; if the finished export's cannot, it ends as failed and its file is removed.

## Routes

| Verb | Path | Does |
| --- | --- | --- |
| GET | `/institution/export` | page: create button, history |
| POST | `/institution/export` | ask for an export (it is prepared in the background) |
| GET | `/institution/export/(:num)` | download one of your institution's exports |

## Tests

- `InstitutionExportServiceTest` (database): contents, eligibility, scoping, no hashes, formula defusing, expiry and pruning.
- `InstitutionExportRollbackTest` (database, non-isolated): audit failure keeps nothing.
- `InstitutionExportTest` (feature): access matrix, other institution refused, logged.
