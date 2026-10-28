# QA test plan

| | |
|---|---|
| **Project** | Mentora LMS (`C:\xampp\htdocs\mentora`) |
| **Date** | 2026-10-26 |
| **Branch** | `docs/qa-test-cases`, from `fix/approvals` at `27f2012` (42 staged files of plan-4 approvals work and four untracked items are present and untouched) |
| **Document owner** | _to be named (PLN-08)_ |
| **Test design complete 2026-10-28** | All four waves are written: 285 catalog cases in `docs/test-cases/` (Waves 1–4 and the cases that were there), 12 exploratory charters (`exploratory-charters.md`) and 8 checks (`checks.md`). Every one of the 415 planning scenarios is placed (`coverage-matrix.md` Part K). Nothing has been run: execution starts with the baseline (CK-01). |
| **Decisions complete 2026-10-28** | Every open item was decided under the owner's delegation (register Part I). 415 scenarios, none blocked: READY-IBR 76, READY-VCI 221, REG 8, REG? 21, FIX? 33, NB 17, OUT 1, EXP 31, CHK 7. Owner and capacity: one person, 30 h a week, 25%% buffer (PLN-08). Privacy tests are P1 (PLN-09). The baseline runs first in execution (PLN-05). |
| **Update 2026-10-28** | The code under test is now `ae86042` (plans 4–9 committed; the QA branch was fast-forwarded). The newer code is the source of truth where it made a deliberate choice. 31 scenarios are now `FIX?` (fix committed, not run); see `coverage-matrix.md` Part F and `decision-register.md` Part H. The risks scored for jobs, backups, purge, course roles and lesson links (section 5) assumed those were not built and must be re-scored after a baseline run. |
| **Update 2026-10-27** | The reviewer signed off the decision register (Part F). **Read this first:** the figures in this plan were written before it. Now: 414 scenarios (was 413); 4 blocked (was 69); `READY-IBR` 74, `REG` 12, `REG?` 41, `NB` 28, `CHK` 8. 92 register items are decided, 25 decided with an open sub-point and 5 open (PLN-01, PLN-08, PLN-09, AMB-ASG-11, MISS-HTTP-01). Section 5 risk scores, section 10 defect register and section 12 estimate have **not** been re-scored: that waits for PLN-01, PLN-08 and PLN-09. New defect candidates from the sign-off are the `REG` / `REG?` scenarios in the matrix; they are suspected from code reading, not run. See `decision-register.md` Part G. |
| **Status** | **Draft for review.** Planning only: no test case has been written, no test has been run, no application code or database has been changed. |
| **Method** | The `risk-based-testing`, `test-strategy` and `test-planning` skills (installed in `.claude/skills/`), applied to [`project-inventory.md`](project-inventory.md). |
| **Companions** | [`decision-register.md`](decision-register.md) (unresolved and contradictory behavior), [`coverage-matrix.md`](coverage-matrix.md) (modules and workflows against coverage, with the 413-scenario backlog) |

## 0. Reading this plan

### 0.1 Three kinds of statement

Every behavior in these documents is one of three kinds, and a test's expected result may only come from the first two.

| Kind | Short | Where it comes from | Used as an expected result? |
|---|---|---|---|
| **Verified current implementation behavior** | VCI | Reading the code. **[V]** = I checked it directly; **[A]** = an audit agent read it. Nothing has been run. | Only as a `Draft` characterization, to be confirmed by an owner. |
| **Intended behavior established by an authoritative requirement** | IBR | A numbered decision in `docs/audit-2026-10-23-decisions.md` (1.1–16.1, U1–U5, D1–D11), or a catalog case marked `Confirmed by Shuay` (the 17 P1 cases). | **Yes.** |
| **Unresolved behavior requiring a decision** | UNR | No authoritative requirement, or sources disagree. Listed in the decision register. | **No.** Dependent scenarios are `BLK` (blocked). |

### 0.2 What this plan does not claim

- **No test baseline exists.** Nothing in this plan says any existing test passes. Counts of tests come from file inventories and disagree across sources (see 6.1).
- **A VCI behavior is never promoted to a requirement.** If code contradicts an IBR, that is a defect with a regression scenario (section 10), not a case to "document as is".
- **Ambiguous requirements get no invented expected result.** They are blocked until a decision is recorded.
- **The staged plan-4 work is described as it sits on disk.** It is uncommitted and its own documentation task is undone.

### 0.3 Status vocabulary for scenarios

`READY-IBR`, `READY-VCI`, `REG`, `REG?`, `NB`, `BLK`, `EXP`, `CHK`, defined in the coverage matrix, Part C. In short: write now from a requirement (`READY-IBR`); write now as Draft from verified behavior (`READY-VCI`); regression for a defect (`REG` verified, `REG?` suspected); a requirement not built yet (`NB`); **blocked** (`BLK`); exploratory (`EXP`); an investigation (`CHK`).

## 1. Executive summary

**What Mentora is.** A multi-tenant LMS for higher-education institutions: seven roles, one account per institution, a course/assessment/grading core with a multi-step grade-approval chain, an SIS file integration, a parent portal, an AI question builder (Python worker plus an external model provider), and platform administration (subscriptions, backups, jobs). About 480 commits, nearly all in the last three months.

**What exists to test with.** Roughly 5,300 automated test methods by count (about 80% PHPUnit service/route tests that use a real database, 18% unit and JS, 2% Playwright) and a draft catalog of 98 manual/browser cases (17 owner-confirmed). **No baseline run exists.**

**What this plan proposes.** A 413-scenario backlog across 52 modules and 24 workflows, ordered in four waves. Of the 413:

| | Count | Meaning |
|---|---|---|
| Ready from an authoritative requirement or confirmed case (`READY-IBR`) | 58 | Can be written now |
| Regression for a verified or suspected defect (`REG`, `REG?`) | 21 | Expected to fail until fixed |
| Authoritative requirement not built (`NB`) | 11 | Documents the requirement |
| Ready from verified current behavior (`READY-VCI`) | 213 | Write as Draft |
| Exploratory charters / investigations (`EXP`, `CHK`) | 41 | No oracle by design |
| **Blocked by a decision (`BLK`)** | **69** | **Cannot be written yet** |

**The highest risks** (section 5), all scored CRITICAL: scheduled jobs that are not installed and not monitored; backups that are not verified and not copied off-server; institution purge that leaves personal data and files; the new, uncommitted grade-override and sign-off chain; quiz attempt races and view-as side effects; unvalidated assessment authoring; web security (CSRF only just enabled, no security headers); and tenant isolation resting on PHP checks with no database backing.

**Defects with a known expected outcome** (section 10): 15 candidates (12 verified by code reading, 3 reported by an audit agent; none executed), including the dean / institution-admin lesson link that still passes a slug as an item id, purge that deletes nothing but the institution row, backups with no read-back, no deploy step that installs the cron jobs, and 23 role checks that use account groups instead of role-in-course. A further 13 anomalies have no settled expected outcome and wait for a decision.

**What is needed from you before detailed test-case generation** (full list in the register, section 1): confirm the decisions document as authoritative (PLN-01); settle quiz validity and running-attempt rules (AMB-ASM-02..05, -09); confirm the grade scale and weight rule (AMB-ASG-11, -02); settle sign-off reopening (AMB-ASG-04); term-close rules (AMB-INST-01/02); future-dated announcements (AMB-COM-01); late penalty (AMB-ASG-01); which actions need the password again (AMB-AUTH-05); audit retention (MISS-OPS-01); user hard-delete (PLN-02); and authorization to run a baseline on a throw-away database (PLN-05).

## 2. Scope and objectives

### 2.1 In scope

Everything in the inventory: authentication and session security; authorization and tenancy; the system administrator area (institutions, subscriptions, plans, users, flags, settings, audit, backups, monitoring); the institutional administrator area (structure, terms, offerings, enrolment, people, CSV upload, parent links, branding, defaults, security overrides, reports, export, SIS); course content; assessments, quizzes, question bank, import/export and the AI builder; assignments, grading, gradebook, override requests, finalization and sign-off; discussions, announcements, calendar, notifications, file serving; the dean, chairperson, parent and student areas; the data model, migrations and seeds; the frontend shell; build, deploy and CI; scheduled jobs.

Environments: local, CI, E2E database, staging. Production: smoke checks only.

### 2.2 Out of scope (this plan)

- Running anything, writing the cases themselves, or editing the existing catalog (all later steps).
- Performance and load testing: no performance requirement exists anywhere in the repository (the only numbers are system-status thresholds and the export sizing in decision 12.1). Recorded as a gap, to be planned when targets exist.
- Penetration testing beyond the probes listed in the security approach (7.8).
- Third-party behavior: Groq's model quality, SMTP provider delivery, the VPS provider. Interfaces are tested with fakes; live checks are manual on staging and depend on PLN-07.
- Production data.

### 2.3 Objectives

