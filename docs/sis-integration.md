# SIS integration: connector core

How an institution's student information system (SIS) is connected. This branch builds the connection, its credentials, the test and the run history. The import (branch 30) and the grade export (branch 31) are built on it.

## Direction of truth

- Inbound (students, instructors, courses, enrolments): **the SIS wins.**
- Outbound (final grades only): **Mentora wins.**

## The connector contract

`App\Services\Sis\SisConnector`:

| Method | Does |
| --- | --- |
| `testConnection()` | Says whether it works and what it found. Never throws. |
| `read($dataset)` | Returns a `SisBatch` of usable records and rejected rows (with row number and reason). Throws `SisException` when the whole dataset is unusable. |
| `publishGrades($batchKey, $rows)` | Delivers final grades. The same batch key replaces the earlier delivery, so retries never duplicate. |

A real SIS product is a new class behind this interface, registered in `SisConnectors::types()` and `make()`. The admin page needs no change beyond that.

## The file connector

The SIS (or someone on its behalf) drops CSV files in a folder; grades are written to another.

```
<sis.path>/<institution id>/inbound/students.csv
                                    instructors.csv
                                    courses.csv
                                    enrollments.csv
<sis.path>/<institution id>/outbound/grades-<batch key>.csv
```

`sis.path` in `.env` defaults to `writable/sis`. Folders are derived from the institution id, never typed in, so a connection cannot point anywhere else.

### Columns

| File | Required | Optional |
| --- | --- | --- |
| students.csv | external_id, email, first_name, last_name | student_number |
| instructors.csv | external_id, email, first_name, last_name | |
| courses.csv | external_id, course_code, course_title | units, program_code |
| enrollments.csv | course_external_id, student_external_id | section, status (enrolled, dropped, withdrawn, completed) |

Header names are matched without regard to case; spaces count as underscores; a byte order mark is ignored; unknown columns are ignored. A missing required column rejects the file. A bad row (empty required value, invalid email, units not 0 to 99.99, a value over 255 characters, wrong column count, repeated `external_id`) is rejected alone, with its row number (the header is row 1). Files over 10 MB or 50,000 rows are refused.

### Signing key

With a signing key saved, every inbound file needs `<name>.csv.sig` holding the hex HMAC-SHA256 of the file, and outbound grades are signed the same way. Without a key, files are accepted unsigned.

## Credentials

- Sealed with the application's `encryption.key` (`CredentialVault`) before they reach the database. Run `php spark key:generate` if the server has none.
- **Write-only.** The page, responses, audit entries and logs only ever say whether credentials are set and when they changed. A blank field keeps what is saved; "Remove" clears it.
- Changing `encryption.key` makes saved credentials unreadable; enter them again.

## Admin page

Institution page, **Integrations** tab (`/admin/institutions/{id}/integrations`):

- `integrations.view` to look; `integrations.manage` to save or test; saving also asks for the password (it can replace credentials).
- The connection can be **enabled** only while the `sis.sync` feature is on for the institution. It can be configured and tested before that.
- **Test connection** reports what each file holds ("students.csv: 120 usable, 3 rejected") or why it cannot be read, and records a run.
- Run history lists every test and (from the sync branches) every inbound and outbound run with created, updated, skipped and failed counts.

## Data

- `sis_connections`: one per institution (connector type, enabled, sealed credentials).
- `sis_runs`: one per test or run (direction, trigger, status, dry run, counts, message).
- Both are removed with the institution.

## Audit

`sis.connection_saved` (flags only, never values) and `sis.connection_tested`.

## Tests

`SisCsvParserTest` and `FileConnectorTest` (unit: contract, malformed rows, signatures, vault), `SisConnectionServiceTest` (database: sealing, flag rule, runs, audit), `AdminIntegrationsTest` (feature: access, credentials never returned).
