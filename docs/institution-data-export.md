# Institution data export

When a subscription ends, the institution's administrator can take a full copy of their data. This is the offboarding export (SA13).

## Who and when

- Only an **institutional administrator**, and only for **their own institution**.
- Only while the institution is **read-only** or **past**. An active or pending institution is refused with an explanation.
- The `institution` route group is deliberately **not** behind the `readonly` filter, so the export keeps working after the subscription lapses.
- An institutional administrator now lands on `/institution/export` after sign-in (previously they had no landing page).

## What is in the ZIP

One CSV per dataset (UTF-8, header row), plus a `README.txt` with row counts:

`institution`, `terms`, `departments`, `sub_departments`, `programs`, `courses`, `offerings`, `people`, `enrollments`, `final_grades`, `assessments`, `submissions`, `announcements`.

Everything is scoped through the institution's departments. Not included: uploaded files (documents, videos, submitted files) and passwords. People are listed with email, username, name, roles and active flag. Cells that would run as a spreadsheet formula are prefixed with an apostrophe (the same defusing as the user export).

## Storage and retention

- Files live in `writable/exports` (override with `export.path` in `.env`), named `<code>-<timestamp>-<random>.zip`.
- Each export is kept for **7 days** (`InstitutionExport::KEEP_DAYS`), then removed with its row the next time any export is built.
- The `institution_exports` table holds the history: who, when, size, row counts, expiry. Purging an institution removes its rows (the cascade); files are removed by expiry.

## Audit

- `institution.exported` when an export is built (with file and row counts).
- `institution.export_downloaded` on every download.
- If the audit entry cannot be written, the export row and file are discarded.

## Routes

| Verb | Path | Does |
| --- | --- | --- |
| GET | `/institution`, `/institution/export` | page: create button, history |
| POST | `/institution/export` | build an export now |
| GET | `/institution/export/(:num)` | download one of your institution's exports |

## Tests

- `InstitutionExportServiceTest` (database): contents, eligibility, scoping, no hashes, formula defusing, expiry and pruning.
- `InstitutionExportRollbackTest` (database, non-isolated): audit failure keeps nothing.
- `InstitutionExportTest` (feature): access matrix, other institution refused, logged.