| # | Objective | Measure | Target date |
|---|---|---|---|
| O1 | Record every unresolved behavior and obtain a decision for the ones that block P1 scenarios | Blocked P1 scenarios | End of Wave 1 |
| O2 | Cover the nine CRITICAL-zone risks with scenarios that have an authoritative or confirmed expected result | % of CRIT-module `READY-IBR`, `REG`, `NB` scenarios written | End of Wave 1 |
| O3 | Establish the first test baseline (PHP, JS, Playwright, Python) | Baseline recorded | Before Wave 1 (PLN-05) |
| O4 | Give every defect with a known expected outcome a regression test written before the fix | `REG` scenarios with a test | End of Wave 1 |
| O5 | Bring the existing catalog in line with plan 4 and the decisions without losing IDs | Stale cases rewritten | Wave 1 (PLN-03) |
| O6 | Move the 81 Draft cases to `Confirmed` or `Rewritten` | % confirmed | End of Wave 3 |

_Dates are relative because capacity is unknown (PLN-08); section 12 gives the sequence._

## 3. Sources of truth

Priority order when sources disagree:

1. **Authoritative requirements** (IBR): `docs/audit-2026-10-23-decisions.md`; catalog cases marked `Confirmed by Shuay`. _Assumption: the decisions document is the current requirement; confirmation is the first item in the register (PLN-01)._
2. **Documented design** (evidence of intent, not authority): `docs/roles-and-permissions.md`, `docs/sis-integration.md`, `docs/course-access.md`, `docs/institutional-admin-area.md`, the design spec `docs/superpowers/specs/2026-10-23-audit-fixes-design.md`, plan 4.
3. **Code** (the thing under test, never an oracle on its own).
4. **Existing tests** (evidence of what was once believed correct; they can encode defects).

The `docs/audit-2026-10-23.md` findings are the project's incident and near-miss history for risk scoring, but its "what exists" statements are older than the code (inventory 0.3).

## 4. Test levels, types and ownership

Taken from what exists. **[A]**

| Level | What it validates | Tool | Location | Today |
|---|---|---|---|---|
| Unit (PHP) | Pure logic with no database | PHPUnit | `tests/unit` (72 files) | ~631 methods |
| Service / integration (PHP) | Services against a real MySQL test database | PHPUnit (`DatabaseTestCase`; refuses a database name without "test") | `tests/database` (171 files) | ~2,865 methods |
| Route / feature (PHP) | HTTP routes, filters, views | PHPUnit (`RouteTestCase`) | `tests/feature` (119 files) | ~1,340 methods |
| Unit (JS) | Browser modules in jsdom | Vitest | `tests/js` (43 files) | ~339 tests |
| End to end | Real browser against a served app on `mentora_e2e` | Playwright (Chromium desktop only) | `tests/e2e` (15 specs) | ~110 tests |
| Worker | Python pipeline | pytest | `python-question-builder-service/tests` (15 files) | not counted |
| Static analysis | Types, style, secrets, dependencies | PHPStan level 5, ESLint, gitleaks, `composer audit`, `npm audit` | CI | gates |
| Manual / exploratory | Everything above cannot judge: wording, flow, a11y by a person | the catalog + charters | `docs/test-cases/` | 98 cases |
| Accessibility | WCAG 2 A/AA automated | axe in Playwright | dean, institution, admin screens | partial |
| Security | CSRF, access, redaction | PHPUnit | scattered | partial; no XSS, header or upload-attack tests |
| Performance | none | none | none | **gap, no requirement** |

**Ownership** is not documented anywhere and is to be named (PLN-08). Proposed split: developers own PHPUnit and Vitest for rules they change; QA owns the catalog, Playwright journeys and exploratory charters; whoever fixes a `REG` scenario's defect owns turning it green.

## 5. Risk assessment

Method: Impact (1–5) × Probability (1–5), zones CRITICAL 15–25, HIGH 10–14, MEDIUM 5–9, LOW 1–4. The scores below are the **planner's proposals from the evidence shown**. The skill expects them to be scored with stakeholders; they should be reviewed, and the register in 5.1 re-scored if the owners disagree. Churn is high everywhere (the repository is three months old: 464 of 480 commits), so it ranks only relatively.

### 5.1 Risk register

| ID | Risk area (modules) | I | P | Score | Zone | Evidence for the scores |
|---|---|---|---|---|---|---|
| R-01 | Authentication, sessions, account states (AUT, REA) | 5 | 2 | 10 | HIGH | Well covered by PHPUnit (lockout, 2FA, reauth, resets). Open: AMB-AUTH-01 login-recording, no rate limits (MISS-AUTH-03), `LoginService.php` empty [V] |
| R-02 | Authorization and tenant isolation (RBA) | 5 | 3 | 15 | CRIT | 19 filters, `Routes.php` is the most-changed file (90 edits in 3 months), permission layer new and staged, tenancy checked in PHP only (no database constraint), route sweep test exists |
| R-03 | Subscription lifecycle and read-only enforcement (ROM, SUB) | 4 | 3 | 12 | HIGH | Dates now drive access immediately (D:6.1) which limits the cron dependency; boundary logic complex; suspend/restore semantics open (AMB-OPS-01/02) |
| R-04 | Scheduled jobs and missed-job alerting (JOB, MON) | 4 | 5 | 20 | CRIT | **Cron not installed by repo or deploy [V4]**; every notification trigger hangs off one job; only that job monitored; `parentsNotifyAt` absent [V5]; D:6.2/D5 not built |
| R-05 | Backups and recovery (BAK) | 5 | 4 | 20 | CRIT | No read-back, no good-only pruning, no off-server copy, uploads not backed up [V]; a stuck `running` row blocks future backups |
| R-06 | Institution purge, retention, personal data (INS) | 5 | 4 | 20 | CRIT | Purge deletes only the institution row; accounts, files, export zips remain [V]; compliance context unknown (PLN-09) |
| R-07 | Grade calculation and finalization (GBK, FIN) | 5 | 3 | 15 | CRIT | Grades are the product; engine heavily tested (~36 + ~50 sheet + ~30 finalization); default scale is a placeholder (AMB-ASG-11); weight rule open (AMB-ASG-02) |
| R-08 | Override and sign-off chain, chair/dean assignment (OVR, SGN, CHR) | 5 | 4 | 20 | CRIT | **New, uncommitted code (42 staged files)**; documentation task undone; concurrency paths; catalog cases stale (PLN-03); seeded chair outside the new uniqueness [V12] |
| R-09 | SIS integration and grade export (SIS) | 4 | 3 | 12 | HIGH | Strong service tests, **no end-to-end**, depends on the sign-off gate (staged); file connector only |
| R-10 | Quiz taking and attempts (QUZ) | 4 | 4 | 16 | CRIT | GET creates attempts with no transaction [V8]; view-as and instructor-group bypass; many open rules (AMB-ASM-03..09); `QuizAttemptServiceTest` is thorough |
| R-11 | Assessment authoring, question bank, rescoring (ASM, QBK) | 4 | 4 | 16 | CRIT | No server validation [A]; editing questions rescored finished attempts; 12 import formats and hostile-file exposure; `AssessmentService` 1,706 lines, 11 edits |
| R-12 | AI question generation (AIQ) | 3 | 4 | 12 | HIGH | External provider receives source text (DOC-19); default path still the legacy service; non-atomic accept; no quality baseline; `AiWorkerEndpointsTest` env-dependent |
| R-13 | Uploads and file serving (FIL) | 4 | 3 | 12 | HIGH | Access matrix well tested; no real-file upload tests, no scan, no type whitelist for assignments |
| R-14 | Course content, prerequisites, progress (MOD) | 3 | 4 | 12 | HIGH | `ModuleService` is 2,546 lines with 16 edits in 3 months; dean/institution lesson link crashes [V Routes 371/749] |
| R-15 | Assignments and grading (ASG, GRD) | 4 | 3 | 12 | HIGH | Tested; chair-as-instructor suspected gap [V line 40]; no late penalty (V3) |
| R-16 | Parent portal privacy (PRT, PAR) | 5 | 2 | 10 | HIGH | Dedicated authorization matrix and privacy canary tests; data is about students (possibly minors, PLN-09) |
| R-17 | Notifications and email delivery (NOT) | 3 | 4 | 12 | HIGH | Nothing fires without the cron; SMTP defaults block admin sign-in; real delivery unverifiable here |
| R-18 | Academic structure, terms, offerings, enrolment, people (STR, TRM, OFF, ENR, PPL) | 4 | 3 | 12 | HIGH | Term close is destructive; role-conflict rules not built; cross-institution offering has no DB guard |
| R-19 | CSV upload (CSV) | 3 | 3 | 9 | MED | Strong tests; preview retention open |
| R-20 | View-as (IMP) | 4 | 3 | 12 | HIGH | Non-GET blocked; GET side effects not (D:3.2) [V8] |
| R-21 | Web security: CSRF, XSS, headers, rate limits (SEC) | 5 | 3 | 15 | CRIT | CSRF only just enabled (D:4); `Filters.php` 19 edits; no CSP/XFO/HSTS [V7]; rich-text and import surfaces; no XSS tests |
| R-22 | Discussions and announcements (DSC, ANN) | 3 | 3 | 9 | MED | Tested; moderation gate partly unverified; future-dated post and duplicate-row questions |
| R-23 | Calendar, reminders, schedule (CAL) | 2 | 3 | 6 | MED | Low impact; no schedule route test |
| R-24 | Dean and chairperson monitoring (DEA, CHA) | 3 | 3 | 9 | MED | Calculations unread; chair e2e has no catalog cases |
| R-25 | Data model integrity (DAT) | 4 | 3 | 12 | HIGH | Reversed FK actions [V10]; non-strict SQL; tied migration timestamps [V11]; many PHP-only invariants |
| R-26 | Platform administration: flags, settings, users (FLG, SET, USR) | 4 | 2 | 8 | MED | Tested; audit-retention setting does nothing [V1] |
| R-27 | Data export (RPT) | 3 | 4 | 12 | HIGH | Synchronous zip build vs 20,000-student requirement (D:12.1) |
| R-28 | Branding, academic defaults, institution security overrides (BRD, ACD, IAS) | 3 | 2 | 6 | MED | Tested; defaults feed grading |
| R-29 | Frontend shell, accessibility, responsive, student views (FE, STU) | 2 | 3 | 6 | MED | axe covers three areas only; Chromium only |
| R-30 | Build, deploy, CI (CI) | 4 | 3 | 12 | HIGH | Deploy migrations are not auto-reverted; workflows untested; uptime check does not detect missed jobs |
| R-31 | Audit log integrity (AUD) | 3 | 3 | 9 | MED | Append-only in the model only; retention no-op |

