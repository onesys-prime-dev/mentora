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

# Inbound import (branch 30)

People, courses and enrolments flow from the SIS into one institution. **The SIS wins** on any difference.

## What a run does

1. Reads whichever of `students.csv`, `instructors.csv`, `courses.csv`, `enrollments.csv` the SIS provided (a missing file is left alone). A file that cannot be read at all (bad columns, bad signature, too big) fails the whole run before anything is written.
2. In **one database transaction**, applies people, then courses, then enrolments.
3. Records the run (counts per dataset, and the rows that were not imported with the reason, up to 200) in the run history.

Counts per dataset:

| Count | Meaning |
| --- | --- |
| created | a new person, course or enrolment |
| updated | an existing one whose details differ from the SIS |
| skipped | already identical, nothing to do |
| failed | not imported: invalid row, a conflict, no seat left, an unknown course or student, no active term |

### Guarantees

- **All or nothing.** If anything unexpected goes wrong, the run is rolled back and recorded as failed; no partial data. System administrators are notified (urgent, also by email).
- **Single bad rows do not stop the run.** They are counted as failed with their reason; the rest imports.
- **Student and staff of one course never meet** (`course-access.md`): an enrolment row for someone who teaches, chairs or leads the course fails ("I2 teaches or has taught this course."), and a staff row for someone enrolled there fails with the assignment's own words ("Ivy Tan is enrolled in this course this term.").
- **Preview (dry run).** Does exactly the work of a real run inside the transaction, then rolls it back. Its counts are what the real run produces, and nothing is written.
- **First real import needs a preview** (any earlier successful import or preview counts; a connection test does not).
- A real import needs: the `sis.sync` feature on, the connection enabled, and the institution active. A preview needs the feature and saved settings only.

## How records are matched

`sis_links` remembers which Mentora person or course is which SIS id.

- **People:** by link; otherwise by email, but only an account that already belongs to this institution with the same role. An account in another institution, with another role, or an administrator is never taken over: the row fails. One account cannot belong to two SIS records.
- **Courses:** by link; otherwise by program and course code (an existing course is adopted, not duplicated). A new course needs a `program_code` that is a program of this institution. Units default to 3 when the SIS says none.
- **Enrolments:** the course and the student must be known (listed in `courses.csv` / `students.csv`, in this run or an earlier one). They go into the institution's **active term** (the latest, if several), in the section the SIS names ("A" when none), with the status the SIS gives ("enrolled" when none).

What the SIS leaves empty is not a change. Things the SIS stops listing are **never deleted**.

## Accounts

New people get an active account and **no password**. Send them a link from the Users page ("reset password"). Students take a seat; when the plan's seats are full, further students fail with the seat message.

## Fields the SIS owns

While the connection is enabled, a person the SIS supplied cannot have their **name or email** changed in Mentora (the Users page shows the fields read-only, and the server refuses). Switching the connection off releases them. Courses and enrolments are SIS-owned too, but nothing in Mentora edits them by hand today, so nothing is locked yet.

## Schedule

```
php spark sis:sync [--institution=ID] [--dry-run]
```

Runs every institution with an enabled connection. One that cannot run now (feature off, not active, no preview yet) is reported and skipped; the rest continue. Exits non-zero if any run failed. Add it to the server's scheduler (nightly is typical), like `subscriptions:sync` and `backup:run`.

## Admin page

Integrations tab: **Preview (dry run)** and **Import now** (needs your password), the latest result with the list of rows that were not imported, and the run history with a details column per run.

## Audit

`sis.import_previewed`, `sis.import_run` (inside the transaction: if it cannot be written, the import is rolled back), `sis.import_failed`.

## Tests

`SisMapperTest` (unit: mapping and change detection), `SisImporterTest` (database: counts, matching, conflicts, seats, preconditions, lock, command), `SisImporterRollbackTest` (non-isolated: preview writes nothing and equals the real run; a failed run leaves nothing), `AdminSisImportTest` (feature), `UsernameGeneratorTest`.

# Outbound final grades (branch 31)

Final grades go from Mentora to the SIS. **Mentora wins**: the SIS receives what is here.

## What is sent

Only final grades, four columns: `course_external_id`, `student_external_id`, `percentage` (two decimals) and `remarks` (Passed, Failed, Incomplete, Withdrawn). Never assessment scores, feedback or anything else.

- The final percentage is the instructor's override when there is one, otherwise the calculated percentage.
- Nothing is sent for a student with no final percentage yet.
- Only grades for courses and students the SIS knows (linked by the inbound import) are sent. A local course or student is left out.
- Only what is **new or changed** since it was last sent. `sis_grade_exports` remembers what went out; a run with nothing new sends nothing.

## Never duplicated

- A delivery has a **key made from its content** (`g<institution id>-<24 hex>`): the same grades always give the same key, in any order. The connector replaces a delivery it already has under that key (the file connector writes `grades-<key>.csv`, and `.sig` when a signing key is set).
- A failed delivery is **retried right away** (3 attempts). If it still fails, nothing is marked as sent, the run is recorded as failed and system administrators are notified (urgent, also by email). The next run sends the same batch again.
- If Mentora crashes after the SIS has the grades but before recording them, nothing is marked as sent; the next run sends the same batch with the same key, and the SIS replaces it.

## Running it

| | |
| --- | --- |
| Integrations tab | **Preview what would be sent** and **Send final grades now** (needs your password). |
| Schedule | `php spark sis:grades [--institution=ID] [--dry-run]`. Put it in the server's scheduler, after the nightly `sis:sync`. |

A preview works out the counts and sends and records nothing. Sending needs the `sis.sync` feature on, the connection enabled and the institution active; a preview needs the feature and saved settings only.

Counts in the run history: **created** = grades sent for the first time, **updated** = changed grades sent again, **skipped** = left out (unchanged, or not graded yet).

## Audit

`sis.grades_previewed`, `sis.grades_sent` (inside the recording transaction: if it cannot be written nothing is marked as sent), `sis.grades_failed`.

## Tests

`SisGradePayloadTest` (unit: payload, idempotency key), `SisGradeExporterTest` (database: what is sent, retry, repeat, failure, preconditions, command), `SisGradeExporterRollbackTest` (non-isolated: a crash after delivery never duplicates), plus the grade cases in `AdminSisImportTest`.
