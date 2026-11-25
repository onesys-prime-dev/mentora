# User Profile: test strategy

## Approach
- TDD per task (superpowers:test-driven-development): write the failing test, run it and see it fail for the right reason, implement, run it green. Red runs are recorded in the phase results. No phase closes with a failing or skipped test.
- Layers: pure unit tests for `ProfilePolicy` and `RoleRank` (`tests/unit/Profiles`); service and schema tests on the DB (`tests/database`); route, permission and page tests through `Tests\Support\RouteTestCase` (`tests/feature`); Vitest for JS modules (`tests/js`); Playwright journeys at 360/768/1440 px with axe (`tests/e2e`) where the e2e DB is available.
- Privacy tests assert **absence**: a hidden value (email, course name, bio) is not anywhere in the response body.
- Regression: every phase runs the full `phpunit` and `vitest`, plus the existing suites of code it touches (header/dashboard, Messages, parent portal, People, SIS import, CSV upload, 2FA, password reset).
- Data preservation: Phase 1 proves existing `user_profiles` rows are byte-identical after migration; Phase 5 proves existing message and parent preferences are unchanged.
- Quality gates per phase: phpstan on changed PHP, eslint on changed JS, `ui-ux-design.md` states checklist, spec + code review (subagent-driven-development reviewers), `ponytail-review`/`code-review` on the phase diff.

## Commands
- PHP: `env 'database.tests.port=3307' 'database.tests.hostname=127.0.0.1' vendor/bin/phpunit [--filter <Name>]`
- JS: `npx vitest run [path]`, `npx eslint resources/js`
- Static: `vendor/bin/phpstan analyse <paths>`
- E2E: `npx playwright test <spec>` (needs the e2e DB; see the note below)