Heatmap (counts of risks per cell):

```
              Rare(1) Unlikely(2)  Possible(3)  Likely(4)  Frequent(5)
Catastrophic(5)   .    R-01 R-16    R-02 R-07    R-05 R-06     .
                                    R-21         R-08
Major(4)          .    R-26         R-03 R-09    R-10 R-11    R-04
                                    R-13 R-15
                                    R-18 R-20
                                    R-25 R-30
Moderate(3)       .    R-28         R-19 R-22    R-12 R-14     .
                                    R-24 R-31    R-17 R-27
Minor(2)          .      .          R-23 R-29       .          .
```

Zone totals: CRITICAL 9 (R-02, R-04, R-05, R-06, R-07, R-08, R-10, R-11, R-21); HIGH 14; MEDIUM 8; LOW 0.

### 5.2 Failure mode analysis for every risk scoring 10 or more

| Risk | Failure mode and trigger | Blast radius | Detection today | Current mitigation | Gap |
|---|---|---|---|---|---|
| R-01 | A locked-out person is never unlocked, or a succeeded login never resets the count (AMB-AUTH-01) | All sign-ins of that email | User report | `SecurityPoliciesTest`, `SecuritySettingsTest` | Runtime confirmation of success recording; email-variant bypass; no rate limit on token endpoints |
| R-02 | A route or API lets a role or tenant read or change another's data (new route without a filter; id guessing) | Cross-tenant data exposure | `RouteAccessTest` sweep; per-page cross-institution tests | 19 filters; 404 on non-membership | Sweep must be re-run after plan-4 routes; tenant consistency is PHP-only; no IDOR probe across all resource types |
| R-03 | An institution stays writable after its end date, or is locked early, because dates and the stored status disagree | One institution's users | `ReadOnlyModeTest`, `EffectiveStatusTest` | Effective status follows dates (D:6.1) | Boundary and zero-day settings; suspend/renew semantics (AMB-OPS-01/02) |
| R-04 | The cron is not installed or a job silently stops: subscriptions never lapse, no notification fires, backups and SIS never run | Whole platform; operational blindness | Nobody notices (only `subscriptions:sync` is health-checked) | Commands exist and are individually tested | Install step and its check (D5); missed-job alert and email (D:6.2/U2/D11) |
| R-05 | A backup is recorded good but unreadable, or only exists on the failing server; uploads are not backed up | Total data loss on server failure | Backup status row (size > 0 and exit code only) | Daily dump, 14 kept, status alert | Read-back (11.1), good-only pruning (11.2), off-server encrypted copy (U3), uploads, restore proof |
| R-06 | A purged institution's accounts, files and export zips remain | Personal-data retention breach; storage leak | None | Audit-first purge, cascade rows | Deletion of files, orphaned accounts, zips (10.1–10.4); compliance position (PLN-09) |
| R-07 | A student's grade is wrong (formula, band, weight normalisation, rounding) or changes after finalization | Individual grades, transcripts, SIS | Engine/finalization/sheet tests | ~116 named tests | Placeholder scale (AMB-ASG-11); weight rule (AMB-ASG-02); band gaps (AMB-ASG-08) |
| R-08 | A request is decided by the wrong person, twice, or stuck; a sign-off is given by someone ineligible; chair/dean state diverges | Grade integrity; SIS release | Chain, race, authority tests (staged) | Conditional UPDATEs, `open_marker` keys, ~60 tests | Uncommitted; stale catalog; unverified re-route trigger (AMB-ASG-09); reopen rules (AMB-ASG-04) |
| R-09 | A file is mis-parsed, a partial import commits, or grades are sent unsigned | Institution records; SIS | Importer/exporter/rollback tests | One transaction; dry run; ownership locks | No end-to-end; `.sig` handling; gate depends on staged code |
| R-10 | A student loses an attempt, gets extra attempts, or is scored wrongly; view-as consumes an attempt | Student grades; fairness | `QuizAttemptServiceTest`; e2e QUIZ-01..03 | Unique key on (assessment, student, attempt) | Race handling (15.1), view-as (3.1), role bypass (9.1), open rules AMB-ASM-03..09 |
| R-11 | A malformed quiz is published, or editing questions corrupts finished attempts' scores | Students taking the quiz | Rescore tests; import hardening tests | Rescore service; acknowledgement on import | No server validation (MISS-ASM-01); hostile import files; zero-question publish |
| R-12 | The provider receives sensitive source text; a bad question is accepted; quota is mis-counted | Course content privacy; question quality | Safety/job tests; pytest | Gates, verification, ownership checks | Data-handling position (DOC-19); baseline (MISS-AI-01); atomic accept; PHP↔worker contract |
| R-13 | A file is served to the wrong person, or a hostile file is stored and served | Student work, course material | `FileAccessServiceTest` (19) | 404-not-403, outside web root, nosniff | Real-file uploads; scanning; assignment type limits |
| R-14 | A student sees draft content or is locked out of published content; direct lesson link crashes | Student learning flow | `ModuleServiceTest`, e2e MOD | Visibility chain; prerequisite service | Lesson link for dean / institution admin (DEF-01); upload boundaries |
| R-15 | A late/blocked submission is mishandled; a chair cannot give feedback | Student submissions | Service tests; e2e ASG/GRD | Blocking-order logic | Chair-as-instructor (DEF-04); late penalty question |
| R-16 | A parent sees another family's child, a score, or an attachment | Student privacy | Matrix and canary tests | 404 filters, no attachments, no scores on assessments | Notifier cross-checks; file access by parent test |
| R-17 | Notifications never arrive or arrive twice | Parents, admins, requesters | `NotificationsTest` | Dedupe keys | Cron dependency; SMTP unverifiable; bell does not mark read |
| R-18 | A term close or reopen leaves enrolments/offerings inconsistent; a dual role goes undetected | One institution's academic year | Term/offering tests | Transaction + snapshot | Sign-off rule (AMB-INST-01), role-conflict rules (DEF-04) |
| R-20 | Viewing as someone saves state (quiz attempt, notification read) | The viewed person's data and audit trust | `ImpersonationTest` | Non-GET blocked | GET side effects (D:3.2) |
| R-21 | A state change without a token is accepted; script injected through user text; clickjacking | All users | `CsrfProtectionTest`, `PostFormsCarryATokenTest` | CSRF on globally except worker | XSS probes; headers; rate limits |
| R-25 | A delete cascades unexpectedly; out-of-range or mis-typed values are stored; cross-tenant rows | Grade-chain records; data quality | Migration tests | Unique keys | Reversed FK actions (PLN-02); non-strict mode; PHP-only invariants |
| R-27 | The export runs out of memory or time | Institution leaving the platform | `InstitutionExportTest` | Synchronous zip, 7-day expiry | Background job, scale (D:12.1–12.4) |
| R-30 | A deploy applies a migration that cannot be reverted; jobs missing after deploy | Whole platform | CI gates, health poll, smoke | Pre-deploy dump, health check | Job check (D5), uptime detects missed jobs (U2), environment-independent suite (D:14) |

### 5.3 Reassessment

Re-score within 48 hours of any production incident; when plan 4 is committed; when a decision in the register is recorded; when a new scheduled job or integration is added; quarterly at minimum. The churn command (skill Phase 6) is re-run each quarter.

## 6. Pyramid and coverage analysis

### 6.1 Current shape (counts are method counts from file inventories, not run results)

| Level | Methods | Share of total | Note |
|---|---|---|---|
| Unit (PHP `unit` + Vitest) | ~970 (631 + 339) | 18% | |
| Integration (`database` + `feature`) | ~4,205 (2,865 + 1,340) | 80% | Service tests use a real database, so they are the de facto unit tests of the services |
| End to end | ~110 | 2% | Chromium only, one serial story per area |
| Total | ~5,285 | 100% | Sources disagree: `docs/testing.md` says 1,735 PHPUnit tests (stale), the 2026-10-23 audit says 7,138 (data providers expand cases) |

