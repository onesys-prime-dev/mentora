# Test strategy

Approved 2026-11-23 with `implementation-plan.md` (gate 3 → 4).

## Environment
- PHP: `env 'database.tests.port=3307' 'database.tests.hostname=127.0.0.1' vendor/bin/phpunit` (scratch MariaDB :3307; XAMPP :3306 broken, not repaired). See `../group-activities/test-strategy.md`.
- JS: `npm test` (Vitest, jsdom, `tests/js/**`). Lint: `npm run lint`.
- E2E: `npm run e2e` (Playwright, `tests/e2e/messages.spec.js`, own DB `mentora_e2e` on :8089). Recorded as **NOT RUN** when it cannot run locally.

## Layers
| Layer | Where | What it proves |
|---|---|---|
| Unit (no DB) | `tests/unit/PartyTest.php`, `MessagingPolicyTest.php`, `NoActiveRoleInMessagesTest.php` | Capacity (D4); the full role-pair matrix (AC1); no `active_role` (AC3) |
| Database (services) | `tests/database/*` (resolver, blocks, reset migration, drafts, first contact, limits, notifier, restrictions, pause, review by person, minor flag, settings mapping, quiet mode, groups, read-only threads, recipient search) | Rules on real data, migrations up/down with counts, audits |
| Feature (HTTP) | `tests/feature/Messages*`, `InstitutionMessagesSettingsTest`, `InstitutionPeopleMinorTest`, `MessagesIdorTest` | Filters, CSRF, 404-not-403, identical refusals, settings and People routes |
| Frontend | `tests/js/pages/messages-*.test.js` | Draft flow, first-contact line, notices, switches, group dialog, paused blocks |
| End to end | `tests/e2e/messages.spec.js` | Critical journeys per role in a browser |

## Acceptance criteria → tests
| AC | Tests (owning task) |
|---|---|
| AC1 role-pair matrix | `MessagingPolicyTest` provider 7×7 + context variants (7.1) |
| AC2 multi-role | `PartyTest`, `multiRole*` (1.2, 7.1) |
| AC3 no active role | `NoActiveRoleInMessagesTest` (1.3), `testPartyIgnoresSavedActiveRole` (1.2) |
| AC4 drafts | `ConversationDraftTest` incl. concurrency (3.1) |
| AC5 first contact + limits | `FirstContactTest`, `MessageRateLimiterTest` (3.2), minor case (5.1) |
| AC6 restriction / removal / pause | `RestrictionServiceTest`, `AutomaticPauseTest` incl. class-brigading, `ReportReviewByPersonTest` (4.1-4.3) |
| AC7 groups | `GroupServiceTest`, `GroupMinorRulesTest`, `MessagesGroupRoutesTest` (6.1-6.2) |
| AC8 quiet hours | `MessagePreferencesTest`, `MessageNotifierTest` (5.3) |
| AC9 migrations | `ResetNonBindingBlocksTest`, `MapStudentDmSettingTest`, quiet-mode backfill (2.1, 5.2, 5.3) |
| AC10 data intact | full regression every phase + integrity counts (7.4) |

## Regression rule
Every phase ends with the whole PHP suite and the whole Vitest suite green, not only the new tests. Counts are recorded below. A failing test keeps the work in its phase (root cause, fix, rerun), and the failure is logged here.