## Acceptance criteria → tests
| AC | Covered by |
|---|---|
| AC1 pages, redirects | `ProfilePageTest`, `SettingsNotificationsTest`, `ProfileRouteSecurityTest`; e2e `profile.spec.js` TC-PRF-01..05 |
| AC2 FR2 matrix + D31 | `ProfilePolicyTest` (unit), `PersonProfileRoutesTest` (HTTP, absence asserts); e2e TC-PRF-13 (hover, modal, page) |
| AC3 parent / other institution | `PersonProfileRoutesTest`, `ProfilePhotoRoutesTest`, `ProfileContextResolverTest`; e2e TC-PRF-23 (parent's child 200 without contact, unlinked student 404) |
| AC4 photos | `ProfilePhotoServiceTest`, `ProfilePhotoRoutesTest`, `ProfilePhotoFlowTest`; e2e `profile-photo.spec.js` TC-PRF-10..12 |
| AC5 corrections, email | `CorrectionRequestServiceTest`, `EmailChangeServiceTest`, `ProfileCorrectionFlowTest`, `ProfileModerationTest`, `ProfileClearFieldRoutesTest`; e2e TC-PRF-15, 16 (request, approve, link, new sign-in), 21 (clear), 22 (home department) |
| AC6 password | `PasswordChangeServiceTest`, `SettingsPasswordTest`; e2e TC-PRF-17 |
| AC7 2FA | `TwoFactorPreferenceTest`, `SettingsSecurityTest`, `AdminEmail2FA` tests; e2e TC-PRF-18; sign-out-elsewhere TC-PRF-19 |
| AC8 preferences preserved | `SettingsNotificationsTest`; e2e TC-PRF-20 |
| AC9 avatars everywhere | Phase 3 + 7 page tests (`MessagesAvatarsTest`, `GroupAvatarsTest`, ...), `person-card.test.js`, `AvatarComponentTest`; e2e `profile-card.spec.js` TC-PRF-06..09 |
| AC10 no regression | full phpunit and vitest each phase; Phase 8 Playwright regression (parent, messages, menu-placement, institutional-admin); axe and overflow TC-PRF-25, 26 |

## Local environment
XAMPP MySQL is broken (do not repair). Tests run on the scratch MariaDB on port 3307 (start command in `docs/feature-development/group-activities/test-strategy.md`). Playwright e2e resets the DB on :3306 and cannot run until that is fixed or the support scripts take a port; record e2e as NOT RUN when so.

## Known pre-existing failures (do not count against a phase)
TC-DEAN-03/04, TC-GGR-04, TC-PRT-02, TC-MSG-06, TC-MSG-08 (e2e); a stale calendar Vitest. Re-check at Phase 1 start and update this list with the baseline numbers.

## Results log
_Filled per phase: counts, red runs shown, NOT RUN items, known limits._

### Baseline (2026-11-24, before Phase 1)
phpunit 10152 tests OK (scratch DB :3307); vitest 121 files / 1243 tests OK. The stale calendar Vitest failure listed below no longer fails.

### Phase 1 (2026-11-24) — feat/user-profile-p1-foundation
- phpunit full suite: 10229 tests / 40721 assertions OK (main MySQL :3306, `mentora_test`). New: ProfileSchemaTest 4, RoleRankTest + ProfilePolicyTest 54 (unit), ProfileReaderTest, AvatarComponentTest 7.
- vitest: 122 files / 1247 tests OK (new tests/js/shared/avatar.test.js 4). eslint clean; phpstan on app/Services/Profiles + components/avatar.php: no errors.
- RED runs shown for every task (class-missing errors; placeholder still rendered; new policy rows failing).
- Data preservation: `testExistingProfileRowsSurviveMigration` seeds on the old schema, migrates, compares every old column (assertSame); rollback test passes.
- Reviews: four task reviews + one whole-phase review (fix wave: roles shown per viewer, `ProfileReader::forViewer` single entry point, admin/chair/dean role details, system admin own role) + re-review, all addressed.
- NOT RUN: Playwright (no UI journey yet in Phase 1); no browser screenshot of the header/dashboard avatar.
- Known limits: a child opening their own deactivated parent gets 404 instead of basics (hides, not leaks); `pronouns` key is present as null when not granted (renderers must use the field list).

### Phase 2 (2026-11-24) — feat/user-profile-p2-my-profile
- phpunit (run in three parts because of memory): unit 1270, database 4377, feature+session 4643 — 10290 tests OK. New: ProfilePageTest, ProfileEditServiceTest, ProfileEditRoutesTest; ProfileReaderTest extended.
- vitest 123 files / 1252 OK (tests/js/pages/profile). Two calendar tests fail only under heavy load and pass alone (known flaky).
- Playwright: tests/e2e/profile.spec.js 11/11 (TC-PRF-01..05 + screenshots at 360/768/1440, no horizontal overflow); parent.spec + menu-placement 10/11 (TC-PRT-02 known pre-existing).
- RED runs shown for 2.1, 2.3 and the fix wave; Task 2.2's tests were written after the code (no RED) — mutation reasoning in review showed the rules are pinned.
- Reviews: three task reviews (+ fix rounds) and a whole-phase review; fixes: parent page kept until Phase 5, header-name memo flushed between test classes, Studies/Contact/Official split, avatar overlap, section codes, empty fields hidden from viewers.
- Known limits: parent/profile old page and its notification/password forms remain until Phase 5; photo checklist item arrives in Phase 4; responsive snapshot baseline for student-profile is stale until the next responsive run.

### Phase 3 (2026-11-24) — feat/user-profile-p3-people
- phpunit (in parts): unit 1270, database 4381, feature 4678 — all OK at 3d8ae0f3; after the review fix wave (8328869b) feature 1458+3220 and `--filter "Profile|AvatarData"` 235 OK.
- vitest 1273 OK (person-card.test.js 19+). Playwright: profile.spec + profile-card.spec 15/15 incl. axe on the card, the announcement card and the dialog.
- New: PersonProfileRoutesTest (AC2 matrix through HTTP for page/card/panel with absence asserts; 404s for other institution, unlinked parent/child, missing id, system admin; deactivated; Message gating incl. two shared children and read-only); ProfileContextResolverTest (visibleIds agreement matrix 16 viewers x 17 subjects; ≤8 queries for 40 ids); AvatarData forViewer hides preferred name/photo of hidden people.
- Reviews caught: Message button promising a refused conversation (shared resolveDirectStart), popover unreachable by keyboard, Escape reopening the card, hidden people's preferred names in avatar labels (Critical, fixed), expired-session login page cached into the card.
- Known limits: no e2e on discussion avatars; popover name read from the avatar's aria-label; deactivated avatars keep their colour.

### Phase 4 (2026-11-25) — feat/user-profile-p4-photos
- phpunit (in parts): unit 1271, database 4395, feature 4710 — 10376 OK. vitest 125 files / 1283 OK.
- Playwright: profile-photo.spec 3/3 (real upload through the dialog, photos off/on, admin removal + bell), profile + profile-card 15/15.
- New: ProfilePhotoServiceTest (content sniffing, 5 MB, pixel cap, EXIF stripped, orientation, 256/64 squares, replace/remove, audit), ProfilePhotoRoutesTest (Review Focus 3: 404 after off/removal/forbidden viewer, same photo after on, no-cache + ETag/304), ProfilePhotoFlowTest (owner/admin flows, institution switch, checklist, cross-institution 404), photo-crop.test.js.
- Migration 2026-12-02-000004 widens mail_outbox.kind with `photo` (additive, reversible).
- Known limits: route-level upload success is covered by e2e only (is_uploaded_file); no-JS upload not possible; a person in two institutions follows their first institution's photo switch (same as the existing resolver).

### Phase 5 (2026-11-25) — feat/user-profile-p5-settings
- phpunit (8 parts + unit; tests need `-d memory_limit=2G` because AdminTwoFactorPagesTest reads the day's log file): 10422 OK. vitest 126 files / 1282 OK (a few timing-sensitive tests fail only under load, pass on rerun).
- Playwright: TC-PRT-06 (parent email choices now in Settings) and TC-MSG-12 pass; TC-PRT-02 known pre-existing. responsive.spec `parent-profile` now redirects to /profile — its committed CI baseline will differ until regenerated on CI.
- New: PasswordChangeServiceTest, SettingsPasswordTest (all 7 roles, Review Focus 5 two-session test, throttles, read-only, CSRF), TwoFactorPreferenceTest, SettingsSecurityTest, SettingsNotificationsTest (AC8 byte-identical preferences, forged child id ignored, redirects), SecurityActivityTest, SecurityNoticeTest.
- Retired: ParentProfileController + view, Messaging PreferenceController (GETs 301, old POSTs 303 without acting).
- Known limits: remember-me stops working after any sign-out-everywhere (secure; Phase 8); no email notice for a reset done through a link; jump list has no current-section highlight.

### Phase 6 (2026-11-25) — feat/user-profile-p6-corrections
- phpunit (unit + 8 parts): 10524 OK. vitest 126 files / 1285 OK.
- New: CorrectionRequestServiceTest, EmailChangeServiceTest (Review Focus 6: expired, reused, other signed-in user, newer link, taken address), ProfileCorrectionFlowTest (W4 end to end, Requests tab badge, escalations, system admin direct edit, read-only, CSRF, public email-change GET side-effect free), ProfileModerationTest, ProfileClearFieldRoutesTest; extended PeopleServiceTest, UserAdminServiceTest, SisImporterTest, CsvUploadServiceTest, SecurityActivityTest, MailOutboxTest.
- Migration 2026-12-02-000005 widens mail_outbox.kind with email_changed and field_cleared (additive, reversible).
- Reviews closed: approval with no profile row, system admin profile stayed minimal (C5), home department select staff-only, import notices queued, D26 notice not muted, own official details editable only via the correction flow, pending email links cancelled by direct sets.
- NOT RUN: Playwright journey for corrections (Phase 8). Known limits: one-open-request rule enforced in code only; a request stays routed to an institution even if its other admins later leave.

### Phase 7 (2026-11-25) — feat/user-profile-p7-avatars
- phpunit (unit + 8 parts): 10568 OK. vitest 127 files / 1301 OK.
- Avatars (gated through AvatarData::forViewer, one lookup per page/response): data tables (People, institution and admin audit logs, Users, chairperson review), announcements (cards, replies, composer), discussions, assessment and learning-item headers (real author/instructor bylines; the old hard-coded "Jalen Brunson / June 22" stub removed), Grade Work, dean monitor (at-risk + audit), dashboard at-risk (non-interactive: the row is a link), institution roster and section roster, Messages (list, thread head, bubbles), group lists and group grading. Gradebook rows have none (asserted).
- Preferred names in social places (preferred first + legal last only for viewers who may see the person); official places stay legal (grade sheet export, SIS export, gradebook — tested).
- Fixed in review: a button inside the "Reply in thread" button (reply was broken), audit avatars in the wrong column, duplicate lookups.
- Deferred (names only, no leak): done in follow-ups F1 (Messages: pickers, "who reacted", notifications, parent feed, message reports) and F2 (group generator preview, parents.php chips, dean teaching load, chairperson workload and monitor-program, follow-ups, announcement index/dashboard bylines, grades at-risk, activity feed names; group, at-risk and preview lists sort by the shown name). Still open: a student's "classmates you can invite" list (legal names, no avatar); institution roster/section lists keep their paged legal-name order; the activity feed names people but draws no avatars (it has no place for them).
- Responsive screenshot baselines to regenerate on CI (GitHub Actions → Responsive → update_snapshots): student/chairperson/dean messages (+dialogs), student-course-announcements, instructor-announcements, student-module-item, student-item-reading, chairperson-course-module, dean-assessment-view, instructor-grade-work (+graded), chairperson-grade-work, instructor-dashboard, chairperson-dashboard, dean-chairperson-review, dean-monitor-college, institution-people, institution-audit, institution-enrolments, admin-audit-log, admin-users, admin-institution-audit, group screens, student-profile, parent-profile.

### Phase 8 (2026-11-25) - feat/user-profile-p8-hardening
- phpunit (unit + 8 parts, `-d memory_limit=2G`): 10591 OK at 12eef1a6; PHPStan (whole project) and ESLint clean after 361944b5; MailOutbox follow-up b76bfa28 re-tested (MailOutbox/SisImporter/GroupNotifier 96 OK). vitest 127 files / 1301 OK.
- Task 8.1: ProfileRouteSecurityTest — 36 routes x 16 callers (anonymous, each role, other institution, viewing as someone, read-only institution, missing CSRF) against an exact status table; found and fixed one gap (cross-institution photo upload answered 422 before the scope check). Hardening: remember-me tokens deleted on sign-out-everywhere/deactivate and remember-me sign-ins set the session start (custom app/Auth/SessionAuthenticator); session id regenerated after password change and sign-out-others; throttles on sign-out-others and correction submissions (10/hour); email notice after a password reset through a link; large photos downscaled before rotation.
- Task 8.2: Playwright journeys TC-PRF-13..26 in `tests/e2e/profile-journeys.spec.js` (W1-W13, axe on /profile, /settings, /people/{id}, the modal and the Requests tab: no serious/critical violations; no horizontal overflow at 360/768/1440). profile*.spec 34/34. Regression parent/messages/menu-placement/institutional-admin 42/44: TC-PRT-02 known pre-existing; TC-IAA-01 is timing-sensitive locally (passes alone in 25.7 s against the local 30 s limit; CI allows 90 s).
- Final whole-branch review: no Critical; CI blockers (PHPStan, ESLint) fixed.
- Still to do by the user: regenerate the responsive screenshot baselines on CI (GitHub Actions → Responsive → update_snapshots); screens listed under Phase 7.