**Shape: integration-heavy "diamond", thin at both ends.** I do not recommend rebalancing for its own sake: this application's rules live in database-backed services, so the database tests are doing the job of unit tests. The weaknesses are elsewhere:

1. **No browser coverage** for entire areas: SIS, discussions, calendar, notifications, AI builder, question import, chairperson journeys with ids, file upload.
2. **No tests at all** for web-security attacks (XSS, headers, upload abuse), maintenance mode directly, deploy workflows, the PHP↔Python contract.
3. **No baseline**, so the figures above cannot be trusted as passing.

### 6.2 Provisional targets (to be revisited after the baseline)

| Level | Today | Provisional target | How |
|---|---|---|---|
| Unit + JS | ~18% | ~18–20% | Add pure-logic tests where logic is extracted (override chain exists as the model) |
| Integration | ~80% | ~75% | Keep; add the `REG` / `READY-IBR` scenarios here first (cheapest, most reliable) |
| End to end | ~2% | 4–6% (≈ 220–320 at today's size) | Add the 12 untagged tests' cases, then the journeys with no coverage; keep the existing "one serial story" style where data depends on order |
| Manual / exploratory | 98 cases | catalog extended per section 9 | Charters for `EXP` scenarios |

Percentages sum to 100 at each row group. These are a direction, not a quota. Rule from the skill: **freeze E2E growth for logic testable below the browser**; new E2E only for journeys that cross layers (workflow matrix, Part B).

### 6.3 Coverage by risk zone (gap analysis for the nine CRITICAL risks)

Required coverage per the skill's zone table, against what exists. "Current" is the existence of named tests; adequacy is unknown until the baseline run.

| Risk | Required (CRITICAL) | Current | Gap | Scenarios | Priority | Owner / sprint |
|---|---|---|---|---|---|---|
| R-02 RBA | Unit/branch high; all boundaries; full journey + error paths; exploratory | Route sweep, matrix, filter tests, ~12 files | Plan-4 routes in sweep; IDOR probe; role-conflict rules | PS-RBA-01..14, PS-SEC-06 | P1 | TBD (PLN-08) / Wave 1 |
| R-04 JOB/MON | All boundaries; error paths; monitoring | Per-command tests; no install, no monitoring | D5, 6.2, U2, D11, seventh job | PS-JOB-01..06, PS-MON-04..05 | P1 | TBD / Wave 1 |
| R-05 BAK | Same | Service + runner tests | Read-back, pruning, off-server, uploads, restore proof | PS-BAK-01..08 | P1 | TBD / Wave 1 |
| R-06 INS purge | Same | 22 purge tests | Files, accounts, zips | PS-INS-04..07, 10 | P1 | TBD / Wave 1 |
| R-07 GBK/FIN | Same | ~116 named tests + e2e GBK | Placeholder scale, weight rule, band gaps (decisions) | PS-GBK-01..11, PS-FIN-01..05 | P1 | TBD / Wave 2 |
| R-08 OVR/SGN/CHR | Same | ~60 staged tests, e2e DEAN-04/05 stale | Rewrite stale cases; concurrency; admin paths | PS-OVR-01..15, PS-SGN-01..10, PS-CHR-01..13 | P1 | TBD / Wave 1 |
| R-10 QUZ | Same | Thorough service tests, e2e QUIZ | Race, view-as, role bypass, open rules | PS-QUZ-01..18 | P1 | TBD / Wave 1 |
| R-11 ASM/QBK | Same | Service + 5 import files | Validation, hostile files, zero-question publish | PS-ASM-01..12, PS-QBK-01..10 | P1 | TBD / Wave 2 |
| R-21 SEC | Same | CSRF + redaction tests only | XSS, headers, upload, rate limit, IDOR, fixation | PS-SEC-01..13 | P1 | TBD / Wave 1 |

HIGH-zone gaps follow the same pattern and are in the coverage matrix (Part A) by module.

### 6.4 Existing coverage by workflow

Of the 24 workflows (coverage matrix, Part B): **15** have at least one end-to-end test today (W6 and W7 are covered by cases now stale after plan 4); **2** only partly (W2 invitation: only TC-SA-05; W9 SIS import: only the CSV-upload case); **7** have none in a browser: W10 AI generation, W13 backup, W14 purge, W17 permission change, W18 upload and file access, W21 question import, W22 deploy.

## 7. Test approach by concern

For each concern: what is tested, the technique, where the expected result comes from, and the scenario ranges in the coverage matrix.

### 7.1 Modules

All 52 modules, with existing coverage, proposed scenarios, priority and wave, are in [`coverage-matrix.md`](coverage-matrix.md) Part A. General rules:

- **One module, one oracle policy.** A module's scenarios mix `READY-IBR`, `READY-VCI`, `REG`, `NB`, `BLK`, `EXP`. A case file for the module records which one each case is.
- **Service rules are tested below the browser** (PHPUnit `database`), because that is where this application keeps its rules and where the existing 2,865 tests live. A browser case is written only for what a lower level cannot judge: that the page, buttons and scripts work together, wording, flow, accessibility.
- **Every case states the demo account and data it uses** (catalog rule) and which PHPUnit test already proves the rule underneath, so it is linked, not duplicated.

### 7.2 Roles and permissions

| Role | What to test about it | Main scenarios |
|---|---|---|
| `admin` (system administrator) | Locked permissions; 2FA always; no academic access; view-as; reauth on dangerous actions; platform settings | PS-AUT-*, PS-REA-*, PS-IMP-*, PS-RBA-04, PS-SET-*, PS-USR-*, PS-BAK-*, PS-INS-* |
| `institutional_admin` | Own-institution scope; editing the five academic roles; approvals and sign-off fallback (no dean); read-only behaviour; CSV and SIS; export | PS-RBA-14, PS-OVR-04..07, PS-SGN-03..06, PS-ROM-*, PS-CSV-*, PS-SIS-*, PS-RPT-* |
| `dean` | Department reach from the live assignment; approvals and sign-off; may sign off a section they teach; follow-ups; department announcements; read-only course views | PS-DEA-*, PS-SGN-02, PS-OVR-12 |
| `chairperson` | Reach = chaired sub-departments; instructor parity (D:9.4); cannot finalize; audited writes; endorse / send back; one-chair rule | PS-CHA-*, PS-CHR-01..08, PS-GRD-05, PS-DSC-08 |
| `instructor` | Section scope; permission-gated course parts (`courses.manage`, `grading.manage`, `announcements.manage`, `discussions.manage`); grading; finalization | PS-RBA-11, PS-GRD-*, PS-FIN-*, PS-QBK-*, PS-AIQ-* |
| `student` | Enrolment scope; visibility chain; quiz and assignment rules; closed offerings; grade views; own parents visible | PS-MOD-01, PS-QUZ-*, PS-ASG-*, PS-STU-* |
| `parent` | Linked children only; portal switch; read-only tabs; privacy canary; notifier; no files | PS-PRT-*, PS-PAR-*, PS-FIL-03 |

**Technique: a role × area × action matrix**, generated from `AuthGroups::DEFAULT_MATRIX`, `APPLICABLE` and the route table, not hand-written. `RouteAccessTest` (336 checks for student + instructor alone) is the base; the plan adds the plan-4 routes and the permission-cleared variants (PS-RBA-01, -02, -11). Expected results are IBR: a wrong role is redirected to `/` (TC-AUTH-05), a non-member gets 404 (TC-ANN-02), a cleared permission makes that part read-only (D:7.4).

**Permission layers.** Test the platform layer, the institution layer on top, "stored only where it differs", "later platform changes flow through", "setting back removes the row", and the audit/undo guarantee (D:U1b, U1c, D2). Test that `grades.approve` / `grades.signoff` withdrawn from deans makes the admin stand in (D:7.2, U1e) and that the gap warning shows on both pages (D:7.3, U1f).

### 7.3 Business workflows

The 24 workflows in the coverage matrix (Part B) are the end-to-end skeleton. For each, the plan identifies the chain, the existing tests at each link, and the missing links. Principles:

- **Test each link of a chain at the lowest level that can see it, then one journey across the chain.** For W7 (override chain) the transitions are service-level; the journey is one browser case per decision path (chair → dean approves; chair → no dean → admin decides; send back → resubmit; concurrent actions).
- **Both directions of a gate.** Every gate has a "passes" and a "refuses" case (for example the SIS sign-off gate: with a dean, only signed-off sections go; with none, finalized goes; when the first dean arrives, unsent grades wait: PS-SIS-06).
- **Cases that consume data** state the fresh-seed requirement (catalog README).

### 7.4 State machines

Method: for each machine, a **transition table** (valid transitions each get a positive case; each invalid transition, a negative case), plus the **entry point** (who or what causes the transition) and the **terminal behaviour**.

| Machine | Valid transitions to cover | Invalid / absent to cover | Writers missing (flag) |
|---|---|---|---|
| Institution status | pending → active → read_only → past; suspend; renewal back | delete while active or running subscription; restore | `suspend` = `past` (AMB-OPS-01) |
| Subscription ladder | active → expiring → grace → read_only → expired at each boundary | zero-day settings | — |
| Term | upcoming ⇄ active; → completed; completed → active | edit completed; active → upcoming with enrolments; reopen with copied offering | `archived` has no writer |
| Offering / enrolment | active → completed / archived; enrolled ⇄ dropped / withdrawn / completed | archive with students; edit non-active | `dropped` / `withdrawn` writers outside admin screen not located |
| Assessment | Draft ⇄ Published; → Archived | **Closed** | `Closed` has no writer (AMB-ASM-01) |
| Submission | In Progress → Submitted → Partially Graded → Grading → Graded; reopen | double submit; save after submit | **`Returned`, `Cancelled` have no writer** |
| Learning item completion | not_started → in_progress → completed | complete a locked item | — |
| Finalization / sign-off | finalize → sign off → reopen; blocked → ready → signed_off | reopen finalization while signed off; sign off blocked | reopen wording |
| Override request | 7 states, section 5.1 of the inventory | decide own; decide after claimed; act twice | — |
| Grade adjustment | live → undone | second live per target | — |
| Chair assignment | active → revoked (reuse row) | second active chair | `expired` has no writer |
| Dean assignment | active → ended | second dean per department / person | — |
| AI batch / job | batch pending → generating → completed / failed; job queued → running → … ; requeue ≤ 2 | claim by another worker; progress after cancel | cancel path differs between `AiJobGeneration` and `AiJobService` |
| Backup | requested → running → succeeded / failed | second request while pending; stuck `running` | no timeout |
| Notification | unread → read | duplicate key | — |
| Invitation / reset (derived) | pending → accepted / used; revoked; expired | reuse; replaced | — |

States with **no writer** are probes (`EXP`) or blocked questions, never positive cases. Scenarios: PS-TRM-*, PS-QUZ-*, PS-OVR-*, PS-SGN-*, PS-CHR-*, PS-BAK-*, PS-AIQ-02.

### 7.5 Data integrity

- **Database-enforced rules** (one open request / sign-off / finalization / adjustment / follow-up; unique attempt; one enrolment; one chair; one dean; one institution per account): each gets a positive case and a "second one is refused" case, and the **concurrent** variant where an application check precedes the insert (PS-DAT-02, PS-QUZ-05, PS-CHR-07).
- **PHP-only invariants** (capacity, tenant consistency, weight sums, band gaps, date ordering, student number uniqueness, prerequisite cycles): each is a probe (`EXP`) until the owner says whether the database should enforce it (MISS-DB-01). The 16-item probe list is in the inventory, 14.2.
- **Cascades and FK actions**: the reversed `SET NULL, CASCADE` columns are tested against the **intended** behavior only after PLN-02 (user hard-delete) is decided.
- **Migrations**: apply from empty (CI already does), apply on a copy holding representative data, the six tied timestamps, `down()` of the staged migration, re-run (PS-DAT-01). These run on a copy only.
- **Seeds**: idempotency, id-dependence, demo admin refused in production (PS-DAT-06).
- **Non-strict SQL mode**: over-long strings truncated and invalid enums coerced (PS-DAT-03).

### 7.6 Integrations

| Integration | Test double / method | Live check | Notes |
|---|---|---|---|
| SMTP (admin 2FA, invitations, resets, urgent, parent emails) | `FakeEmail`, `tests/e2e/support/mail-sink.php` | manual on staging (PLN-07) | Outside development a mail failure **blocks** admin sign-in: a case in each direction |
| SIS file connector | `FakeSisConnector`, `SisFixture`, `writable/sis` folders | manual with a sample folder | Add the CLI/E2E path (W9) which has none; `.sig` handling |
| AI worker + provider | `QB_BACKEND=fake`, `FakeBackupRunner`-style fakes, pytest | manual smoke with a real key on staging only | Test the PHP↔worker HTTP contract (none today); privacy position of source text (DOC-19, PLN-09) |
| Legacy Ollama service | HTTP stub | n/a | Still on the default path (`useJobs=false`) |
| mysqldump / gzip | `MysqldumpRunnerTest`, `FakeBackupRunner`, `fake-dump.php` | restore proof on a copy (PS-BAK-04 once built) | |
| GitHub Actions deploy / uptime | none | read the workflows (`CHK`) | Plan checks, not automated tests |
| Browser libraries (tiptap, pdf.js, sortable) | Vitest + Playwright | real browser charters | rich-text XSS is a P1 probe |
| Payment provider | **none exists** | n/a | Billing is record-keeping; nothing to integrate-test |

### 7.7 Scheduled jobs

| Job | What to test | Scenarios |
|---|---|---|
| `backup:run` | takes requested rows; adds a daily one if none succeeded within 23 h; read-back and pruning when built; no command-level test today | PS-BAK-*, PS-JOB-01 |
| `subscriptions:sync` | ladder moves down only; sets the heartbeat; **runs every notification trigger**; dry run | PS-SUB-01, PS-NOT-02, PS-JOB-04 |
| `institutions:purge-expired` | retention cutoff, dry run, actor label, per-institution failure isolation | PS-INS-07 |
| `parents:notify` | each event, dedupe, portal off, dry run counts; heartbeat (not built) | PS-PRT-03, PS-JOB-06 |
| `sis:sync` / `sis:grades` | gates, per-institution skip, exit code on failure | PS-SIS-*, PS-JOB-01 |
| Seventh (background export) | built per D:12 | PS-RPT-03, PS-JOB-05 |
| Install + monitoring | the deploy installs and checks all jobs (D:D5, 6.4); missed jobs alert (D:6.2, U2, D11) | PS-JOB-02, PS-MON-04, PS-MON-05 |

**Method.** Run each command on the test database with dates moved by SQL (as TC-SA-06 does), assert the effects and the exit code, then run it a second time and assert **no duplicates** (idempotency, PS-JOB-03). The scheduling itself (cron) cannot be tested from the repository; it is a deploy check (`CHK`).

### 7.8 Security

| Area | Technique | Oracle | Scenarios |
|---|---|---|---|
| CSRF | Every state-changing route without token → refused with the stated message and typed input kept; test fails if protection is disabled; sign-out only by button; only `internal/ai/*` exempt | IBR D:4 | PS-SEC-01..03 |
| Access control / IDOR | Role × area × action matrix; id guessing across institutions for every resource type returns 404 | IBR TC-ANN-02, TC-AUTH-05 | PS-RBA-*, PS-SEC-06 |
| Authentication | Lockout, 2FA, idle, session end, reset/invitation lifecycle; no account enumeration | IBR TC-AUTH-03; VCI | PS-AUT-*, PS-AUT-18 |
| Stored XSS | Probe every user-text surface (names, announcements, discussions, rubrics, banner, audit values, rich text, imported questions) as every viewing role | probe | PS-SEC-04, PS-MOD-14 |
| Injection / formula | CSV and spreadsheet exports defuse `= + - @`; SQL via search/filter parameters | VCI + probe | PS-SEC-05 |
| Uploads | MIME vs extension, size boundaries, executable names, polyglots, zip bombs, XML entities in import, storage outside web root, `nosniff` | probe + VCI | PS-SEC-07, PS-QBK-08, PS-MOD-07/08 |
| Headers / transport | CSP, `X-Frame-Options`, HSTS, cookie flags (currently off) | **UNR** (MISS-AUTH-02) | PS-SEC-08 (blocked) |
| Rate limiting | Token endpoints, reauth | **UNR** (MISS-AUTH-03) | PS-SEC-09 (blocked) |
| Secrets | No password/token/key in audit, logs, errors | VCI `AuditRedactionTest` | PS-SEC-10, PS-SEC-13 |
| Worker API | 503 / 403 / 401 paths; ownership | VCI | PS-SEC-11 |
| Dependencies | `composer audit`, `npm audit --omit=dev`, gitleaks (CI) | CI | CI gates |
| Privacy | Parent privacy canary; purge deletes personal data; AI egress | IBR D:10; PLN-09 | PS-PRT-04, PS-INS-04..06, PS-AIQ-11 |

No scanner is installed by this plan. A baseline dynamic scan (for example OWASP ZAP) against staging is a candidate for Wave 3 once PLN-07 settles where it may run.

## 8. Review of the existing catalog

The catalog was reviewed in place and **not modified**.

### 8.1 Location and structure

| Path | Lines | Cases | Format |
|---|---|---|---|
| `docs/test-cases/README.md` | 111 | n/a | Rules, priority scale, case fields, demo accounts and courses, reseed warning, how to add and review |
| `docs/test-cases/p1-critical-journeys.md` | 378 | 17 | One `### TC-…` block each: Role, Priority, Data, Steps, Expected, Already automated, Playwright, Basis, Review, optional Note / Clarification / Correction |
| `docs/test-cases/p2-dean.md` | 317 | 11 | Same block layout, all `Review: Draft`, Playwright `tests/e2e/dean.spec.js` |
| `docs/test-cases/p2-gradebook.md` | 155 | 5 | Same layout, plus a table "What the PHP tests settle" for rules with no browser case |
| `docs/test-cases/p2-institutional-administrator.md` | 113 | 43 | **A table of titles only** for 37 cases (TC-STR … TC-HOM); their steps and expected results live in `tests/e2e/institution-structure.spec.js`. Six `TC-IAA-01..06` cases have short H2 blocks (Steps, Expected, Already automated) |
| `docs/test-cases/p2-parent.md` | 179 | 10 | Block layout, all Draft, `tests/e2e/parent.spec.js` |
| `docs/test-cases/p2-system-administrator.md` | 102 | 12 | Compact blocks (Steps, Expected, Already automated); **no Role / Priority / Basis / Review per case**; file-level "All are Draft" |
| **Total** | 1,355 | **98** | |

### 8.2 IDs

`TC-<AREA>-<NN>`, never reused. In use: AUTH 01–05, ANN 01–02, MOD 01–02, QUIZ 01–03, ASG 01–02, GRD 01–03 (P1: 17); DEAN 01–11; GBK 01–05; STR 01–06, CRS 01–03, TRM 01–03, OFF 01–03, ENR 01–03, PPL 01–04, PAR 01, UPL 01–02, CPY 01, BRD 01–03, ACD 01, SEC 01, RPT 01, VCV 01, SUB 01, AUD 01, VAS 01, HOM 01, IAA 01–06 (43); PRT 01–10; SA 01–12. **No ID collisions.** `TC-IAA-07` (dark mode) exists in the spec but not in the document. SA-05 and SA-06 appear in the institution file only as cross-references.

### 8.3 Status and priority

| | Confirmed by Shuay (6–7 Oct 2026) | Draft |
|---|---|---|
| P1 | 17 | 0 |
| P2 (every other file) | 0 | 81 |
| P3 | 0 | 0 |

The README says the reviewer sets `Confirmed` only after running the case on staging and agreeing the expected result. So **only the 17 P1 expectations are authoritative (IBR)**; the 81 Draft cases are characterizations of what the code did on the day they were written (VCI). Several P1 cases carry "(wording to confirm)" for on-screen text.

### 8.4 Automation linkage

Every P1 case names its Playwright spec (`auth-login`, `announcements`, `modules`, `quizzes`, `assignments`, `grading`), except TC-AUTH-05 (`no`, fully covered by `RouteAccessTest`). Each P2 file maps to one spec. **12 Playwright tests belong to no catalog case:** `assessment-editor.spec.js` (4), `chairperson.spec.js` (5), `monitor-program.spec.js` (3). "Already automated" references name PHPUnit tests by file and method; they drift when tests are renamed and are not machine-checked.

### 8.5 Coverage against the inventory

| Area | Catalog coverage | Gap |
|---|---|---|
| Sign-in, roles, announcements, modules, quizzes, assignments, grading | P1, confirmed | Edge rules (lockout, reset, invitation lifecycle) rely on PHPUnit only |
| Dean | 11 Draft | Pre-plan-4 for approvals and sign-off |
| Gradebook | 5 Draft | Scheme, sheets, finalize, INC rules in PHPUnit only |
| Institution administrator | 43 Draft | No SIS, no approvals/sign-off, no dean assignment, no export at scale |
| Parent | 10 Draft | Notifier is PHPUnit only |
| System administrator | 12 Draft | No backups run, flags, settings, plans, purge |
| **None at all** | | Chairperson (tests untagged), discussions, calendar and reminders, notifications, file upload/serving, student profile / dashboard, assessment authoring, question import/export, rubrics, AI builder, SIS, backups, flags, platform settings, plans, purge, read-only mode (beyond IAA-06), impersonation of a quiz, security (CSRF, XSS, headers), password reset and invitation acceptance (beyond SA-05), deploy / jobs, responsive and dark mode beyond the three audited areas |

### 8.6 Duplicates and overlaps

None is a true duplicate (IDs differ and each states its own role), but these repeat a rule and should share one matrix:

| Overlap | Cases | Underneath |
|---|---|---|
| "Only the right role can open the area" | TC-AUTH-05, TC-SA-02, TC-IAA-01, TC-DEAN-08, TC-PRT-08 | `RouteAccessTest` (one sweep) |
| "A record outside my tenant answers not found" | TC-IAA-02, TC-DEAN-08, TC-PRT-03, TC-PRT-04, TC-ANN-02 | per-page cross-institution tests |
| View-as | TC-SA-08 (system admin), TC-VAS-01 (institution admin) | `ImpersonationTest`, `InstitutionViewAsTest` |
| Read-only course view | TC-DEAN-03, TC-VCV-01 | `DeanCourseViewTest`, `InstitutionCourseViewTest` |
| Read-only institution | TC-IAA-06, TC-DEAN-11, TC-SA-06 | `ReadOnlyModeTest` |
| Accessibility by role | TC-DEAN-10, TC-IAA-04, TC-SA-10 | axe per area (by design) |
| Keyboard-only dialog | TC-DEAN-09, TC-IAA-05, TC-SA-12 | `dialog.test.js` |
| Password in a dialog | TC-SA-12, TC-PPL-03, TC-TRM-03, TC-UPL-01 | `ReauthService` / dialog tests |
| Student's view of a grade | TC-GRD-03, TC-GBK-02/03 (student half), TC-PRT-02 | grade view services |

### 8.7 Stale or contradicted by an authoritative decision

| Case | Problem | Authority |
|---|---|---|
| TC-CRS-03 "a chairperson is assigned to a sub-department and removed again" | Predates exactly-one-chair with confirmed replacement | D:16.0, 16.0b |
| TC-DEAN-04 "the chairperson endorses it, the dean decides it" | Does not cover the institution admin deciding, nobody deciding their own, the dean-less path | D:1.1–1.6, U5 |
| TC-DEAN-05 "only the dean can reopen the sign-off" | Administrator sign-off and its reopen are now a path | D:2.4, 2.5, D3, D4 |
| TC-SA-06 lapses the subscription then runs `subscriptions:sync` | Access now follows dates without the job; the case can drop the job and add a variant | D:6.1 |
| TC-ANN-02 "(UI to confirm)" | Server refusal already tested; only the UI check remains | DOC-23 |
| README "P1 only", area list | Out of date | DOC-13 |

### 8.8 Quality observations

- Two formats coexist; the system-administrator file omits the per-case fields (Role, Priority, Basis, Review) the README requires.
- 37 cases keep their steps only in test code, so a person executing manually has no written procedure (the catalog's own purpose).
- "Basis" cites the code the expected result was read from; for the 81 Draft cases that is the only source, so a defect in the code is silently the expected result.
- The P1 file's "Gaps this draft found" records a **crashing lesson address "Not fixed"** (PLN-04) and a note that the timing of a real-versus-unknown-email refusal is untested.

### 8.9 Recommendations (no change made)

1. **Keep all 98 IDs.** Do not renumber.
2. **Add an `Oracle` field to every case** (`IBR:<source>` / `VCI` / `Blocked:<id>`) and a `Decision` field linking the register, so a Draft that encodes a defect cannot be mistaken for a requirement.
3. **Extend with new files**, not edits to the old ones: a regression file for confirmed defects, plus chairperson, course authoring (assessments, question bank, AI), communication (discussions, announcements, calendar, notifications), security, operations (backups, jobs, purge, flags, settings) and SIS.
4. **Rewrite the stale cases** (8.7) after plan 4 is committed, keeping their IDs (PLN-03).
5. **Collapse the repeated role-boundary and tenant-boundary checks** into one generated matrix case each, keeping one spot-check per role.
6. **Write the 37 institution cases' procedures into the document**, or state that the spec is the procedure.
7. **Link "Already automated" mechanically** (a script that checks the named tests exist).

## 9. Case development rules

1. **One scenario → one case, one source of truth for the expected result.** Take the oracle from the backlog status. If the status is `BLK`, write a stub case with `Review: Blocked – <decision id>` and **no expected result**.
2. **`READY-IBR`:** copy the requirement's wording into `Basis` (decision number or confirmed case) and write the expected result from it. Mark `Review: Draft` until run, then `Confirmed` per the README.
3. **`READY-VCI`:** write the expected result from the verified behavior, mark `Oracle: VCI – characterization`, and add the line "the owner has not confirmed this is the intended behavior".
4. **`REG` / `REG?`:** write the expected result from the requirement. Add `Known defect: DEF-nn` and "expected to fail until fixed; not run". Add the regression test **before** the fix is merged.
5. **`NB`:** write what the requirement says; add `Status: not built`. The case cannot be executed; it is a specification.
6. **`EXP`:** a charter (goal, timebox, areas, heuristics), no expected result. Findings become new scenarios.
7. **`CHK`:** a task in the schedule, not a case.
8. **Do not claim a pass.** A case's `Result` stays empty until it has been executed by a person or the automation, with the run recorded. Until the baseline (PLN-05) exists no document may say a test passes.
9. **Data:** state the demo account and course, the fresh-seed need, any SQL used for states the app has no screen for (and that it is put back).
10. **IDs:** allocate the next free `TC-<AREA>-<NN>` when a case is written; record the `PS-` id it came from in `Basis`. New areas use the codes in the coverage matrix.
11. **Review:** an AI-authored case is reviewed by a person against the oracle before it is `Confirmed`. The skill's authoring trade-off applies: AI cuts writing time but adds review time (budgeted in section 12).
12. **Test smells:** 30 minutes per sprint reviewing automated tests against the `ai-qa-review` taxonomy.

## 10. Defect and regression register

These are **defect candidates found by reading the code**. None has been confirmed by running the application. "Verified" means I read the code that does (or does not do) the thing. A regression scenario is written first and is expected to fail until the defect is fixed.

### 10.1 Defects with a known expected outcome (an authoritative requirement says what is right)

| ID | Defect | Evidence | Expected outcome (source) | Scenarios | Class |
|---|---|---|---|---|---|
| DEF-01 | Dean and institution-admin direct lesson links pass the slug as the item id, so the controller receives a string for an `int` parameter and the page errors (audit finding 5) | **[V]** `Routes.php:371` and `:749` pass `$1/$2/$3`; `ModuleController::viewLearningItem(int, int)` | The link opens the course page on that lesson, read-only, drafts marked; a deleted lesson, another course's lesson and a course out of reach show the same friendly "not found" page (D:5.1–5.3) | PS-MOD-10 | REG |
| DEF-02 | Opening a quiz while viewing as a student creates a real `In Progress` attempt | **[V]** `QuizAttemptService::startAttempt` inserts on a GET; view-as blocks non-GET only | Landing information only; no attempt started; an attempt in progress shown read-only; nothing saved on the viewed person's behalf by opening any page (D:3.1, 3.2) | PS-IMP-02, PS-IMP-03 | REG |
| DEF-03 | A second tab opened at the same moment hits a duplicate-key error instead of resuming the same attempt | **[V]** code counts then inserts with no transaction or duplicate handling; runtime outcome not run | Resumes the same attempt: same timer, same saved answers; last save wins; once one tab submits the other can change nothing (D:15.1, 15.2) | PS-QUZ-05, PS-QUZ-06 | REG |
| DEF-04 | What someone may do in a course is decided by account group, not role in that course; the planned conflict rules do not exist | **[V]** 23 `inGroup('instructor'/'student')` checks in 12 files; no `CourseMembership::roleIn`, no `CourseRoleConflicts` | Role-in-course governs; no one is both student and instructor in one course; chair is an instructor of every course in the program; deans and institution admins cannot be students in their courses; existing conflicts auto-fixed (D:9.1–9.7, D7, D10) | PS-QUZ-07, PS-ENR-04..07, PS-RBA-13 | REG / REG? |
| DEF-05 | Institution purge deletes only the institution row (and cascades); files, accounts, export zips remain | **[V]** `InstitutionService::purge` has no file or account deletion; only a count of orphaned members | Purge deletes files (row and disk) except those another institution uses, accounts belonging to no other institution with profile / notifications / reminders / files, and the export zips; audit entries kept (D:10.1–10.5) | PS-INS-04, -05, -06 | REG |
| DEF-06 | A backup counts as good on exit code and size > 0; no read-back; pruning counts every file | **[V]** no read-back in `app/Services/Backup`; pruning **[A]** | Successful only once the whole file reads back to the end, else failed, file deleted, admins warned and emailed; only proven-good backups count toward the 14 kept (D:11.1, 11.2) | PS-BAK-02, PS-BAK-03 | REG |
| DEF-07 | No off-server backup copy; uploads are not backed up | **[V]** `docs/backup.md:62` "left"; no code | Encrypted copy to a different provider after every good backup, production dumps and uploads, 30 days, append-only; failed copy warns; one copy fetched back and opened before go-live (D:11.4, U3) | PS-BAK-04, PS-BAK-05 | NB |
| DEF-08 | The deploy does not install or check the scheduled jobs | **[V]** no `cron` step in `.github/workflows` (only the hourly uptime schedule) | The deploy installs all jobs, checks they are present, and fails only if that check fails (D:D5, 6.4) | PS-JOB-02 | NB |
| DEF-09 | Only `subscriptions:sync` is monitored; no alert or email for any missed job; `parents:notify` does not record a heartbeat | **[V]** `parentsNotifyAt` absent from `app/`; monitoring reads one heartbeat **[A]** | A missed job shows a warning on the admin home page and sends one email to all system admins, and one on recovery; the hourly uptime check reports it (D:6.2, U2, D11) | PS-MON-04, PS-MON-05, PS-JOB-06 | NB |
| DEF-10 | The institution export is built synchronously in the request | **[A]** `ZipArchive` in the export service; no job | Handles 20,000 students and 5 years in the background; one at a time per institution; only the requester is notified; a seventh scheduled job (D:12.1–12.4, 6.4) | PS-RPT-03, PS-JOB-05 | NB |
| DEF-11 | No way to enter a section's class times; no SIS class-time dataset | **[V]** only `OfferingCopyService` touches `course_schedules`; the SIS reads five datasets | Admin enters weekly pattern on the offering form; clash warns but saves; SIS-owned times are locked; the class-times file is the whole timetable (D:13.1–13.5, D8, D9, U4) | PS-OFF-06, -07, -08 | NB |
| DEF-12 | The seeded chairperson has no `active_marker`, so the unique key does not cover it | **[V]** no seeder sets the marker | Exactly one active chair per sub-department (D:16.0) | PS-CHR-06 | REG |
| DEF-13 | A chairperson who is not also an instructor may be refused when posting feedback | **[V]** `AssessmentFeedbackService` line 40 `$isInstructor = $user->inGroup('instructor')`; behavior not run | A chairperson counts as an instructor of every course in their program (D:9.1, 9.4) | PS-GRD-05, PS-DSC-08 | REG? |
| DEF-14 | The PHP suite depends on the local `.env` (the AI worker secret and four more files) | **[A]** audit finding 14; 6 `AiWorkerEndpointsTest` failures recorded | The suite passes whatever a developer's `.env` contains; CI also runs with a filled sample `.env` (D:14.1, 14.2) | PS-AIQ-13, PS-CI-01 | REG? |
| DEF-15 | Deleting others' discussion posts and marking an answer may not require `discussions.manage` (the route filter gates only pin and lock) | **[A]** `CoursePermissionFilter` rules; `DiscussionService` unread | The permission covers pin, lock, delete others' posts, mark the answer (D:D6) | PS-RBA-12 | REG? |

12 of the 15 are verified by code reading; 3 are agent-reported. None has been executed.

### 10.2 Defect candidates whose expected outcome needs a decision

These are anomalies in the code with **no authoritative statement** of what is right. They are blocked or probed, not asserted.

| ID | Anomaly | Evidence | Needs |
|---|---|---|---|
| DEF-16 | Reopen error says only "the dean" can reopen even when an administrator signed off | **[A]** `GradeFinalizationService::SIGNED_OFF_MESSAGE` | AMB-ASG-04 |
| DEF-17 | Reversed foreign-key actions delete dependent rows when the creator is deleted | **[V]** 7 columns, 6 migrations | PLN-02 |
| DEF-18 | `auditRetentionDays` is editable and does nothing | **[V]** V1 | MISS-OPS-01 |
| DEF-19 | A multi-role author's post appears twice in the course feed | **[A]** join on `auth_groups_users` without a group filter | AMB-COM-03 |
| DEF-20 | A malformed id to `notifications/read` marks everything read | **[A]** | AMB-COM-02 |
| DEF-21 | Accepting a generated question is check-then-update, not atomic | **[A]** | none (probe PS-AIQ-05) |
| DEF-22 | Learning item type is not whitelisted | **[A]** | probe PS-MOD-09 |
| DEF-23 | Maintenance open-path match is loose | **[A]** | AMB-AUTH-09 |
| DEF-24 | No server validation for assessments and questions | **[A]** | MISS-ASM-01 |
| DEF-25 | Five PHP files are 0 bytes: `UserModel.php`, `LoginService.php`, `UserService.php`, views `app-menu.php`, `filter-dropdown.php` | **[V]** V6 | cleanup decision |
| DEF-26 | Six assessment actions (publish, unpublish, archive, restore, duplicate, statistics) have no route | **[V]** V9 | PS-ASM-06 |
| DEF-27 | `AssessmentSubmissionModel::$allowedFields` still lists the dropped `feedback` column | **[A]** | cleanup |
| DEF-28 | The assignment enrolment check matches the course, not the offering | **[A]** | AMB-ASG-03 |

## 11. Environments and test data

| Environment | Use | Data | Constraints |
|---|---|---|---|
| Local (XAMPP, MySQL root, empty password) | Developer runs; the QA baseline; `mentora_test` for PHPUnit; `mentora_e2e` for Playwright (dropped and recreated every run, port 8089) | Demo seed (`php spark db:seed DatabaseSeeder`), date-relative, reseed erases the database | A `.env` with real SMTP must be pointed at a sink for QA; `npm run build` needed outside development |
| CI (GitHub Actions) | Per-PR gates | Ephemeral MySQL 8.4 service | The suite must not depend on a local `.env` (DEF-14) |
| Staging | Manual execution of the catalog; deploy rehearsal; live SMTP and provider checks | Reseeding requires the team's agreement (README); TLS check is insecure by default | Which environment runs manual cases is undecided (PLN-07) |
| Production | Smoke checks only (`/health`, login, assets, anonymous redirect) | Live | No QA data |

**Test data needs the plan adds**

- **A second tenant.** Institutions 2–5 are seeded empty. Tenant-isolation cases need a populated second institution: use the `tests/_support` factories for PHPUnit and the onboarding flow (as TC-SA-04 creates "E2E College") for browser cases.
- **Time control.** Everything is date-relative. Move dates with SQL as TC-SA-06 does, or set the clock in PHPUnit. Never rely on "today".
- **Mail sink.** `E2E_MAIL_SINK` / `FakeEmail` so 2FA codes, invitations and resets can be read without sending.
- **SIS folder.** A sample inbound folder per institution; `FakeSisConnector`.
- **AI.** `QB_BACKEND=fake` for browser smoke; real provider only on staging with a key.
- **Cron harness.** A script that invokes each command twice (idempotency) on the test database; the cron schedule itself is a deploy check.
- **Roles for a full matrix.** A dual-role person (chairperson2, dean2) and a chair who is not an instructor (needed for DEF-13) are not both seeded; create one.

## 12. Estimation, waves and schedule

Method (test-planning): decompose, estimate by test type from the skill's reference table, add setup and review, allocate **70–80% of capacity**, reserve **20–30% as a named buffer**, order by risk then effort. Estimates are for planning only and must be calibrated after the first wave.

### 12.1 Waves

| Wave | What | Scenarios | Estimated hours |
|---|---|---|---|
| **Prep (Week 0)** | Baseline run and triage (8); fixtures: second tenant, mail sink, command harness (16); rewrite stale catalog cases (6); catalog restructure: oracle field, matrix consolidation, link checker (8); decision review preparation (6) | n/a | 44 |
| **1** | All `REG`, `REG?`, `NB` (32); `READY-IBR` in CRITICAL modules (37); `EXP` / `CHK` in CRITICAL modules (13) | 82 | 151 |
| **2** | `READY-VCI` / `READY-IBR` P1 in CRITICAL and HIGH modules; their `EXP` / `CHK` | 123 | 108 |
| **3** | Remaining P2, MEDIUM modules; remaining `EXP` | 117 | 111 |
| **4** | `BLK` (69, as decisions arrive), P3 and the rest | 91 | 33 |
| **Total** | | **413** | **≈ 447** |

Hours are made of: authoring ≈ 91, review ≈ 51, new automation ≈ 156, exploratory / investigation execution ≈ 58, setup and data ≈ 47, plus the 44 prep hours. Not scheduled: **unblocking** the 69 blocked scenarios once decisions land, ≈ 62 hours (≈ 0.9 hour each).

### 12.2 Capacity and buffer

447 planned hours is 75% of **≈ 596 hours** of capacity; the remaining **≈ 149 hours (25%) is the named buffer**, held per person, for re-testing fixed defects, decision changes and surprises. Assuming 30 test hours per person per week (an assumption; capacity is unknown, PLN-08), that is ≈ 20 person-weeks, about **10 weeks for two people**.

### 12.3 Sequence (illustrative, two people)

| Weeks | Work | Gate to proceed |
|---|---|---|
| 0 | Prep; the decision review (register section 1); baseline run authorized (PLN-05) | Baseline recorded, PLN-01 answered |
| 1–3 | Wave 1: write regression and not-built specifications; add regression tests; exploratory charters on SEC, BAK, JOB, QUZ | Every Wave-1 `REG` has a test; no CRITICAL `BLK` remains for Wave-1 modules |
| 4–5 | Wave 2 | HIGH-module P1 scenarios written |
| 6–7 | Wave 3 | Draft P2 cases confirmed or rewritten (O6) |
| 8–10 | Wave 4 and unblocking; reassess risk | Buffer consumption reviewed |

Critical-path rule: no one person is the only cover for a CRITICAL module; each assignee carries their own buffer line.

## 13. Entry and exit criteria, quality gates

### 13.1 By stage

| Stage | Entry | Exit |
|---|---|---|
| **Catalog case written** | Scenario status is not `BLK`; oracle named; demo account and data named | Reviewed by a second person against the oracle; `Review: Draft` or `Confirmed` |
| **Automated test added** | Case exists or the test is its own specification; a failing run proves it can fail (for `REG`) | Passes (or is explicitly expected-fail with `DEF-nn`); no skip; reviewed for test smells |
| **Wave** | Previous wave's gate met; decisions for this wave recorded | Every `READY-*`, `REG`, `NB` scenario of the wave is a case; every `EXP` charter run with findings logged; every `CHK` closed |
| **QA sign-off for a release** (not claimable today) | A recorded baseline; all CRITICAL-module `REG` fixed or accepted by the owner; no open P0 defect | Smoke passes on staging and production; rollback plan verified |

### 13.2 Gates that already exist (CI) **[A]**

PHP lint 8.2 / 8.5; composer validate and audit; PHPStan level 5; PHPUnit on 8.2 (coverage ≥ 86.5% floor, diff coverage ≥ 80% on PRs) and 8.5; assets (`npm audit` high, lint, Vitest, build); Playwright; gitleaks; Python pytest; the single `ci-passed` gate. Deploy: environment check, pre-deploy dump, migrate, worker restart, `/health` poll, smoke test. Uptime workflow hourly.

### 13.3 Gates this plan proposes (not implemented)

| Proposed gate | Why |
|---|---|
| The route sweep (`RouteAccessTest`) includes every route added by a change | The most-changed file is `Routes.php` |
| A pull request that touches a CRITICAL module updates or adds a scenario in the coverage matrix | Keeps the matrix alive |
| A `REG` scenario has a test before its fix merges | Objective O4 |
| The number of `BLK` scenarios older than one wave is reported | Decisions do not rot |
| JS coverage threshold (none exists) | Browser code is untested for authoring surfaces |
| Deploy fails if the scheduled jobs are missing; uptime check reports missed jobs | Decisions D5 and U2 |

## 14. Metrics and KPIs

**All targets are provisional.** There is no baseline, so none can be judged yet; they become binding after the first run.

| Metric | Definition | Target | Cadence | Baseline |
|---|---|---|---|---|
| Wave progress | Scenarios in the wave written as cases (excluding `BLK`) / scenarios in the wave | 100% at wave exit | Weekly | 0 |
| Open decisions | Number of `BLK` scenarios | Falling; ≤ 10% of the backlog after Wave 3 | Weekly | 69 (17%) |
| Requirement-backed automation | `READY-IBR` + `REG` + `NB` scenarios with an automated test, in CRITICAL modules | ≥ 80% | Per wave | unknown |
| Regression-first | `REG` scenarios with a test before the fix | 100% | Per fix | 0 |
| Catalog confirmation | Draft cases confirmed or rewritten | 100% of P1 (already); ≥ 50% of the 81 Draft by Wave 3 exit | Per wave | 17 of 98 |
| PHP coverage | Existing floor | ≥ 86.5% maintained; diff ≥ 80% | Per PR | 86.92% (6 Oct, **[D]**) |
| Flakiness | Non-deterministic failures per run | < 2% | Weekly | 1 flaky Vitest observed (audit) |
| Defect escape | Defects found after release / all found | < 5% per release | Per release | unknown |
| CI duration | Push to green | PR < 15 min; full < 30 min | Weekly | unknown |
| Suite independence | Suite passes with an arbitrary `.env` | pass | Per PR | not met (DEF-14) |
| Estimation accuracy | Actual / estimated hours per test type | within ±30% after Wave 1 | Per wave | n/a |
| Buffer consumed | Buffer hours used | ≤ 100% by plan end; alert at 70% | Weekly | 0 |

## 15. Ownership, reassessment, revision history

- **Owner of this plan:** to be named (PLN-08). Proposed: QA lead; a developer lead for the `REG` fixes; the product owner for the register.
- **Review cadence:** quarterly; plus on any trigger in 5.3.
- **Re-evaluation triggers:** plan 4 committed; a decision recorded; a new job or integration; a production incident; a change of team; a new institution type.

| Version | Date | Change | Author |
|---|---|---|---|
| 0.1 | 2026-10-26 | First draft from the project inventory | Claude (planning step) |

## 16. Verification of this plan

The skills' "done when" checks, applied to this plan:

| Check | Result |
|---|---|
| Every in-scope module scored (impact, probability) | 52 modules map to 31 scored risks (coverage matrix Part A has the mapping) |
| Every risk scoring ≥ 10 has a failure-mode block with trigger, blast radius, detection, mitigation, gap | 23 of 23 (section 5.2) |
| Zones named with a testing action | Section 5.1 and the skill's zone table |
| Coverage gap worksheet for CRITICAL risks | Section 6.3 (9 of 9) |
| Pyramid shape and provisional targets | Section 6 |
| Entry / exit criteria; gates; tool rationale | Sections 4, 13 (tool choice: **no new tools proposed**; existing PHPUnit / Vitest / Playwright / pytest are kept) |
| Estimates with a named buffer (70–80% planned) | Section 12 (75% / 25%) |
| Coverage matrix has no unexplained gap | Every module and workflow has coverage and decisions listed; blocked items name their decision |
| Metrics each have a target and cadence | Section 14 |

**Assumptions and limits.** Decisions document treated as authoritative (PLN-01). Test counts are by file inventory, not execution. Scores are the planner's. Effort figures are skill defaults pending calibration. Capacity unknown (PLN-08). The code areas listed in inventory section 14.3 were not read by any audit agent; statements about them are UNVERIFIABLE.

**What was not done, by instruction.** No test case was written; no test was run; no application code, database, deployment or the existing catalog was modified; nothing was staged, committed or pushed.
