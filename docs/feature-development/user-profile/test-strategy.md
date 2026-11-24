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
| AC1 pages, redirects | `ProfilePageTest`, `SettingsNotificationsTest`, `ProfileRouteSecurityTest` |
| AC2 FR2 matrix + D31 | `ProfilePolicyTest` (unit), `PersonProfileRoutesTest` (HTTP, absence asserts) |
| AC3 parent / other institution | `PersonProfileRoutesTest`, `ProfilePhotoRoutesTest` |
| AC4 photos | `ProfilePhotoServiceTest`, `ProfilePhotoRoutesTest`, `ProfilePhotoFlowTest` |
| AC5 corrections, email | `CorrectionRequestServiceTest`, `EmailChangeServiceTest`, `ProfileCorrectionFlowTest` |
| AC6 password | `PasswordChangeServiceTest`, `SettingsPasswordTest` |
| AC7 2FA | `TwoFactorPreferenceTest`, `AdminEmail2FA` tests |
| AC8 preferences preserved | `SettingsNotificationsTest` |
| AC9 avatars everywhere | Phase 3 + 7 page tests, `person-card.test.js`, `profile.spec.js` |
| AC10 no regression | full suites each phase |

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