## Results
| Phase | PHP | JS | Lint | E2E | Date |
|---|---|---|---|---|---|
| baseline (main 37a9c5f9, before Phase 1) | 10629 tests / 42895 assertions OK (MySQL :3306, 37m43s) | 127 files / 1306 tests OK | not run | not run | 2026-11-25 |
| Phase 1 (feat/messages-open-p1-parties, uncommitted) | full suite: 10638 tests, 42909 assertions; 10626 passed, 12 failed only in `CheckDiffCoverageScriptTest` (11) and `SmokeTestScriptTest` (1) with exit code -1073741502 (Windows process start failure under low memory; the run's wrapper was killed by Claude Code for low memory at the same time). Both files rerun alone: OK (11 + 19 tests). Filtered Messages suites: 2917 OK. phpstan clean on changed files. | 127 files / 1306 tests OK | eslint clean | NOT RUN (no UI change in Phase 1) | 2026-11-26 |
| Phase 2 (feat/messages-open-p2-blocks) | full suite: 10680 tests / 43025 assertions OK (36m08s); incl. ResetNonBindingBlocksTest (counts add up, down() restores, abort path) | 127 files / 1308 tests OK | eslint clean | NOT RUN (no local e2e server) | 2026-11-26 |
| Phase 3 (feat/messages-open-p3-drafts) | full suite: 10718 tests / 43186 assertions OK (33m49s); incl. FirstSendRaceTest (two connections) | 127+ files / 1320 tests OK | eslint clean | NOT RUN (no local e2e server) | 2026-11-26 |
| Phase 4 (feat/messages-open-p4-restrictions) | full suite: 10745 tests / 43363 assertions; 12 failed only in `CheckDiffCoverageScriptTest` (11) and `SmokeTestScriptTest` (1) with exit -1073741502 (low memory; the wrapper shell was reaped); both files rerun alone OK (11 + 19). Incl. pause/expiry race tests on a second connection | 1329 tests OK | eslint clean | NOT RUN (no local e2e server) | 2026-11-26 |
| Phase 5 (feat/messages-open-p5-minors) | full suite: 10811 tests / 43595 assertions; 1 failure (InstitutionCsvUploadTest template expectation, stale after the new `minor` column) fixed and its file rerun OK (23) | Vitest not changed in this phase (1329 at Phase 4) | eslint clean | NOT RUN (no local e2e server; e2e reset helper updated) | 2026-11-26 |
| Phase 6 (feat/messages-open-p6-groups) | full suite: 10847 tests / 43698 assertions; 2 failures fixed (course chat `closed` overwritten by the group close flag; group settings dialog missing from the responsive bottom-dialog list), files rerun OK (88) | Vitest 1348 passed | eslint, phpstan clean | NOT RUN (no local e2e server) | 2026-11-26 |

## Failure log
- 2026-11-26 Phase 5: CsvUploadServiceTest failed only in file order: a deliberately failing apply left the connection's transStatus false and DatabaseTestCase reset it only for isolated classes; fixed in the shared base (pre-existing hazard). Review found 4 Important (failed CSV row still set the flag, stuck flag after role removal, invalid UTF-8 byte, e2e reset); fixed. Full run: 1 stale template expectation, fixed.
- 2026-11-26 Phase 6: first full run errored on every test because MySQL stopped (low memory, no clean shutdown in its log); restarted, rerun. Real failures: the group `closed` flag replaced the course-closed flag in ConversationPresenter (now OR'd), and the new group settings dialog was not listed in StaffModalsResponsiveTest's bottom dialogs (added, like messages-group). Implementer once ran `php spark migrate` on the dev DB (additive schema only, 0 rows touched; reversible by rollback).
- 2026-11-26 Phase 4: whole-phase review found 6 Important (concurrent pause start, double expiry audit, removal not reaching open threads, no lift route, per-row restriction lookups, status card re-announced). Fixed before the full run; the full run's shell was stopped for low memory, PHPUnit finished; 12 environmental script-test failures, green alone.
- 2026-11-26 Phase 3: whole-phase review found 1 Critical (concurrent first sends: second sender got a 500 under REPEATABLE READ) and 5 Important; all fixed before the full run (first run stopped as stale). Two implementer runs were stopped by the user mid-task (one by accident) and resumed.
- 2026-11-26 Phase 2: whole-phase review found 3 Important (reset ignored a failed transaction; protected-link sentence unreachable; inbox poll queries). Fixed before the full run; first full run stopped as stale.
- 2026-11-26 Phase 1: the first full run was stopped on purpose after the whole-phase review (results would be stale). The second full run's wrapper was killed by Claude Code for low memory; the PHPUnit process kept running to the end. 12 script-test failures under memory pressure, confirmed environmental by rerunning both files alone (green). Review findings and fixes are in the SDD ledger (Task 1.2 fix round, Task 1.3 fix round, Phase 1 fix wave).

- 2026-11-23 (Stage 2, docs): a script inserted decisions before a heading the D2 edit had removed, so D3-D24 never reached `decision-register.md`. Found by the builder check; register rewritten from the session record; no content changed. Lesson: assert the anchor exists before every scripted insert (now done).
