# Coverage matrix

| | |
|---|---|
| **Date** | 2026-10-26; updated 2026-10-27 after the reviewer sign-off (register Part F) |
| **Branch** | `docs/qa-test-cases`, fast-forwarded on 2026-10-28 to `ae86042` (the code under test; written against `27f2012`, then updated, see Part F) |
| **Status** | **All decisions made (2026-10-28), see Part G.** Planning. No test has been run; **no baseline exists**, so nothing here claims that any existing test passes. **Updated after the sign-off:** 66 of the 69 blocked scenarios were released with a new status (they are still not test cases and no `REG` / `REG?` has been confirmed by running anything). 3 stay blocked and 1 new blocked scenario (PS-DAT-09) was added. See "Sign-off update" in Part D. |
| **Inputs** | [`project-inventory.md`](project-inventory.md), [`decision-register.md`](decision-register.md), [`qa-test-plan.md`](qa-test-plan.md), the existing catalog in `docs/test-cases/` |
| **What this maps** | Every discovered module (52) and every end-to-end workflow (24) to: existing coverage, proposed additional scenarios, priority, evidence, and the unresolved decisions that block parts of it. |

## Conventions

**Evidence labels** (same as the inventory): **[V]** verified by me directly in the code; **[A]** read by an audit agent, not re-checked; **[D]** documented only. Test-file names are taken from file names and the inventory; **I did not read their assertions**, so "covered by `XTest`" means the test exists and targets that area, not that it passes or is sufficient.

**Behavior categories** (see the register, section 0.1): **VCI** verified current implementation behavior; **IBR** intended behavior from an authoritative requirement; **UNR** unresolved, needs a decision.

**Existing coverage columns**
- *Automated*: PHPUnit (`unit` / `database` / `feature`), Vitest (`js`), Playwright (`e2e`). "none found" means no test file name or inventory entry points at it.
- *Catalog*: existing `TC-…` cases in `docs/test-cases/` (98 cases; only the 17 P1 cases are owner-confirmed, the other 81 are `Draft`). A **stale** flag means the case predates the plan-4 decisions (see PLN-03).

**Risk and priority**
- *Risk* is the id and zone from the risk register in [`qa-test-plan.md`](qa-test-plan.md) section 5 (CRIT 15–25, HIGH 10–14, MED 5–9, LOW 1–4). Scores are proposals for stakeholder review.
- *Priority* uses the catalog's own scale: **P1** (a failure makes the release unshippable or loses data), **P2**, **P3**. A module's priority follows its risk zone: CRIT and HIGH → P1, MED → P2, LOW → P3. Each scenario carries its own priority in Part C.
- *Wave* is the order of work: **1** regressions and not-built requirements in CRIT modules, plus the decisions they need; **2** verified-behavior P1 scenarios in CRIT/HIGH modules; **3** P2; **4** blocked scenarios as decisions arrive, and P3.

**Scenario status** (Part C): `READY-IBR`, `READY-VCI`, `REG`, `REG?`, `NB`, `BLK`, `EXP`, `CHK` as defined in the introduction to Part C. `BLK` scenarios have no expected result and must not be written until the blocking decision is recorded.

**Sources of expected results after the sign-off.** The reviewer (Shuay, 26–27 Oct 2026) recorded decisions in `decision-register.md` Part F. A scenario cites them as `SO:<id>`. Where a register item is `DECIDED-OPEN`, only the decided part is an oracle: the open sub-point is named in the scenario text and is not to be guessed. Scenarios citing `D:` (the decisions document) stay **provisional** until PLN-01 records who approved that document. A `REG?` status means the current behavior is only suspected from code reading ([A]); it has not been run.

**Not in scope of this document:** writing the cases themselves, running anything, changing the existing catalog.

## Part A. Module coverage matrix

52 modules, grouped by area. "Proposed" and "Unresolved decisions" are generated from the scenario backlog in Part C, so they cannot disagree with it.

### A1. Identity, access and platform security

| Module | Scope | Existing automated coverage | Existing catalog | Risk / Pri / Wave | Proposed additional scenarios | Unresolved decisions | Evidence |
|---|---|---|---|---|---|---|---|
| **AUT** Authentication and session | login, lockout, 2FA, password policy, account states, idle timeout, invitation, reset, role switch | PHPUnit: `AuthFlowTest`, `AuditAuthEventsTest`, `AdminTwoFactor*Test`, `SecurityPoliciesTest`, `SecuritySettingsTest`, `AccountStateTest`, `InvitationLinkTest`, `PasswordResetLinkTest`, `ActiveRoleServiceTest`, `RoleSwitchTest`; e2e `auth-login.spec.js` | TC-AUTH-01..04 (confirmed), TC-SA-01, -05 | R-01 HIGH / P1 / 2 | **21** (IBR 4, VCI 11, NB 2, EXP 3, CHK 1); PS-AUT-01…21 | — (all decided 2026-10-28) | [A], [V] V7 |
| **REA** Re-authentication | password re-entry window, 5 failures, return path | `AdminReauthTest`, `InstitutionReauthTest`, `ReauthServiceTest` | TC-SA-12, TC-PPL-03, TC-TRM-03 (dialog use) | R-01 HIGH / P1 / 2 | **7** (IBR 2, VCI 4, REG? 1); PS-REA-01…07 | — (all decided 2026-10-28) | [A] |
| **IMP** Impersonation / view-as | admin and institution-admin view-as, view-only filter | `ImpersonationTest`, `InstitutionViewAsTest` | TC-SA-08, TC-VAS-01 | R-20 HIGH / P1 / 1 | **7** (IBR 1, VCI 4, FIX? 2); PS-IMP-01…07 | — (all decided 2026-10-28) | [A], [V] V8 |
| **RBA** Roles, permissions, route access | permission matrix, filters, course membership, wrong-role redirect | `RouteAccessTest`, `PermissionRouteTest`, `PermissionsTakeEffectTest`, `PermissionMatrix*Test`, `AuthGroupsConfigTest`, `CourseMembershipTest`, `CoursePermissionFilterTest`, `AdminRolesPageTest`, `InstitutionRolesPageTest` | TC-AUTH-05, TC-IAA-01..03, TC-SA-02, TC-DEAN-08, TC-PRT-08 | R-02 CRIT / P1 / 1 | **14** (IBR 11, REG? 1, FIX? 1, CHK 1); PS-RBA-01…14 | — (all decided 2026-10-28) | [A] |
| **ROM** Read-only states and maintenance | institution access states, effective status, maintenance mode | `ReadOnlyModeTest`, `ReadOnlyOwnThingsTest`, `InstitutionAccessTest`, `EffectiveStatusTest`; maintenance only through `GeneralSettingsTest` | TC-IAA-06, TC-DEAN-11, TC-SA-06, -07 | R-03 HIGH / P1 / 2 | **10** (IBR 5, VCI 2, REG? 2, NB 1); PS-ROM-01…10 | — (all decided 2026-10-28) | [A] |
| **SEC** Web security | CSRF, XSS, headers, uploads, rate limits, worker API | `CsrfProtectionTest`, `PostFormsCarryATokenTest`, `AuditRedactionTest`, `AiWorkerEndpointsTest`; no XSS, header or upload-attack tests | none | R-21 CRIT / P1 / 1 | **13** (IBR 4, VCI 3, NB 2, EXP 4); PS-SEC-01…13 | — (all decided 2026-10-28) | [V] V7, [A] |

### A2. System administrator and platform operations

| Module | Scope | Existing automated coverage | Existing catalog | Risk / Pri / Wave | Proposed additional scenarios | Unresolved decisions | Evidence |
|---|---|---|---|---|---|---|---|
| **INS** Institution lifecycle | create, suspend, delete, restore, purge, onboarding | `InstitutionServiceTest`, `InstitutionPurgeTest` (22), `OnboardingServiceTest`, `OnboardingRollbackTest`, `Admin*PageTest`; e2e `system-admin.spec.js` | TC-SA-04 | R-06 CRIT / P1 / 1 | **10** (IBR 2, VCI 4, FIX? 3, EXP 1); PS-INS-01…10 | — (all decided 2026-10-28) | [V] purge keeps files and accounts, [A] |
| **SUB** Subscriptions, plans, limits | status ladder, renewal, plan limits | `SubscriptionServiceTest`, `SubscriptionLifecycleTest`, `SubscriptionStatusTest`, `PlanService/Limits*Test` | TC-SA-06, -07, TC-SUB-01 | R-03 HIGH / P1 / 2 | **8** (IBR 1, VCI 7); PS-SUB-01…08 | — (all decided 2026-10-28) | [A] |
| **USR** Users (admin) | deactivate, roles, bulk, export | `UserAdminServiceTest`, `AdminUsersPageTest`, `UserExportTest` | TC-SA-11 | R-26 MED / P2 / 3 | **5** (VCI 4, REG 1); PS-USR-01…05 | — (all decided 2026-10-28) | [A], [V] V10 |
| **FLG** Feature flags | resolution, overrides, notes | `FeatureFlag*Test` | none | R-26 MED / P2 / 3 | **4** (VCI 3, EXP 1); PS-FLG-01…04 | — (all decided 2026-10-28) | [A] |
| **SET** Platform settings | general and security settings, time zone, audit retention | `GeneralSettingsTest`, `SecuritySettingsTest` | none (platform); TC-SEC-01 (institution) | R-26 MED / P2 / 3 | **4** (IBR 1, VCI 2, NB 1); PS-SET-01…04 | — (all decided 2026-10-28) | [V] V1, [A] |
| **AUD** Audit log | append-only, redaction, export | `AuditLogTest` (20), `AuditRedactionTest`, `InstitutionAuditLogPageTest` | TC-SA-09, TC-AUD-01 | R-31 MED / P2 / 3 | **4** (VCI 4); PS-AUD-01…04 | — (all decided 2026-10-28) | [A] |
| **BAK** Backups | request, run, status, alert | `BackupServiceTest`, `MysqldumpRunnerTest`, `BackupStatusTest`; no `RunBackup` command test | TC-SA-12 (dialog only) | R-05 CRIT / P1 / 1 | **8** (VCI 3, REG 1, FIX? 4); PS-BAK-01…08 | — (all decided 2026-10-28) | [V] V4, [A] |
| **MON** Monitoring and health | `/health`, system status, admin home | `HealthEndpointTest`, `SystemHealthTest`, `AdminHomeTest`, `AdminMonitoringPageTest` | TC-SA-03, TC-HOM-01 | R-04 CRIT / P1 / 1 | **5** (VCI 3, FIX? 2); PS-MON-01…05 | — (all decided 2026-10-28) | [V] V5 |
| **JOB** Scheduled jobs | six cron commands (seventh planned) | `SubscriptionLifecycleTest` (sync), `ParentsNotifyCommandTest`, `InstitutionPurgeTest` (command), `SisImporterTest`; no `RunBackup` command test | none | R-04 CRIT / P1 / 1 | **6** (VCI 2, FIX? 3, EXP 1); PS-JOB-01…06 | — (all decided 2026-10-28) | [V] V4, V5 |

### A3. Institution administrator and academic structure

| Module | Scope | Existing automated coverage | Existing catalog | Risk / Pri / Wave | Proposed additional scenarios | Unresolved decisions | Evidence |
|---|---|---|---|---|---|---|---|
| **STR** Structure | departments, sub-departments, programs | `StructureServiceTest` (18), `InstitutionStructureTest` (16), `AcademicHierarchyTest` | TC-STR-01..06 | R-18 HIGH / P1 / 2 | **5** (VCI 5); PS-STR-01…05 | — (all decided 2026-10-28) | [A] |
| **CHR** Chair and dean assignment | one chair, replace, SIS lock, dean rules | `OneChairpersonTest`, `StructureChairReplaceTest`, `ChairAssignmentServiceTest`, `DeanAssignmentServiceTest` (12), `SisStaffImportTest` (staged) | TC-CRS-03 (**stale**), TC-DEAN-02 | R-08 CRIT / P1 / 1 | **13** (IBR 6, VCI 3, FIX? 2, NB 1, EXP 1); PS-CHR-01…13 | — (all decided 2026-10-28) | [A], [V] V12 |
| **TRM** Terms | create, status, close, reopen | `TermServiceTest` (14), `TermCloseServiceTest` (8), `InstitutionTermCloseTest`, `ClosedOfferingTest` | TC-TRM-01..03 | R-18 HIGH / P1 / 2 | **7** (VCI 5, REG? 1, NB 1); PS-TRM-01…07 | — (all decided 2026-10-28) | [A] |
| **OFF** Offerings and copy | create, instructors, copy, class times | `OfferingServiceTest` (19), `OfferingCopyServiceTest` (14), `OfferingCopyRollbackTest` | TC-OFF-01..03, TC-CPY-01 | R-18 HIGH / P1 / 2 | **10** (VCI 4, FIX? 3, EXP 3); PS-OFF-01…10 | — (all decided 2026-10-28) | [A], [V] no class-time entry |
| **ENR** Enrolment | add, status, capacity, role conflicts | `EnrolmentServiceTest` (13), `EnrolmentListTest`, `InstitutionEnrolmentsTest` | TC-ENR-01..03 | R-18 HIGH / P1 / 2 | **9** (IBR 1, VCI 2, REG 1, REG? 1, FIX? 3, EXP 1); PS-ENR-01…09 | — (all decided 2026-10-28) | [A], [V] 23 `inGroup` checks |
| **PPL** People and invitations | invite, deactivate, roles, reset | `PeopleServiceTest`, `InstitutionPeopleTest` (16), `OneInstitutionPerAccountTest` | TC-PPL-01..04 | R-18 HIGH / P1 / 2 | **5** (IBR 2, VCI 3); PS-PPL-01…05 | — (all decided 2026-10-28) | [A] (service PARTIAL) |
| **CSV** CSV upload | preview / apply, limits, tokens | `CsvUploadServiceTest` (12), `InstitutionCsvUploadTest` (13), `SisCsvParserTest` | TC-UPL-01, -02 | R-19 MED / P2 / 3 | **6** (VCI 5, REG? 1); PS-CSV-01…06 | — (all decided 2026-10-28) | [A] |
| **PAR** Parent links and portal switch | link, unlink, switch, flag | `ParentLinkServiceTest`, `InstitutionParentLinksTest`, `ParentPortalTest` | TC-PAR-01, TC-PRT-10 | R-16 HIGH / P1 / 2 | **4** (IBR 1, VCI 2, EXP 1); PS-PAR-01…04 | — (all decided 2026-10-28) | [A] |
| **BRD** Profile and branding | colours, logo, profile | `BrandColoursTest`, `InstitutionProfileServiceTest`, `InstitutionSettingsTest` (13) | TC-BRD-01..03 | R-28 MED / P2 / 3 | **2** (VCI 2); PS-BRD-01…02 | — (all decided 2026-10-28) | [A] |
| **ACD** Academic defaults | passing mark, scale, categories, thresholds | `AcademicDefaultsTest`, `AcademicDefaultsResolutionTest` | TC-ACD-01 | R-28 MED / P2 / 3 | **2** (VCI 2); PS-ACD-01…02 | — (all decided 2026-10-28) | [A] |
| **IAS** Institution security and banner | stricter-only overrides, banner | `InstitutionSecurityTest` (16), `InstitutionSecurityStricterTest`, `InstitutionSecuritySettingsTest` | TC-SEC-01 | R-28 MED / P2 / 3 | **2** (IBR 1, VCI 1); PS-IAS-01…02 | — (all decided 2026-10-28) | [A] |
| **RPT** Reports, export, home | reports, data export, home attention | `InstitutionReportsTest` (16), `InstitutionExportTest`, `InstitutionExportServiceTest`, `InstitutionHomeAttentionTest` | TC-RPT-01, TC-HOM-01, TC-SA-06 | R-27 HIGH / P1 / 2 | **4** (VCI 3, FIX? 1); PS-RPT-01…04 | — (all decided 2026-10-28) | [A], [V] export is synchronous |
| **SIS** SIS integration | import, grade export, locks, gates, commands | `SisImporterTest` (30+), `SisImporterRollbackTest`, `SisStaffImportTest`, `SisGradeExporterTest`, `SisConnectionServiceTest`, `FileConnectorTest`, `SisStaffLockPagesTest`; **no e2e** | none | R-09 HIGH / P1 / 2 | **12** (IBR 1, VCI 8, OUT 1, EXP 2); PS-SIS-01…12 | — (all decided 2026-10-28) | [A] |

### A4. Courses: content, assessments, grading

| Module | Scope | Existing automated coverage | Existing catalog | Risk / Pri / Wave | Proposed additional scenarios | Unresolved decisions | Evidence |
|---|---|---|---|---|---|---|---|
| **MOD** Modules, units, items, resources | content tree, uploads, prerequisites, completion, direct links | `ModuleServiceTest`, `LearningProgressServiceTest`, `ResourceServiceTest`, `ContentDeletionServiceTest`, `ResourceRoutesTest`; e2e `modules.spec.js`. No real-file upload test | TC-MOD-01, -02, TC-VCV-01 | R-14 HIGH / P1 / 2 | **18** (IBR 2, VCI 10, FIX? 2, NB 1, EXP 3); PS-MOD-01…18 | — (all decided 2026-10-28) | [V] Routes 371/749, [A] |
| **ASM** Assessment authoring | lifecycle, totals, availability, validation | `AssessmentServiceTest`, `AssessmentRoutesScopeTest`, `AssessmentSectionAccessTest`, `AssessmentRescoreServiceTest`; e2e `assessment-editor.spec.js` (no TC ids) | none | R-11 CRIT / P1 / 2 | **12** (IBR 1, VCI 5, REG? 2, NB 1, EXP 1, CHK 2); PS-ASM-01…12 | — (all decided 2026-10-28) | [V] V9, [A] |
| **QBK** Question bank, import, export | CRUD, 12 import formats, 11 export formats, undo | `QuestionServiceTest`, `QuestionImport*Test` (5 files), `PrintableTestExportTest`, `QuestionPickerServiceTest` | none | R-11 CRIT / P1 / 2 | **10** (VCI 8, NB 1, EXP 1); PS-QBK-01…10 | — (all decided 2026-10-28) | [A] |
| **QUZ** Quiz taking | start, answer, timer, submit, scoring, races | `QuizAttemptServiceTest` (thorough); e2e `quizzes.spec.js` | TC-QUIZ-01..03 (confirmed) | R-10 CRIT / P1 / 1 | **19** (IBR 2, VCI 9, REG? 4, FIX? 3, EXP 1); PS-QUZ-01…19 | — (all decided 2026-10-28) | [V] V8, [A] |
| **AIQ** AI question builder | start, jobs, tray, worker API, Python pipeline | `QuestionGenerationServiceTest`, `QuestionGenerationSafetyTest`, `AiJob*Test`, `AiWorkerEndpointsTest` (6 failures in 2026-10-23 audit), pytest (15 files); no PHP↔Python contract test | none | R-12 HIGH / P1 / 2 | **13** (IBR 1, VCI 8, REG? 1, FIX? 1, NB 1, EXP 1); PS-AIQ-01…13 | — (all decided 2026-10-28) | [A] |
| **ASG** Assignments | submission, windows, late, attempts | `AssignmentSubmissionServiceTest` (29); e2e `assignments.spec.js` | TC-ASG-01, -02 (confirmed) | R-15 HIGH / P1 / 2 | **6** (IBR 2, VCI 3, NB 1); PS-ASG-01…06 | — (all decided 2026-10-28) | [V] V3, [A] |
| **GRD** Grading and feedback | rubric, scoring, feedback, reopen | `GradeWorkServiceTest` (~34), `RubricServiceTest` (~24); e2e `grading.spec.js` | TC-GRD-01..03 (confirmed) | R-15 HIGH / P1 / 2 | **8** (VCI 6, REG 1, FIX? 1); PS-GRD-01…08 | — (all decided 2026-10-28) | [V] `inGroup` line 40, [A] |
| **GBK** Gradebook | calculation, scheme, bands, manual, sheets, curves, reports | `GradingEngineServiceTest` (~36), `GradingSchemeServiceTest`, `GradeSheetTest` (~50), `GradeAdjustmentServiceTest`, `GradeReportServiceTest`; e2e `gradebook.spec.js` | TC-GBK-01..05 | R-07 CRIT / P1 / 2 | **11** (IBR 2, VCI 8, REG 1); PS-GBK-01…11 | — (all decided 2026-10-28) | [A] |
| **FIN** Finalization | readiness, freeze, reopen | `GradeFinalizationServiceTest` (~30), `GradeRoutesTest` | TC-GBK-04, TC-DEAN-05 (**stale**) | R-07 CRIT / P1 / 2 | **5** (VCI 4, REG 1); PS-FIN-01…05 | — (all decided 2026-10-28) | [A] |
| **OVR** Override request chain | request, chain, decide, race, re-route | `GradeOverrideRequestServiceTest` (~37), `OverrideRequestFlowTest` (14), `OverrideRequestRaceTest` (4), `AdministratorDecidesOverridesTest` (9), `GradeAuthorityTest`/`GapsTest`, `OverrideRequestChainTest` (staged) | TC-DEAN-04 (**stale**) | R-08 CRIT / P1 / 1 | **15** (IBR 9, VCI 5, REG 1); PS-OVR-01…15 | — (all decided 2026-10-28) | [A] (staged) |
| **SGN** Sign-off | states, dean / admin authority, program-wide, reopen | `GradeSignoffServiceTest` (~23), `AdministratorSignsOffTest` (9), `DeanSignoffPageTest`, `InstitutionSignoffPageTest` (3) (staged) | TC-DEAN-05 (**stale**) | R-08 CRIT / P1 / 1 | **10** (IBR 5, VCI 4, REG 1); PS-SGN-01…10 | — (all decided 2026-10-28) | [A] (staged) |

### A5. Communication, parents, dean / chair areas, student

| Module | Scope | Existing automated coverage | Existing catalog | Risk / Pri / Wave | Proposed additional scenarios | Unresolved decisions | Evidence |
|---|---|---|---|---|---|---|---|
| **DSC** Discussions | threads, replies, moderation, attachments | `DiscussionServiceTest` (~40), `DiscussionRoutesTest` (~26); no e2e | none | R-22 MED / P2 / 3 | **8** (IBR 1, VCI 5, FIX? 1, EXP 1); PS-DSC-01…08 | — (all decided 2026-10-28) | [A] |
| **ANN** Announcements | course feed, app page, department / institution / platform | `AnnouncementServiceTest` (33), `AnnouncementRoutesTest` (11), `DepartmentAnnouncementServiceTest`; no page test for the Announcements app | TC-ANN-01, -02 (confirmed), TC-DEAN-07 | R-22 MED / P2 / 3 | **11** (VCI 6, REG? 3, NB 2); PS-ANN-01…11 | — (all decided 2026-10-28) | [A] |
| **CAL** Calendar, reminders, schedule | events, range, reminders, grid | `CalendarEventServiceTest` (15), `CalendarRoutesTest`, `ScheduleServiceTest` (14); no schedule route test | TC-PRT-05 (parent calendar) | R-23 MED / P2 / 3 | **6** (IBR 2, VCI 4); PS-CAL-01…06 | — (all decided 2026-10-28) | [A] |
| **NOT** Notifications | dedupe, urgent email, triggers, lists | `NotificationsTest` (20), `NotificationPagesTest` (12) | none | R-17 HIGH / P1 / 2 | **7** (IBR 2, VCI 3, REG? 1, NB 1); PS-NOT-01…08 | — (all decided 2026-10-28) | [A] |
| **FIL** File serving and storage | access matrix, ranges, quota | `FileAccessServiceTest` (19), `FileRouteTest` (8), `StorageServiceTest` (8) | none | R-13 HIGH / P1 / 2 | **7** (VCI 6, NB 1); PS-FIL-01…07 | — (all decided 2026-10-28) | [A] |
| **DEA** Dean area | dashboard, follow-ups, department announcements, monitor, load, chair review | `DeanDashboardServiceTest`, `DeanAttentionRulesTest`, `DeanAccessServiceTest`, `FollowUpServiceTest`, `MonitorDepartmentServiceTest`, `TeachingLoadServiceTest`, `ChairpersonReviewServiceTest`; e2e `dean.spec.js` | TC-DEAN-01, -03, -06..-11 | R-24 MED / P2 / 3 | **7** (VCI 7); PS-DEA-01…07 | — (all decided 2026-10-28) | [A] (calculations unread) |
| **CHA** Chairperson area | reach, course writes audit, finalize boundary, monitor program | `ChairpersonAccessServiceTest`, `ChairpersonCourseAccessTest`, `GradeRoutesTest`, `MonitorProgram*Test`; e2e `chairperson.spec.js` (5) and `monitor-program.spec.js` (3), **no TC ids** | none | R-24 MED / P2 / 3 | **6** (IBR 2, VCI 4); PS-CHA-01…06 | — (all decided 2026-10-28) | [A] |
| **PRT** Parent portal | authorization, dashboard, course tabs, notifier, report | `ParentAuthorizationMatrixTest`, `ParentPrivacyCanaryTest`, `ParentDashboardServiceTest` (14), `ParentNotifierTest` (31), `ParentCoursePageTest`, `ParentGradeReportTest`; e2e `parent.spec.js` | TC-PRT-01..08 | R-16 HIGH / P1 / 2 | **8** (IBR 1, VCI 5, REG? 2); PS-PRT-01…08 | — (all decided 2026-10-28) | [A] |
| **STU** Student dashboard and profile | widgets, caps, parent names | `DashboardServiceTest` (28), `StudentProfilePageTest` (11); no student dashboard page test | TC-PRT-09 (student side) | R-29 MED / P2 / 3 | **2** (VCI 2); PS-STU-01…02 | — (all decided 2026-10-28) | [A] |

### A6. Data, frontend, delivery

| Module | Scope | Existing automated coverage | Existing catalog | Risk / Pri / Wave | Proposed additional scenarios | Unresolved decisions | Evidence |
|---|---|---|---|---|---|---|---|
| **DAT** Data integrity | constraints, FK actions, strict mode, migrations, seeds | `MigrationsTest` and per-migration tests, `ServiceTestInventoryTest`, `TableTestInventoryTest`, `CreateSystemAdminCommandTest` | none | R-25 HIGH / P1 / 2 | **9** (IBR 1, VCI 3, REG? 1, EXP 3, CHK 1); PS-DAT-01…09 | — (all decided 2026-10-28) | [V] V10, V11, V12, [A] |
| **FE** Frontend shell and quality | accessibility, responsive, keyboard, dark mode | Vitest (43 files: shared widgets, dialogs, theme, csrf); axe in e2e for dean / institution / admin | TC-DEAN-09, -10, TC-IAA-04, -05, TC-SA-10, -12 | R-29 MED / P2 / 3 | **7** (VCI 4, EXP 2, CHK 1); PS-FE-01…07 | — (all decided 2026-10-28) | [A] |
| **CI** Build, deploy, CI | pipeline gates, deploy, smoke, uptime | `SmokeTestScriptTest`, `CheckDiffCoverageScriptTest`, `E2EConfigTest`; the workflows themselves are untested | none | R-30 HIGH / P1 / 2 | **3** (VCI 1, FIX? 1, CHK 1); PS-CI-01…03 | — (all decided 2026-10-28) | [A], [V] V4 |

### A7. Modules with no existing catalog case and no end-to-end test

SEC, FLG, SET (platform), JOB, SIS, ASM, QBK, AIQ, DSC, CAL, NOT, FIL, CHA (tests exist, no ids), DAT, CI, MON (partly), BAK (dialog only). These are where new coverage adds the most that does not exist today. CRIT-zone ones among them (SEC, JOB, BAK) are Wave 1.

## Part B. End-to-end workflow matrix

A workflow is a path that crosses browser, route and filter, controller, service and database. Each row lists the existing chain of tests that exercises it, the catalog cases, the proposed scenarios (by module wildcard or id) and the decisions that still block part of it.

| # | Workflow | Layers touched | Roles | Existing coverage | Catalog | Proposed scenarios | Pri | Decisions blocking part | Evidence |
|---|---|---|---|---|---|---|---|---|---|
| W1 | Sign-in with optional 2FA, landing, lockout | login view → Shield + filters → `AdminEmail2FA` → `AuditAuthListener` → role redirect | all | PHPUnit chain in A1; e2e `auth-login` | TC-AUTH-01..05, TC-SA-01 | PS-AUT-01..13, PS-AUT-18, PS-REA-01..04 | P1 | AMB-AUTH-01, -04, -07 | [A] |
| W2 | Invitation → account | people form → `InvitationService` → mail → public link → password policy → login | institution admin, system admin, invitee | `InvitationLinkTest`, `InvitationServiceTest`; only TC-SA-05 end to end | TC-PPL-01, TC-SA-05 | PS-AUT-09, PS-AUT-11, PS-PPL-01, PS-PPL-03, PS-SUB-03 | P1 | — | [A] |
| W3 | Institution onboarding | admin form (3 steps) → `OnboardingService` (transaction) → subscription → invitation after commit | system admin, first admin | `OnboardingServiceTest`, `OnboardingRollbackTest`; e2e SA-04..07 | TC-SA-04..07 | PS-INS-03, PS-SUB-01, PS-SUB-02 | P1 | — | [A] |
| W4 | Content → quiz → gradebook | module editor → assessment auto-create → publish chip → student attempt → answers → submit → gradebook sync → recalculation | instructor, student | service-level in `QuizAttemptServiceTest`, `ModuleServiceTest`; e2e TC-MOD/QUIZ | TC-MOD-01, -02, TC-QUIZ-01..03 | PS-MOD-01..04, PS-ASM-02, PS-ASM-07, PS-QUZ-*, PS-GBK-01, PS-GBK-10 | P1 | AMB-ASM-02..09 | [A], [V] V8 |
| W5 | Assignment → grading → gradebook | submission form → `AssignmentSubmissionService` → grading → feedback → gradebook | student, instructor | `AssignmentSubmissionServiceTest`, `GradeWorkServiceTest`; e2e | TC-ASG-01, -02, TC-GRD-01..03 | PS-ASG-*, PS-GRD-*, PS-GBK-* | P1 | AMB-ASG-01, -06 | [A] |
| W6 | Finalize → sign-off → SIS export | finalize → `GradeSignoffService` → dean / admin board → `SisGradeExporter` gate → `sis_grade_exports` | instructor, dean, institution admin, SIS | services tested; e2e TC-DEAN-05 (stale) | TC-GBK-04, TC-DEAN-05 | PS-FIN-*, PS-SGN-*, PS-SIS-05, PS-SIS-06, PS-CHR-11 | P1 | AMB-ASG-04, -05 | [A] (staged) |
| W7 | Final-grade override request | gradebook → `GradeOverrideRequestService` → chair endorse → dean / admin decide → engine → audit + notify | instructor, chair, dean, admin | `OverrideRequestFlowTest`, `OverrideRequestRaceTest`, chain tests; e2e TC-DEAN-04 (stale) | TC-DEAN-04 | PS-OVR-*, PS-CHR-01..05, PS-RBA-07 | P1 | AMB-INST-03, AMB-ASG-05, -09 | [A] (staged) |
| W8 | Term close, reopen, copy | term pages → `TermCloseService` (transaction + snapshot) → closed-offering filter | institution admin, all roles after | `TermCloseServiceTest`, `ClosedOfferingTest`, `OfferingCopyServiceTest`; e2e TRM / CPY | TC-TRM-03, TC-CPY-01 | PS-TRM-02..07, PS-OFF-04 | P1 | AMB-INST-01, -02 | [A] |
| W9 | SIS import (cron or upload) | inbound CSV → gates → `SisImporter` (one transaction) → ownership locks | SIS, institution admin | `SisImporterTest`, rollback test, `CsvUploadServiceTest`; **no e2e** | TC-UPL-01 (upload only) | PS-SIS-01..04, PS-SIS-08, PS-SIS-09, PS-CSV-03..05 | P1 | — | [A] |
| W10 | AI question generation | quiz builder → `QuestionGenerationController` → extraction → `ai_jobs` → Python worker → provider → tray → `QuestionService` | instructor, chair | service and job tests, pytest; **no PHP↔Python test** | none | PS-AIQ-* | P1 | AMB-ASM-10, MISS-AI-01 | [A] |
| W11 | Parent portal and notifications | finalize / cron → `ParentNotifier` → in-app + email → parent routes (404 filters) | parent, student | notifier and matrix tests; e2e `parent.spec.js` | TC-PRT-01..10 | PS-PRT-*, PS-PAR-*, PS-NOT-01, PS-NOT-02 | P1 | AMB-COM-01, -04 | [A] |
| W12 | Subscription lapse → read-only → renewal | dates → `EffectiveStatus` → `ReadOnlyFilter` → banner → renewal → sync job → notifications | all roles, system admin | `ReadOnlyModeTest`, `SubscriptionLifecycleTest`; e2e SA-06/07 | TC-SA-06, -07, TC-IAA-06 | PS-ROM-01..06, PS-ROM-10, PS-SUB-01, PS-INS-08, PS-INS-09 | P1 | AMB-OPS-01, -02, MISS-OPS-04 | [A] |
| W13 | Backup and recovery | admin request → `backups` row → cron `backup:run` → mysqldump \| gzip → status → alert → off-server copy | system admin, ops | `BackupServiceTest`, `MysqldumpRunnerTest` | TC-SA-12 | PS-BAK-01..08 | P1 | AMB-OPS-03 | [A], [V] no read-back, no off-site |
| W14 | Institution purge | suspend → delete → retention → `purge` → audit-first → cascade (files and accounts remain) | system admin | `InstitutionPurgeTest` (22) | none | PS-INS-02, PS-INS-04..07, PS-INS-10 | P1 | PLN-09 | [A], [V] |
| W15 | View-as | reauth → reason → session switch → banner → non-GET refused → stop | system admin, institution admin | `ImpersonationTest`, `InstitutionViewAsTest` | TC-SA-08, TC-VAS-01 | PS-IMP-01..07 | P1 | — | [A], [V] V8 |
| W16 | Role switch and dual roles | `POST role/switch` → `ActiveRoleService` → `RoleFilter` → area | dual-role people | `ActiveRoleServiceTest`, `RoleSwitchTest` | TC-DEAN-02 | PS-AUT-12, PS-RBA-08, PS-RBA-13, PS-CHA-01 | P1 | AMB-AUTH-07 | [A] |
| W17 | Permission change takes effect | roles page (platform / institution) → `role_permissions` → `RolePermissions` → route filter / `coursepermission` → UI | system admin, institution admin, affected roles | `PermissionsTakeEffectTest`, `PermissionRouteTest`, layer tests | none for the flow | PS-RBA-02..07, PS-RBA-11, PS-RBA-14 | P1 | AMB-AUTH-08 | [A] |
| W18 | Upload and file access | form → `StorageService` (quota) → `files` row → `GET files/{id}` → `FileAccessService` | instructor, student, parent, admin | `FileAccessServiceTest`, `FileRouteTest`, `StorageServiceTest`; no real-file e2e | none | PS-MOD-07, PS-MOD-08, PS-FIL-*, PS-SEC-07, PS-SUB-04 | P1 | AMB-DB-03 | [A] |
| W19 | Announcement → notification | composer / calendar post → `AnnouncementService` → feed, calendar, dashboard, parent tab, parent notifier | instructor, student, parent | `AnnouncementServiceTest`, `ParentNotifierTest` | TC-ANN-01, -02 | PS-ANN-*, PS-CAL-01, PS-NOT-06, PS-PRT-07 | P1 | AMB-COM-01, MISS-COM-01, -02 | [A] |
| W20 | Enrolment lifecycle | roster add / status → `EnrolmentService` → gradebook sync → capacity → role-conflict rules | institution admin, student | `EnrolmentServiceTest` | TC-ENR-01..03 | PS-ENR-01..08, PS-OFF-10 | P1 | AMB-INST-04, AMB-ASG-03 | [A], [V] 23 `inGroup` checks |
| W21 | Question import, preview, undo | upload / paste → parser → validator → `ImportPreviewStore` token → apply → undo → rescore | instructor | `QuestionImport*Test` | none | PS-QBK-02..08 | P1 | MISS-ASM-02 | [A] |
| W22 | Deploy → migrate → health → jobs | tag / merge → CI → rsync → `spark migrate` → worker restart → `/health` → cron | ops | CI and smoke script; deploy untested | none | PS-CI-01..03, PS-DAT-01, PS-JOB-01..03 | P1 | PLN-05 | [A], [V] V4 |
| W23 | Data export when read-only | lapse → export page → build zip → download → 7-day expiry | institution admin | `InstitutionExportTest`; e2e SA-06 | TC-SA-06 | PS-RPT-02, PS-RPT-03 | P1 | — | [A], [V] synchronous |
| W24 | Follow-up and department announcement | dean / chair form → `FollowUpService` / `DepartmentAnnouncementService` → notify → reply / close | dean, chair, instructor, students | `FollowUpServiceTest`, `DepartmentAnnouncementServiceTest`; e2e TC-DEAN-06/07 | TC-DEAN-06, -07 | PS-DEA-03, PS-DEA-04, PS-CHA-05 | P2 | — | [A] |


## Part C. Scenario backlog (proposed additional scenarios)

These are **scenario titles with the source of their expected result**, not test cases. Steps, data and exact expected text are written later, in the catalog format (`docs/test-cases/README.md`), once the blocking decisions are made. Final `TC-<AREA>-<NN>` IDs are allocated at that point; the `PS-` IDs here are only for planning and are never reused.

**Status** (what the expected result is based on):

| Status | Meaning | May be written now? |
|---|---|---|
| `READY-IBR` | Expected result is set by an authoritative requirement (decision id, an owner-confirmed catalog case, or a sign-off decision `SO:`). It does not say the code already behaves that way. | Yes |
| `READY-VCI` | Expected result is the code's behavior. Since 2026-10-28 this is **confirmed** (register I1.3): the code is the source of truth where its behavior is deliberate. It is re-read against `ae86042` when the final case is written. | Yes |
| `REG` | Regression test for a defect whose code was verified. Expected result comes from the requirement; **it is expected to fail until the defect is fixed.** Not run. | Yes |
| `REG?` | Same, but the defect is only suspected from code reading and must be confirmed by running it. | Yes, after confirming |
| `FIX?` | A fix for this defect or missing feature was committed after `27f2012` and names a test. The expected result is the requirement (or, where they differ, the committed behavior). **Not run**: it is not known to pass. | Yes |
| `OUT` | Out of scope by decision. No case is written. | No |
| `NB` | An authoritative requirement that is **not built yet** (feature absent). The scenario documents what the requirement says. | Yes, marked not-built |
| `BLK` | Blocked: the expected result is ambiguous. The blocking decision ID is listed. No expected result is invented. | **No** |
| `EXP` | Exploratory charter or probe. There is no oracle by design; the output is findings. | Yes, as a charter |
| `CHK` | An investigation or run to perform (not a test case). | n/a |

Oracle column: `DD:<id>` = delegated decision in `decision-register.md` Part I (2026-10-28); `SO:<id>` = decision recorded in `decision-register.md` Part F; `D:<n>` = `docs/audit-2026-10-23-decisions.md` decision n (U/D/numbered); `TC:<id>` = owner-confirmed catalog case; `T:<name>` = existing PHP/JS test that already pins the behavior; `V:<n>` = inventory verification V-n.

### Authentication and session (AUT)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-AUT-01 | Lockout: N failures lock the email, the right password is refused while locked, the lock ends M minutes after the last failure, a success resets the count | T:SecurityPoliciesTest, SecuritySettingsTest | READY-VCI | P1 |
| PS-AUT-02 | Confirm at runtime that a successful sign-in is recorded so the failure count resets (Shield records failures only by constant) (expected: a success row is written) | AMB-AUTH-01; SO:AMB-AUTH-01 | CHK | P1 |
| PS-AUT-03 | Lockout cannot be dodged with email case or surrounding whitespace | none (probe) | EXP | P2 |
| PS-AUT-04 | An institution's stricter lockout, idle timeout and password length apply to its own people only; looser values are refused | D:U1c, T:InstitutionSecurityTest | READY-VCI | P1 |
| PS-AUT-05 | Idle past the effective timeout ends the session on the next request (redirect with message; JSON 401 for scripts); recent activity refreshes it | T:SecuritySettingsTest | READY-VCI | P1 |
| PS-AUT-06 | Deactivation or "sign out everywhere" ends other sessions on their next request; a session started after is unaffected | T:AccountStateTest | READY-VCI | P1 |
| PS-AUT-07 | 2FA for a non-admin role chosen by platform or institution, through the full sign-in flow | T:SecurityPoliciesTest (unit level only) | READY-VCI | P2 |
| PS-AUT-08 | 2FA mail failure outside development stops sign-in; in development the code is logged | T:AdminTwoFactorTest | READY-VCI | P2 |
| PS-AUT-09 | Invitation link: single use, expiry boundary, replaced, revoked, institution deleted (404 vs 410), weak password keeps the link alive | T:InvitationLinkTest | READY-VCI | P1 |
| PS-AUT-10 | Password-reset link: single use, expiry, replaced, ends other sessions, deactivated account refused | T:PasswordResetLinkTest | READY-VCI | P1 |
| PS-AUT-11 | An invitee is held to the inviting institution's password rule | T:SecurityPoliciesTest | READY-VCI | P2 |
| PS-AUT-12 | A dual-role person's area follows the active role; an unheld role cannot be switched to; a dean with no assignment cannot act as dean | T:ActiveRoleServiceTest, RoleSwitchTest | READY-VCI | P1 |
| PS-AUT-13 | A person with no usable role gets the 403 explanation after sign-in | T:AuthFlowTest | READY-VCI | P2 |
| PS-AUT-14 | Failed 2FA codes are not rate limited or counted anywhere | MISS-AUTH-03 | EXP | P2 |
| PS-AUT-15 | Remember-me (30 days) versus idle timeout; polling pages keeping a session alive | AMB-AUTH-04; SO:AMB-AUTH-04 | READY-IBR | P3 |
| PS-AUT-16 | Response time for a real versus unknown email (noted in TC-AUTH-03) | TC:AUTH-03 note | EXP | P3 |
| PS-AUT-17 | Self-service "forgot password" and "change my password" (change-password to be built; verify "forgot password" against the existing reset-by-link flow first) | MISS-AUTH-01; SO:MISS-AUTH-01, DOC-16 | NB | P2 |
| PS-AUT-18 | Wrong password and unknown email give the identical message; no account enumeration through invitation, reset or lockout messages | TC:AUTH-03 | READY-IBR | P1 |
| PS-AUT-19 | An institution cannot set lockout to "never" while the platform has it on; the refusal names the rule | AMB-AUTH-06; SO:AMB-AUTH-06 | READY-IBR | P3 |
| PS-AUT-20 | 2FA for a person with several roles when only one of them is selected for 2FA | AMB-AUTH-07; SO:AMB-AUTH-07 | READY-IBR | P2 |
| PS-AUT-21 | Lockout notification or an admin unlock; allowed sign-in methods; compromised-password check; session listing and limits (open: admin unlock, method list, fail-open/closed, session limits) (**decided 2026-10-28:** decided: no admin unlock (the lock message is the notice), one sign-in method; not built: compromised-password check (fail-open), session list and revoke; no session limit) | MISS-AUTH-04, MISS-AUTH-05, MISS-AUTH-06, MISS-AUTH-07; SO:MISS-AUTH-04..07; DD:MISS-AUTH-04..07 | NB | P3 |

### Re-authentication (REA)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-REA-01 | Password re-entry window boundaries (inside and outside 300 s); another person's confirmation does not count | T:ReauthServiceTest | READY-VCI | P1 |
| PS-REA-02 | Five wrong passwords end the session (HTML and JSON paths) | T:AdminReauthTest | READY-VCI | P1 |
| PS-REA-03 | The return path is limited to admin / institution paths; a planted external or `//` path is refused | T:InstitutionReauthTest | READY-VCI | P1 |
| PS-REA-04 | A dean who is not an institution admin confirms the password and lands on the dean dashboard | none (GAP-AUTH-04) | READY-VCI | P2 |
| PS-REA-05 | Sign-off and program sign-off ask for the password; permission toggles (both layers) ask for it | D:2.4, D:U1c | READY-IBR | P1 |
| PS-REA-06 | Which other actions must ask for the password (delete, suspend, deactivate, reopen sign-off, per-institution flags, integrations test / preview) (listed actions must ask for the password; confirm which already do) (**decided 2026-10-28:** sign-off reopen is removed from the list (I4)) | AMB-AUTH-05; SO:AMB-AUTH-05; DD:AMB-AUTH-05 | REG? | P2 |
| PS-REA-07 | The password window is a setting rather than a constant | AMB-OPS-05; SO:AMB-OPS-05 | READY-IBR | P3 |

### Impersonation / view-as (IMP)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-IMP-01 | Both kinds of view-as refuse every non-GET request (403 JSON or redirect with message) except stop | D:3.2, T:ImpersonationTest | READY-IBR | P1 |
| PS-IMP-02 | Opening any GET page while viewing as someone saves nothing on their behalf (quiz open, notification read, last-seen, progress) (**after the new commits:** 5140068; `ViewAsWritesNothingTest`) | D:3.2; MISS-QZ-02 | FIX? | P1 |
| PS-IMP-03 | Opening a quiz while viewing as a student shows availability, attempts used and past results; an attempt in progress is read-only; no attempt is started (**after the new commits:** 5140068; `ViewAsQuizTest`) | D:3.1, V:8; MISS-QZ-02 | FIX? | P1 |
| PS-IMP-04 | An institution admin can view only their own institution's people and never another institutional admin or the system admin | T:InstitutionViewAsTest | READY-VCI | P1 |
| PS-IMP-05 | View-as ends on expiry, logout, target deactivation, or admin account block; audit entries carry the administrator | T:ImpersonationTest | READY-VCI | P2 |
| PS-IMP-06 | View-as and data export remain available when the institution is read-only | T:ReadOnlyModeTest | READY-VCI | P2 |
| PS-IMP-07 | A banner marks every page while viewing; reason 5–500 chars required | T:ImpersonationTest | READY-VCI | P2 |

### Roles, permissions and route access (RBA)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-RBA-01 | After the plan-4 routes, every route is public, known, or role-protected (re-run the route sweep) | T:RouteAccessTest; PLN-05 | CHK | P1 |
| PS-RBA-02 | Each enforced permission, once cleared, takes effect on the next request (every permission on the Roles page does something) | D:7.1, 7.4–7.7 | READY-IBR | P1 |
| PS-RBA-03 | An institution's override layers on the platform default; boxes it never touched follow later platform changes; setting back to the default removes the row | D:U1b | READY-IBR | P1 |
| PS-RBA-04 | Permissions not applicable to a role cannot be set and stored rows are ignored; every role keeps at least one permission; the admin role is locked | D:D2, U1c | READY-IBR | P1 |
| PS-RBA-05 | Every permission change is audited (with institution id on the institution page); a change that cannot be audited is undone | D:U1c | READY-IBR | P2 |
| PS-RBA-06 | The "no one can approve or sign off" warning shows on both permission pages | D:7.3, U1f | READY-IBR | P1 |
| PS-RBA-07 | An institution taking `grades.approve` / `grades.signoff` from its deans makes the admin stand in ("no dean" for that duty, not "no deans anywhere") | D:7.2, U1e | READY-IBR | P1 |
| PS-RBA-08 | A wrong role is redirected to `/` and then to its own dashboard, in every area | TC:AUTH-05; AMB-AUTH-02 | READY-IBR | P1 |
| PS-RBA-09 | Menu tiles for permission-gated pages (Approvals, Announcements, Follow-ups…) when the permission is cleared (tiles are visible today [A]) | AMB-AUTH-08; SO:AMB-AUTH-08 | REG? | P3 |
| PS-RBA-10 | A non-member gets 404 for every course page and API in each area (student, instructor, chair, dean, institution admin, parent) | TC:ANN-02, T:CourseMembershipTest; DOC-23 | READY-IBR | P1 |
| PS-RBA-11 | Clearing `courses.manage`, `grading.manage`, `announcements.manage` or `discussions.manage` makes that part of the course read-only for that role | D:7.4, D6 | READY-IBR | P1 |
| PS-RBA-12 | Deleting others' posts and marking an answer require `discussions.manage` (the route filter gates only pin and lock) (**decided 2026-10-28:** 5140068; enforced in the service via `canModerate` [V]) | D:D6; DOC-09; DD | FIX? | P2 |
| PS-RBA-13 | A chairperson counts as an instructor of every course in their program (cannot be enrolled in one); deans and institution admins cannot be students in their own courses | D:9.4, 9.5 | READY-IBR | P1 |
| PS-RBA-14 | Institution admin edits only the five academic roles; `institutional_admin` stays with the system admin; refused while read-only | D:U1a | READY-IBR | P1 |

### Read-only states, effective status and maintenance (ROM)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-ROM-01 | In a read-only institution every role's writes are refused (403 JSON or redirect with message); reads still work | D:8.1, T:ReadOnlyModeTest | READY-IBR | P1 |
| PS-ROM-02 | Own-account changes still work when read-only: reminders, notification read state, a parent's email choices; institution records stay frozen | D:8.2 | READY-IBR | P1 |
| PS-ROM-03 | "Password" as an own-account change in a read-only institution (no self-service change exists; reset link is public) | D:8.2 vs DOC-16; SO:DOC-16, D:8.2 | NB | P2 |
| PS-ROM-04 | Past and suspended institutions behave like read-only | D:8.3 | READY-IBR | P1 |
| PS-ROM-05 | Access follows the dates the moment they pass, with the nightly job not run | D:6.1 | READY-IBR | P1 |
| PS-ROM-06 | Whether a change is allowed depends on that institution's state (one account, one institution) | D:8.1, D1 | READY-IBR | P1 |
| PS-ROM-07 | Maintenance mode: non-admins get 503 with Retry-After (HTML or JSON); admins and impersonators bypass; open paths work | V:AUTH 1.9 | READY-VCI | P1 |
| PS-ROM-08 | Maintenance open-path matching is loose (a segment named login / health / auth anywhere) (the loose open-path match must go [A]) | AMB-AUTH-09; SO:AMB-AUTH-09 | REG? | P3 |
| PS-ROM-09 | A signed-in institution admin during maintenance (institution admin must not get 503 [A]; others see a "system under maintenance" page) (**decided 2026-10-28:** institution administrators keep working; others see the maintenance page) | AMB-AUTH-03; SO:AMB-AUTH-03; DD | REG? | P3 |
| PS-ROM-10 | Renewal lifts the restriction on the next request; another institution's state does not reach me | T:ReadOnlyModeTest | READY-VCI | P2 |

### Cross-cutting web security (SEC)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-SEC-01 | Every state-changing route refuses a request without a valid token, with "This page was open too long. Reload it and try again." and typed input kept; the test fails if CSRF is turned off | D:4.1, 4.2, 4.4 | READY-IBR | P1 |
| PS-SEC-02 | Only `internal/ai/*` is exempt from CSRF | D:4.1 | READY-IBR | P1 |
| PS-SEC-03 | A plain link cannot sign anyone out; sign-out is a button | D:4.3 | READY-IBR | P1 |
| PS-SEC-04 | Stored XSS probes across user text: names, announcement subject / message, discussion posts, rubric text, institution banner, audit values, rich-text content | none (probe) | EXP | P1 |
| PS-SEC-05 | CSV / spreadsheet formula injection defused in every export (users, audit, reports, grade sheet) | T:UserExportTest, AuditLogTest | READY-VCI | P1 |
| PS-SEC-06 | ID guessing (IDOR) across institutions for each resource type answers "not found", never "forbidden" | TC:ANN-02, T:per-page tests | READY-IBR | P1 |
| PS-SEC-07 | Upload handling: MIME / extension mismatch, oversize, executable names, polyglots; files stored outside the web root and served with nosniff | none (probe) | EXP | P1 |
| PS-SEC-08 | Security headers, secure cookie flag, forced HTTPS (all currently off) (phased: report-only CSP first; HSTS only after HTTPS) | MISS-AUTH-02, V:7; SO:MISS-AUTH-02 | NB | P2 |
| PS-SEC-09 | Rate limiting on invitation and reset token endpoints | MISS-AUTH-03; SO:MISS-AUTH-03 | NB | P2 |
| PS-SEC-10 | Secrets (passwords, tokens, keys) never appear in audit rows, logs or error pages | T:AuditRedactionTest | READY-VCI | P1 |
| PS-SEC-11 | Worker API refuses: no secret configured (503), not loopback (403), wrong secret (401) | T:AiWorkerEndpointsTest | READY-VCI | P1 |
| PS-SEC-12 | Session fixation / regeneration on login; cookie attributes | none (probe) | EXP | P2 |
| PS-SEC-13 | Error pages leak nothing (stack traces, SQL) in production mode | none (probe) | EXP | P2 |

### Institution lifecycle and onboarding (INS)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-INS-01 | Create: code format, uniqueness, immutability; name length | T:InstitutionServiceTest | READY-VCI | P2 |
| PS-INS-02 | Suspend goes to `past`; delete refused while active or while a subscription is running; exact name required; restore keeps the previous status | T:InstitutionServiceTest | READY-VCI | P1 |
| PS-INS-03 | Onboarding is atomic; the invitation mail goes after commit; a mail failure leaves the institution onboarded with a resendable invitation | T:OnboardingRollbackTest | READY-VCI | P1 |
| PS-INS-04 | Purge deletes every file used in the institution (row and disk) and keeps a file another institution still uses (**after the new commits:** 2154a2f, 67cc8bf (a file another record uses is kept); `InstitutionPurgeFilesTest`) | D:10.1, 10.2; MISS-OPS-02, DOC-17, PLN-09 | FIX? | P1 |
| PS-INS-05 | Purge deletes accounts belonging to no other institution, with profile, notifications, reminders and remaining files (**after the new commits:** 2154a2f; `InstitutionPurgeFilesTest`) | D:10.3; MISS-OPS-02, DOC-17, PLN-09 | FIX? | P1 |
| PS-INS-06 | Purge deletes the institution's export zips and keeps its audit entries (**after the new commits:** 2154a2f; purge collects export files and logs `institution.purged` [V]) | D:10.4, 10.5; MISS-OPS-02, DOC-17 | FIX? | P1 |
| PS-INS-07 | Purge writes its audit entry first; a failed purge records `institution.purge_failed`; the scheduled purge honors retention and labels the actor "system (scheduled purge)" | T:InstitutionPurgeTest | READY-VCI | P1 |
| PS-INS-08 | Renewing a manually suspended institution (manual suspension must survive renewal; confirm `statusAfterRenewal`) (**decided 2026-10-28:** the code keeps the suspension [A]) | AMB-OPS-01; SO:AMB-OPS-01; DD | READY-IBR | P2 |
| PS-INS-09 | Restoring a deleted institution whose dates now lapse | AMB-OPS-02; SO:AMB-OPS-02 | READY-IBR | P2 |
| PS-INS-10 | Soft-deleted institution: children remain; nothing breaks; restore brings them back | none (probe) | EXP | P2 |

### Subscriptions, plans, limits (SUB)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-SUB-01 | Status ladder at every boundary (end date, warning window, grace, read-only days, zero-day settings) | T:SubscriptionStatusTest | READY-VCI | P1 |
| PS-SUB-02 | Renewal and correction rules: later end only; plan change only to an active plan (or stay); corrections change payment only | T:SubscriptionServiceTest | READY-VCI | P2 |
| PS-SUB-03 | Seat limit blocks invite, reactivate and adding the student role at 100% and warns at 90% | T:PlanLimitEnforcementTest | READY-VCI | P1 |
| PS-SUB-04 | Storage limit blocks uploads; AI limit blocks generation, top-up and replace | T:PlanLimitEnforcementTest | READY-VCI | P1 |
| PS-SUB-05 | Retire / reinstate a plan; a retired plan cannot be chosen but existing institutions keep it | T:PlanServiceTest | READY-VCI | P3 |
| PS-SUB-06 | Lowering a plan's limits below current usage (grandfather existing usage; block new usage) | AMB-OPS-06; SO:AMB-OPS-06 | READY-IBR | P2 |
| PS-SUB-07 | Per-institution expiry timeline overrides are accepted only when stricter | T:InstitutionSecurityTest | READY-VCI | P2 |
| PS-SUB-08 | History rows are never edited or deleted | T:SubscriptionServiceTest | READY-VCI | P2 |

### Users, flags, settings, audit (USR / FLG / SET / AUD)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-USR-01 | Deactivate guards: not yourself, not the last active system admin, SIS-managed people refused; sessions end | T:UserAdminServiceTest | READY-VCI | P1 |
| PS-USR-02 | Role changes: at least one role kept, `admin` never assignable; removing a role ends chair / dean assignment | T:UserAdminServiceTest | READY-VCI | P1 |
| PS-USR-03 | Bulk deactivate / reactivate reports a reason per skipped person; reactivating a student needs a free seat | T:UserAdminServiceTest | READY-VCI | P2 |
| PS-USR-04 | Users export: 10,000 cap, formulas defused, audited | T:UserExportTest | READY-VCI | P2 |
| PS-USR-05 | Deleting a user row removes the dean assignments, override requests, sign-offs and announcements they created (foreign-key argument order) ([V] cascade removes history; target is SET NULL or RESTRICT) (**decided 2026-10-28:** target: SET NULL for creators; RESTRICT for a file a resource uses) | PLN-02, V:10; SO:PLN-02, V:10; DD:PLN-02 | REG | P1 |
| PS-FLG-01 | Flag resolution order: global off → institution override → plan grants → global on / default; expired `ends_on` ignored | T:FeatureFlagResolverTest | READY-VCI | P1 |
| PS-FLG-02 | A note is required for on / off; a past end date is refused; "default" deletes the row | T:FeatureFlagAdminTest | READY-VCI | P3 |
| PS-FLG-03 | The three flags actually gate their features (SIS sync, AI generation, parent portal) | T:FeatureFlagsTest | READY-VCI | P1 |
| PS-FLG-04 | AI generation fails open when the flag cannot be read | none (observed) | EXP | P3 |
| PS-SET-01 | Each platform setting enforces its bounds and has its stated effect | T:GeneralSettingsTest | READY-VCI | P2 |
| PS-SET-02 | A change of platform time zone moves every time in the app, including class times | D:13.5, U4 | READY-IBR | P2 |
| PS-SET-03 | `auditRetentionDays` archives or removes old audit entries ([V] the setting has no effect today) | MISS-OPS-01; SO:MISS-OPS-01 | NB | P2 |
| PS-SET-04 | A settings change is audited; if the audit write fails the change is undone | T:GeneralSettingsTest | READY-VCI | P2 |
| PS-AUD-01 | Audit rows cannot be updated or deleted through the model | T:AuditLogTest | READY-VCI | P1 |
| PS-AUD-02 | Every security-relevant action in the inventory writes an audit entry with actor, acting role and impersonator | T:per-service tests | READY-VCI | P1 |
| PS-AUD-03 | Audit export cap, formula defusing, self-audit; IP shown only when enabled | T:AuditLogTest | READY-VCI | P2 |
| PS-AUD-04 | The institution's audit log is scoped to it, shows no source addresses, labels vendor staff "Mentora support" | T:InstitutionAuditLogPageTest | READY-VCI | P2 |

### Backups, monitoring, scheduled jobs (BAK / MON / JOB)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-BAK-01 | Request → run → succeeded; a second request is refused while one is pending | T:BackupServiceTest | READY-VCI | P1 |
| PS-BAK-02 | A backup is successful only once the whole file was written and reads back to the end; otherwise it fails, the file is deleted, and system admins are warned and emailed (**after the new commits:** 5069228; `BackupProvenTest`, `BackupFileTest`) | D:11.1; MISS-OPS-03, DOC-17 | FIX? | P1 |
| PS-BAK-03 | A failing run never causes a good backup to be deleted; only proven-good backups count toward the 14 kept (**after the new commits:** 5069228; `BackupProvenTest`) | D:11.2; MISS-OPS-03, DOC-17 | FIX? | P1 |
| PS-BAK-04 | After every good backup an encrypted copy goes off-server (production dumps and uploads, 30 days, append-only); a failed copy warns; a copy fetched back opens (**after the new commits:** 5069228; `BackupOffsiteTest`. Encryption and retention are done by the configured off-site command, outside the app) | D:11.4, U3; MISS-OPS-03 | FIX? | P1 |
| PS-BAK-05 | Uploaded files (`writable/uploads`) are part of the backup (**after the new commits:** 5069228; uploads go to the off-site copier with the dump [V]) | D:U3a, docs/backup.md; MISS-OPS-03 | FIX? | P1 |
| PS-BAK-06 | A crashed `running` backup row blocks later requests and runs (**decided 2026-10-28:** a backup `running` over 2 h must be marked failed and alerted; today it blocks later backups [V]) | GAP-OPS-03; MISS-OPS-03; DD:MISS-OPS-03 | REG | P2 |
| PS-BAK-07 | Backup alert thresholds and the delay before the urgent notification (26 h, 1 h, hourly independent check; today 24 h and sent only by `subscriptions:sync`) (**after the new commits:** code is the source of truth: the age alert uses the `backupAlertHours` setting, default 24 [V]; supersedes the 26 h sign-off) | AMB-OPS-03; SO:AMB-OPS-03 | READY-VCI | P2 |
| PS-BAK-08 | The mysqldump password is never shown in an error | T:MysqldumpRunnerTest | READY-VCI | P2 |
| PS-MON-01 | Health endpoint: 200 ok, 503 degraded, no caching, works under maintenance | T:HealthEndpointTest | READY-VCI | P1 |
| PS-MON-02 | System status thresholds: database 1000 ms, disk 90 / 97%, storage writable, AI heartbeat | T:SystemHealthTest | READY-VCI | P2 |
| PS-MON-03 | Admin home needs-attention list | T:AdminHomeTest | READY-VCI | P2 |
| PS-MON-04 | When any scheduled job misses its window the system admin sees a warning on the admin home page and gets one email, and one when it recovers (**after the new commits:** 5069228, e4457b0; `SchedulerCheckTest`, `SchedulerCheckEndpointTest`) | D:6.2, U2, D11; MISS-OPS-04 | FIX? | P1 |
| PS-MON-05 | The hourly uptime check reports missed jobs (**after the new commits:** 5069228; `UptimeWorkflowTest`) | D:U2; MISS-OPS-04 | FIX? | P1 |
| PS-JOB-01 | Each command's real and dry-run behavior and exit code: `backup:run`, `subscriptions:sync`, `institutions:purge-expired`, `parents:notify`, `sis:sync`, `sis:grades` | T:per-command tests | READY-VCI | P1 |
| PS-JOB-02 | The deploy installs the scheduled jobs on every server, checks they are present, and fails only if that check fails (**after the new commits:** 5069228; `CrontabBlockTest`, `JobsCrontab` command) | D:D5, 6.4; MISS-OPS-05 | FIX? | P1 |
| PS-JOB-03 | Running a job twice creates no duplicate notifications, rows or emails | T:NotificationsTest (dedupe) | READY-VCI | P1 |
| PS-JOB-04 | With `subscriptions:sync` not running, no notification of any kind fires (all triggers hang off it) | V:4 | EXP | P2 |
| PS-JOB-05 | The background export is a seventh scheduled job (**after the new commits:** 5069228; `exports:run` is the seventh registered job [V]) | D:6.4 "Also"; MISS-OPS-06 | FIX? | P2 |
| PS-JOB-06 | `parents:notify` reports each run so a missed run is detected like every other job (**after the new commits:** 5069228; `parents:notify` is registered with `Monitoring.parentsNotifyAt` [V]) | D:6.2, D11; DOC-24 | FIX? | P2 |

### Academic structure, assignments of chair and dean (STR / CHR)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-STR-01 | Code and name validation boundaries at every level (50 chars, start character, 3–255, year 1800 → now) | T:StructureServiceTest | READY-VCI | P2 |
| PS-STR-02 | A node cannot be switched off while it has active children, courses, or offerings in an active term; the page says why | T:StructureServiceTest, TC:STR-05 (draft) | READY-VCI | P1 |
| PS-STR-03 | Nothing can be created or reactivated under an inactive parent | T:StructureServiceTest | READY-VCI | P2 |
| PS-STR-04 | Program inactive / archived / reactivate transitions; invalid status refused | T:StructureServiceTest | READY-VCI | P3 |
| PS-STR-05 | An unchanged save writes no audit entry; every change writes `structure.*` | T:StructureServiceTest | READY-VCI | P2 |
| PS-CHR-01 | Assigning a second chair to a sub-department asks for confirmation naming both; confirming replaces the old chair, keeps history and notifies them | D:16.0, 16.0b; PLN-03 | READY-IBR | P1 |
| PS-CHR-02 | Requests waiting for the old chair pass to the new chair, who is notified | D:16.0c | READY-IBR | P1 |
| PS-CHR-03 | Requests already past the chair step do not move back to a newly assigned chair | D:U5 | READY-IBR | P1 |
| PS-CHR-04 | Where several chairs exist, the migration keeps the most recently assigned and ends the others | D:16.0d | READY-IBR | P1 |
| PS-CHR-05 | With no chair, or when the chair is the requester, the chair step is skipped and the requester is told | D:U5 | READY-IBR | P1 |
| PS-CHR-06 | A second active chair cannot be inserted by a path that bypasses the service (the seeded chair has no `active_marker`) (**after the new commits:** 48543c7; unique key in migration 2026-11-15-000001; `OneChairpersonTest`) | D:16.0, V:12 | FIX? | P2 |
| PS-CHR-07 | Two simultaneous chair assignments end in one chair and a friendly message, not a raw database error (**after the new commits:** 48543c7, 67cc8bf; `OverrideRequestRaceTest`, the chairperson race test) | AMB-INST-07; SO:AMB-INST-07 | FIX? | P2 |
| PS-CHR-08 | Removing a chair (not replacing): what happens to requests waiting for the chair, and who is told (requests stay `awaiting_chair`; dean or administrator is told) (**after the new commits:** code is the source of truth: `revoke()` moves waiting requests on to the dean or the administrators (`retargetChairStep`) [V]; supersedes the AMB-INST-03 sign-off) | AMB-INST-03; SO:AMB-INST-03 | READY-VCI | P2 |
| PS-CHR-09 | The SIS replaces a hand-made chair; a hand assignment cannot replace a SIS-owned chair; a second chair for one sub-department in one file is refused | T:SisStaffImportTest | READY-VCI | P1 |
| PS-CHR-10 | Dean: one per department and per person (database-enforced); a chairperson cannot be made dean; SIS lock | T:DeanAssignmentServiceTest | READY-VCI | P1 |
| PS-CHR-11 | When the first dean is assigned, unsent finalized grades now need sign-off and the admins are told which sections wait | D:2.3 | READY-IBR | P1 |
| PS-CHR-12 | Assign or end a dean from the institution area (open: SIS source of truth) (**decided 2026-10-28:** SIS-managed dean assignments locked like chairs) | MISS-INST-01; SO:MISS-INST-01; DD:MISS-INST-01 | NB | P2 |
| PS-CHR-13 | Chair status `expired` and reactivation overwriting `assigned_at` | GAP-DB-01 | EXP | P3 |

### Terms and offerings (TRM / OFF)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-TRM-01 | Term fields: name 2–100, year formats and separators, end after start, 800-day limit, name + year unique | T:TermServiceTest | READY-VCI | P2 |
| PS-TRM-02 | Status moves: upcoming ⇄ active; active → upcoming refused with enrolments; completed / archived cannot be edited | T:TermServiceTest | READY-VCI | P1 |
| PS-TRM-03 | Close: offerings and enrolled places become completed, snapshot stored, audit inside the transaction, password required; a failed audit rolls back | T:TermCloseServiceTest | READY-VCI | P1 |
| PS-TRM-04 | Reopen restores only rows still completed; blocked when an offering was copied from the term | T:TermCloseServiceTest | READY-VCI | P1 |
| PS-TRM-05 | Closing a term with unsigned or unfinalized grades (closing requires finalized or signed-off grades; not built) | AMB-INST-01; SO:AMB-INST-01 | NB | P2 |
| PS-TRM-06 | Reopening a term while another is active | AMB-INST-02; SO:AMB-INST-02 | REG? | P2 |
| PS-TRM-07 | A closed offering is read-only for instructor, student and chair, including calendar announcements; archived offerings are not opened | T:ClosedOfferingTest | READY-VCI | P1 |
| PS-OFF-01 | Create: course active, term upcoming / active, section characters and capital letters, (course, term, section) unique including archived, capacity bounds | T:OfferingServiceTest | READY-VCI | P1 |
| PS-OFF-02 | Edit only while active; capacity cannot drop below enrolled; archive refused while students are enrolled | T:OfferingServiceTest | READY-VCI | P2 |
| PS-OFF-03 | Instructor assign / unassign: role, active offering, duplicate refused, SIS lock | T:OfferingServiceTest | READY-VCI | P1 |
| PS-OFF-04 | Copy: all-or-nothing, collision refuses the whole run, dates shift by the term difference, what is and is not copied, source term cannot reopen | T:OfferingCopyServiceTest | READY-VCI | P1 |
| PS-OFF-05 | An offering whose course and term belong to different institutions is refused on create, copy and SIS import (no database constraint) | none (DB gap) | EXP | P1 |
| PS-OFF-06 | The admin enters a section's class times on the offering form (day, start, end, room, delivery mode) (**after the new commits:** 2154a2f; entered on a Class times page linked from Offerings, not the offering form; `OfferingSchedulePageTest`) | D:13.1, 13.3; MISS-OPS-07 | FIX? | P2 |
| PS-OFF-07 | A room or instructor clash warns and the save still goes through (**after the new commits:** 2154a2f; `ClassScheduleServiceTest`) | D:13.4; MISS-OPS-07 | FIX? | P2 |
| PS-OFF-08 | SIS class times: the whole-timetable file locks a section's rows; a SIS section left out loses its SIS times; a section never sent is untouched; no file changes nothing (**after the new commits:** 2154a2f; `SisClassSchedulesTest`) | D:D8, D9, 13.2; MISS-OPS-07 | FIX? | P2 |
| PS-OFF-09 | End time before start time; overlapping meetings; duplicate meeting rows | none (DB gap) | EXP | P3 |
| PS-OFF-10 | Capacity enforced under concurrent enrolments | none (PHP-only check) | EXP | P2 |

### Enrolment, people, CSV, parent links (ENR / PPL / CSV / PAR)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-ENR-01 | Add list: 500 cap, split on lines and commas, resolved by student number or email; skip reasons for not-a-student, SIS-managed, already enrolled, completed; dropped / withdrawn reinstated | T:EnrolmentServiceTest | READY-VCI | P1 |
| PS-ENR-02 | Status changes follow the allowed set; same status refused; re-enrolling checks capacity | T:EnrolmentServiceTest | READY-VCI | P1 |
| PS-ENR-03 | Reinstating a dropped / withdrawn student through Add refreshes the gradebook tag like a status change (`add()` does not sync [A]) (**decided 2026-10-28:** `add()` reinstates without `syncEnrolment` [V]) | AMB-INST-04; SO:AMB-INST-04; DD | REG | P2 |
| PS-ENR-04 | No one is both student and instructor in one course (any section) in the same term, whichever role comes first (**after the new commits:** 5140068; `CourseRoleConflictsTest`) | D:9.2, 9.3, D7; MISS-GRD-01 | FIX? | P1 |
| PS-ENR-05 | A chairperson cannot be enrolled in a course of their program and cannot become chair while enrolled in one; deans and institution admins cannot be students in their courses (**after the new commits:** 5140068; `CourseRoleConflictsTest`) | D:9.4, 9.5; MISS-GRD-01 | FIX? | P1 |
| PS-ENR-06 | A former student may later teach or chair; a former dean or admin may never enrol in a course they led | D:D7, D10 | READY-IBR | P2 |
| PS-ENR-07 | Existing conflicts are fixed automatically: the enrolment is removed and the staff role stays (**after the new commits:** 5140068; migration 2026-11-16-000001, `EndCourseRoleConflictsTest`) | D:9.6, 9.7; MISS-GRD-01 | FIX? | P2 |
| PS-ENR-08 | Capacity overrun by two simultaneous enrolments | none | EXP | P2 |
| PS-ENR-09 | A student enrolled in another section of the same course submits work (service-level check missing [A]) (**after the new commits:** after 5140068: `AssignmentSubmissionService` line 120 still uses `inGroup('student')` when no offering is found [V]) | AMB-ASG-03; SO:AMB-ASG-03 | REG? | P3 |
| PS-PPL-01 | Invite each role; seat check for students; duplicate email refused; resend and revoke; pending list | T:InstitutionPeopleTest | READY-VCI | P1 |
| PS-PPL-02 | Deactivate / reactivate guards (last admin, yourself); removing a role ends the chair or dean assignment | T:PeopleServiceTest | READY-VCI | P1 |
| PS-PPL-03 | One account, one institution: inviting an address that belongs to another institution is refused | D:D1 | READY-IBR | P1 |
| PS-PPL-04 | A person given the dean role with no department assignment (role granted with a warning; no dean powers until assigned; assignable later) | AMB-INST-05; SO:AMB-INST-05 | READY-IBR | P3 |
| PS-PPL-05 | Reset-password from People needs the password; the link goes to the person's address | T:InstitutionPeopleTest | READY-VCI | P2 |
| PS-CSV-01 | Per-row validation message for every rule in each dataset (required, >255, email, units, status, staff role target, repeated key) | T:SisCsvParserTest | READY-VCI | P1 |
| PS-CSV-02 | File limits (10 MB, 50,000 rows), BOM, header case and spaces, unknown columns ignored, header-only file refused | T:CsvUploadServiceTest | READY-VCI | P2 |
| PS-CSV-03 | Preview writes nothing and equals apply; token bound to the institution; expiry 3600 s; apply re-checks that no SIS connection is enabled | T:CsvUploadServiceTest | READY-VCI | P1 |
| PS-CSV-04 | Upload is refused while a SIS connection is enabled | T:CsvUploadServiceTest | READY-VCI | P1 |
| PS-CSV-05 | Staff-assignments upload end to end (dean, chair, instructor targets) | T:InstitutionCsvUploadTest | READY-VCI | P2 |
| PS-CSV-06 | Un-applied previews linger until the next preview (expiry within 1 h not built [A]) | AMB-INST-06; SO:AMB-INST-06 | REG? | P3 |
| PS-PAR-01 | Link rules: parent role, student role, same institution; unlink; duplicates refused | T:ParentLinkServiceTest, D:D1 | READY-VCI | P1 |
| PS-PAR-02 | The portal needs the Mentora flag and the institution switch; off shows no child data (403 JSON for APIs) and the profile still works | T:ParentPortalTest | READY-VCI | P1 |
| PS-PAR-03 | Which switch wins when the setting and the flag disagree | AMB-OPS-04; SO:AMB-OPS-04 | READY-IBR | P3 |
| PS-PAR-04 | A parent link to a user who is later deleted leaves an orphan | none (no FK) | EXP | P3 |

### Settings, branding, defaults, reports, export (BRD / ACD / IAS / RPT)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-BRD-01 | Colour contrast boundary 4.5 (primary vs tint, accent vs white); defaults; reset | T:BrandColoursTest | READY-VCI | P3 |
| PS-BRD-02 | Logo limits: PNG / JPEG / WebP, ≤ 1 MB, 32–2000 px, SVG refused, counts toward storage | T:InstitutionProfileServiceTest | READY-VCI | P2 |
| PS-ACD-01 | Academic defaults: institution value else platform value; existing schemes keep theirs; category weights must total 100 | T:AcademicDefaultsTest | READY-VCI | P2 |
| PS-ACD-02 | The shipped default grade scale, passing mark and categories are the institution's official ones (**decided 2026-10-28:** default under test: 1.00–5.00, pass 75, Quizzes 50 / Assignments 50; institution defaults replace it) | AMB-ASG-11; DD:AMB-ASG-11 | READY-VCI | P1 |
| PS-IAS-01 | Security overrides accept only stricter values; equal to platform is not stored; password required | D:U1c, T:InstitutionSecurityTest | READY-IBR | P1 |
| PS-IAS-02 | Institution banner: ≤ 300 chars, plain text, no `<` `>`, optional end date, shown to non-admins | T:InstitutionSecurityTest | READY-VCI | P3 |
| PS-RPT-01 | Institution reports match Monitor Program numbers; CSV defused and audited | T:InstitutionReportsTest | READY-VCI | P2 |
| PS-RPT-02 | Data export: available only when read-only or past; kept 7 days; a failed audit discards it | T:InstitutionExportTest | READY-VCI | P1 |
| PS-RPT-03 | Export copes with 20,000 students, 5 years of terms and ~50 graded items a course, is prepared in the background, one at a time per institution, and only the requester is notified (**after the new commits:** 5069228; `InstitutionExportQueueTest`, `InstitutionExportScaleTest`) | D:12.1–12.4; MISS-OPS-06 | FIX? | P1 |
| PS-RPT-04 | Home "needs attention" list | T:InstitutionHomeAttentionTest | READY-VCI | P3 |

### SIS integration (SIS)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-SIS-01 | Each import gate refuses with its message: flag off, settings unsaved, connection disabled, institution not active, no earlier run | T:SisImporterTest | READY-VCI | P1 |
| PS-SIS-02 | Atomicity: bad rows counted and skipped, an unexpected exception rolls back the whole run, a dry run writes nothing, audit row inside the transaction | T:SisImporterRollbackTest | READY-VCI | P1 |
| PS-SIS-03 | Matching and conflict rules per dataset (SIS-owned records, external ids) | docs/sis-integration.md, T:SisImporterTest | READY-VCI | P1 |
| PS-SIS-04 | Staff assignments: only SIS-supplied ones are ended; nothing ends if rows were unreadable; instructors end only in the active term | T:SisStaffAssignmentsTest | READY-VCI | P1 |
| PS-SIS-05 | Grade export sends only new or changed grades, retries 3 times, on failure marks nothing sent and notifies admins | T:SisGradeExporterTest | READY-VCI | P1 |
| PS-SIS-06 | Where the institution has any dean only signed-off sections go; with no dean a finalized grade goes; when the first dean is assigned unsent grades need sign-off | D:2.1, 2.2, 2.3 | READY-IBR | P1 |
| PS-SIS-07 | `.sig` file: valid, invalid and missing signatures | none (docs) | EXP | P2 |
| PS-SIS-08 | Ownership locks hold while a connection is enabled (course edits, enrolments, names / emails, chair / dean, instructor unassign) and lift when disabled | T:SisStaffLockPagesTest | READY-VCI | P1 |
| PS-SIS-09 | End to end: a file placed in the inbound folder, `sis:sync` run, data visible in the app (no browser or CLI end-to-end test exists) | T:SisImporterTest (service level) | READY-VCI | P1 |
| PS-SIS-10 | Credentials stored sealed with the application key; never shown | docs/sis-integration.md | EXP | P2 |
| PS-SIS-11 | A non-file (HTTP) connector (**decided 2026-10-28:** out of scope: the file connector is the only connector) | MISS-HTTP-01; DD:MISS-HTTP-01 | OUT | P3 |
| PS-SIS-12 | Institution page hides test runs and folder names | T:InstitutionIntegrationsTest | READY-VCI | P3 |

### Course content: modules and resources (MOD)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-MOD-01 | A student sees content only when the module, unit, item and (for work) assessment are all published; draft and inactive stay hidden at every level | TC:MOD-02 | READY-IBR | P1 |
| PS-MOD-02 | Prerequisite modes none / previous / custom; custom with an empty list becomes none; previous with no earlier item is refused | T:LearningProgressServiceTest | READY-VCI | P1 |
| PS-MOD-03 | Prerequisite cycle, self-reference and a prerequisite from another offering | none (PHP-only) | EXP | P2 |
| PS-MOD-04 | Completion: video ≥ 90% (file and YouTube; other embeds do not gate), quiz and assignment on hand-in (confirmed), discussion on reply, reading / link / PDF always | TC:QUIZ-01, TC:ASG-01 (hand-in); T:LearningProgressServiceTest (rest) | READY-VCI | P1 |
| PS-MOD-05 | Reordering prunes prerequisites that now come later | T:ModuleServiceTest | READY-VCI | P2 |
| PS-MOD-06 | Deleting a module, unit or item removes dependents, purges only unreferenced files, renumbers order | T:ContentDeletionServiceTest | READY-VCI | P1 |
| PS-MOD-07 | Video upload: 500 MB boundary, allowed MIME types, interrupted-upload message, plan storage cap (no real-file test exists) | T:ModuleServiceTest (messages only) | READY-VCI | P1 |
| PS-MOD-08 | PDF upload: 25 MB boundary, exact MIME, replacing purges the old file | T:ModuleServiceTest | READY-VCI | P1 |
| PS-MOD-09 | Item type is whitelisted (an invalid type is refused) | GAP-CONT-04 | EXP | P2 |
| PS-MOD-10 | A dean or institution admin opens a direct lesson link and gets the course page on that lesson, read-only, drafts marked; a deleted lesson, another course's lesson and a course out of reach all give the same friendly not-found page (**after the new commits:** 2154a2f; lesson links for all five roles redirect to the course page on that lesson; `ReadOnlyLessonLinkTest`) | D:5.1, 5.2, 5.3, V:Routes 371/749 | FIX? | P1 |
| PS-MOD-11 | A typed or shared student / instructor lesson address opens the lesson (the catalog says it crashed and was not fixed) (**after the new commits:** 2154a2f; the student and instructor routes now go to `CourseController::view` [V]) | PLN-04 | FIX? | P1 |
| PS-MOD-12 | Dean and institution-admin mirrors of modules have no write routes | T:DeanCourseViewTest | READY-VCI | P2 |
| PS-MOD-13 | Link items accept web links only (`javascript:` and `data:` refused) | T:ModuleServiceTest | READY-VCI | P1 |
| PS-MOD-14 | Rich-text content cannot run script when viewed by other roles | none (probe) | EXP | P1 |
| PS-MOD-15 | A quiz, assignment or discussion item auto-creates its assessment or thread; deleting the item leaves the assessment attached to the course only | T:ModuleServiceTest | READY-VCI | P2 |
| PS-MOD-16 | Resources tab: offering / module / unit levels, search, sort, type filter, list / grid; students see published content only | T:ResourceServiceTest | READY-VCI | P2 |
| PS-MOD-17 | People tab and course-settings page in the course UI (participants and course configuration reachable; a dedicated tab or page is not mandatory) | MISS-CONT-01; SO:MISS-CONT-01 | READY-IBR | P3 |
| PS-MOD-18 | Virus scanning, thumbnails, duration extraction for uploads (virus scan required; open: transcoding, thumbnails, duration) (**decided 2026-10-28:** only virus scanning is required) | MISS-CONT-02; SO:MISS-CONT-02; DD:MISS-CONT-02 | NB | P3 |

### Quiz taking (QUZ)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-QUZ-01 | Start creates attempt n+1; an open attempt is resumed; Cancelled counts toward the limit; instructor preview starts no student attempt | T:QuizAttemptServiceTest | READY-VCI | P1 |
| PS-QUZ-02 | Answers: saved while time remains (+60 s grace), refused after, one row per question with last write winning, refused after submit, cross-student refused | T:QuizAttemptServiceTest | READY-VCI | P1 |
| PS-QUZ-03 | Scoring by type: single choice all-or-nothing, multiple answer exact set, short answer normalised and case rules, essay / file pending, unanswered scores 0 | T:QuizAttemptServiceTest | READY-VCI | P1 |
| PS-QUZ-04 | Status after submit: all auto-graded → Graded; mixed → Partially Graded (confirmed for essay); nothing auto → Submitted | TC:GRD-02 (essay case); T:QuizAttemptServiceTest (rest) | READY-VCI | P1 |
| PS-QUZ-05 | A second tab opened at the same moment resumes the same attempt: same timer, same saved answers (**after the new commits:** 5140068; `QuizTwoTabsTest`) | D:15.1; MISS-QZ-01 | FIX? | P1 |
| PS-QUZ-06 | The last save per question wins; once one tab submits the other can change nothing; a double submit counts once in the gradebook (**after the new commits:** 5140068; one answer per question (migration 2026-11-16-000002); `QuizTwoTabsTest`, `QuizAttemptFailureTest`) | D:15.2; MISS-QZ-01 | FIX? | P1 |
| PS-QUZ-07 | An instructor-group person acting as a student in a different section follows the same quiz rules (visibility, window, attempts) (**after the new commits:** 5140068; `QuizCourseRoleTest`) | D:9.1; MISS-GRD-01, MISS-QZ-02 | FIX? | P1 |
| PS-QUZ-08 | Answer keys (`is_correct`, accepted answers, case flag) never reach the student page or its network responses | T:QuizAttemptServiceTest | READY-VCI | P1 |
| PS-QUZ-09 | A prerequisite lock stops a quiz from starting | T:QuizAttemptServiceTest | READY-VCI | P1 |
| PS-QUZ-10 | Availability messages: before opens, after closes, closed by the instructor, past due without late | TC:QUIZ-03 | READY-IBR | P1 |
| PS-QUZ-11 | A running attempt when the quiz is closed, drafted, archived or its questions edited (Closed auto-submits running attempts; today they resume [A, tested]; open: archive) (**decided 2026-10-28:** closing or archiving does not end a running attempt; it resumes until its timer ends; pinned by a test) | AMB-ASM-03; SO:AMB-ASM-03; DD:AMB-ASM-03 | READY-VCI | P1 |
| PS-QUZ-12 | Should `available_until` or `due_date` cut off a running attempt (**decided 2026-10-28:** only duration plus the latency allowance ends an attempt; `available_until` stops new attempts; `due_date` marks late) | AMB-ASM-04; SO:AMB-ASM-04; DD:AMB-ASM-04 | READY-VCI | P2 |
| PS-QUZ-13 | Submit after the timer: accepted, scoring only what was saved (**decided 2026-10-28:** answers accepted a few seconds after the timer; an expired attempt scores only what was saved in time; pinned by tests) | AMB-ASM-05; SO:AMB-ASM-05; DD:AMB-ASM-05 | READY-VCI | P1 |
| PS-QUZ-14 | Attempt limit 0, negative, or "unlimited" (no validation today [A]) | AMB-ASM-09; SO:AMB-ASM-09 | REG? | P2 |
| PS-QUZ-15 | File Upload questions inside a quiz (File Upload must not be authorable in quizzes) | AMB-ASM-08, MISS-QZ-03; SO:AMB-ASM-08, MISS-QZ-03 | REG? | P2 |
| PS-QUZ-16 | How a Cancelled attempt is created and by whom (Cancelled must not count against the limit (it does today); open: system cancellation rule) (**decided 2026-10-28:** the system never cancels; staff cancellation not built; a cancelled attempt must not count) | AMB-ASM-06; SO:AMB-ASM-06; DD:AMB-ASM-06 | REG? | P3 |
| PS-QUZ-17 | Multiple-answer partial credit; short-answer fuzzy matching | AMB-ASM-07; SO:AMB-ASM-07 | READY-IBR | P2 |
| PS-QUZ-18 | The browser timer and auto-submit behave (client code not read) | none | EXP | P1 |
| PS-QUZ-19 | Editing a quiz's questions does not change a running attempt or its score; the attempt keeps the questions it started with | DD:AMB-ASM-03 | REG? | P1 |

### Assessment authoring, question bank, import / export (ASM / QBK)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-ASM-01 | A quiz and an assignment are created in a unit; total points derive from questions / rubric and the form value is ignored | TC:(assessment-editor e2e), T:AssessmentServiceTest | READY-VCI | P1 |
| PS-ASM-02 | Status changes through the module chip stay in step across learning item, assessment and grade item | T:ModuleServiceTest | READY-VCI | P1 |
| PS-ASM-03 | Publishing a quiz with no questions (nothing blocks publishing today [A]) | AMB-ASM-02; SO:AMB-ASM-02 | REG? | P1 |
| PS-ASM-04 | Invalid values are refused on the server: dates out of order, duration ≤ 0, attempt limit < 1, negative or huge points, empty question text | MISS-ASM-01; SO:MISS-ASM-01 | REG? | P1 |
| PS-ASM-05 | What sets an assessment to Closed (open: exact boundary rules) (**decided 2026-10-28:** boundary second pinned to the code) | AMB-ASM-01; SO:AMB-ASM-01; DD:AMB-ASM-01 | READY-IBR | P2 |
| PS-ASM-06 | The six assessment actions with no route (publish, unpublish, archive, restore, duplicate, statistics) are removed or wired | V:9 | CHK | P3 |
| PS-ASM-07 | Editing, adding, deleting questions after attempts exist rescored finished attempts, updates the grade item maximum and recalculates a published scheme; In Progress untouched | T:AssessmentRescoreServiceTest | READY-VCI | P1 |
| PS-ASM-08 | A nested id (assessment, submission, question, rubric, criterion, generated question) from another offering answers 404 on every route | T:AssessmentRoutesScopeTest | READY-VCI | P1 |
| PS-ASM-09 | Section tie through the module: deleting the module leaves the assessment on the course only | none | EXP | P2 |
| PS-ASM-10 | Rubric create / edit / criteria bounds; an assignment's total comes from the rubric | T:RubricServiceTest | READY-VCI | P2 |
| PS-ASM-11 | `weight_percentage` on the assessment versus the grading scheme (confirm that no active workflow uses `weight_percentage`) | AMB-DB-01; SO:AMB-DB-01 | CHK | P3 |
| PS-ASM-12 | Assessment password and browser-lockdown settings (password stored hashed; lockdown restrictions to be defined) | AMB-DB-02; SO:AMB-DB-02 | NB | P2 |
| PS-QBK-01 | Question create / edit / duplicate / delete / reorder / clear; choice ids stay stable; totals recalculate | T:QuestionServiceTest | READY-VCI | P1 |
| PS-QBK-02 | Import happy path per format (docx, rtf, csv, xlsx, aiken, gift, moodle xml, qti, plain, printed exam) and docx / rtf round trip | T:QuestionImport*Test | READY-VCI | P1 |
| PS-QBK-03 | Limits: 5 MB, 200,000 pasted chars, 300 questions, extension rejected, `.tst` refused, scanned PDF refused | T:QuestionImportHardeningTest | READY-VCI | P2 |
| PS-QBK-04 | Preview writes nothing; token bound to person and quiz, 1 h, single use; undo for 10 minutes; apply and undo audited | T:QuestionImportHardeningTest | READY-VCI | P1 |
| PS-QBK-05 | Validator errors and warnings per rule; exact repeat flagged; similar (≥ 0.85) flagged | T:QuestionImportHardeningTest | READY-VCI | P2 |
| PS-QBK-06 | Changing or removing an answered question needs acknowledgement; adding to a taken quiz does not | T:QuestionImportHardeningTest | READY-VCI | P1 |
| PS-QBK-07 | Export formats and printable options (versions, shuffles, key only, paper) | T:PrintableTestExportTest | READY-VCI | P2 |
| PS-QBK-08 | Hostile import files: zip bombs, XML external entities, macro documents, huge spreadsheets | none (probe) | EXP | P1 |
| PS-QBK-09 | Content sniffing instead of extension only; OCR for scanned PDFs (open: OCR) (**decided 2026-10-28:** content sniffing required; OCR when the legacy service runs, the code's behavior when it does not) | MISS-ASM-02; SO:MISS-ASM-02; DD:MISS-ASM-02 | NB | P3 |
| PS-QBK-10 | Question picker copies between assessments of the same course | T:QuestionPickerServiceTest | READY-VCI | P3 |

### AI question builder (AIQ)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-AIQ-01 | Start gating: assessment belongs to the offering, flag on, plan limit, source is the quiz's own unit, only reading and PDF items | T:QuestionGenerationServiceTest | READY-VCI | P1 |
| PS-AIQ-02 | Job lifecycle: atomic claim, progress and heartbeat, stall display, re-queue ≤ 2 then `worker_lost`, applied once | T:AiJobServiceTest | READY-VCI | P1 |
| PS-AIQ-03 | Review tray: edit limits, accept only pending, accept-all leaves out flagged, replace, top-up, discard, cancel | T:QuestionGenerationSafetyTest | READY-VCI | P1 |
| PS-AIQ-04 | Only the batch creator can act; another teacher of the course cannot | T:QuestionGenerationSafetyTest | READY-VCI | P1 |
| PS-AIQ-05 | Two simultaneous accepts of one generated question add it once | none (check-then-act); MISS-AI-01 | EXP | P2 |
| PS-AIQ-06 | Internal API: wrong worker cannot complete another's job; progress note / fail message truncated | T:AiWorkerEndpointsTest | READY-VCI | P1 |
| PS-AIQ-07 | Fake-backend smoke end to end (`QB_BACKEND=fake`) from the browser | none | READY-VCI | P1 |
| PS-AIQ-08 | Default (no-jobs) path through the legacy service; OCR fallback; skipped-source message | T:QuestionGenerationServiceTest | READY-VCI | P2 |
| PS-AIQ-09 | Generated-question quality against a recorded baseline and a 25–30 case golden set, with a named reviewer (open: owner, reviewer, dates) (**decided 2026-10-28:** owner and reviewer Shuay; 25 golden cases) | MISS-AI-01; SO:MISS-AI-01; DD:MISS-AI-01 | NB | P2 |
| PS-AIQ-10 | `count` upper bound; whether failed / cancelled generations, top-up and replace use quota (no upper bound in PHP [A]) | AMB-ASM-10; SO:AMB-ASM-10 | REG? | P2 |
| PS-AIQ-11 | Source text leaves the institution for an external provider: notice or consent (**decided 2026-10-28:** payload holds only the chosen source material and settings; no student data or user identifiers) | DOC-19; PLN-09; DD:DOC-19 | READY-IBR | P2 |
| PS-AIQ-12 | A notification is sent when a job settles while nobody is watching | T:AiJobGenerationTest | READY-VCI | P3 |
| PS-AIQ-13 | The suite passes whatever a developer's `.env` holds (the worker secret) (**after the new commits:** 2154a2f; `TestEnv`, `TestEnvTest`) | D:14.1; PLN-05 | FIX? | P1 |

### Assignments, grading, gradebook (ASG / GRD / GBK)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-ASG-01 | Submission types per setting (file, text, link); link must be http(s); file 20 MB boundary; something must be supplied | T:AssignmentSubmissionServiceTest | READY-VCI | P1 |
| PS-ASG-02 | Blocking order and message for each reason: closed, not published, before available, after available-until, past due without late, attempts used, no submission type on | TC:QUIZ-03 pattern, T:AssignmentSubmissionServiceTest | READY-VCI | P1 |
| PS-ASG-03 | Resubmission after a grade: each is a new attempt, the latest counts, an attempt race gives a friendly message | T:AssignmentSubmissionServiceTest | READY-VCI | P1 |
| PS-ASG-04 | Allowed file types for assignment uploads (allowlist labelled proposed; 20 MB limit existing) (**decided 2026-10-28:** allowlist adopted: PDF, Word, Excel, PowerPoint, text, JPEG, PNG; 20 MB) | MISS-ASG-02; SO:MISS-ASG-02; DD:MISS-ASG-02 | NB | P2 |
| PS-ASG-05 | A late-submission penalty, deduction or grace window (accepted and marked late; no deduction) | AMB-ASG-01; MISS-ASG-01; SO:AMB-ASG-01, MISS-ASG-01 | READY-IBR | P2 |
| PS-ASG-06 | A late submission where late work is allowed is accepted and marked late; where not allowed it is refused with the due-date message | TC:ASG-02 | READY-IBR | P1 |
| PS-GRD-01 | Score clamping: default mode 0..total, rubric criteria 0..criterion maximum summed, percentage rounding, missing rubric refused | T:GradeWorkServiceTest | READY-VCI | P1 |
| PS-GRD-02 | Transitions: Submitted / Partially Graded → Grading → Graded; reopen returns to Grading and the earlier graded attempt counts again | T:GradeWorkServiceTest | READY-VCI | P1 |
| PS-GRD-03 | Quiz grading by question: overall score is the sum, any typed total ignored | T:GradeWorkServiceTest | READY-VCI | P2 |
| PS-GRD-04 | Feedback thread: instructor message on grade, identical message not repeated, student can reply only after grading | T:AssessmentFeedbackServiceTest | READY-VCI | P2 |
| PS-GRD-05 | A chairperson posting feedback on a submission is treated as an instructor (**after the new commits:** 5140068; feedback uses `roleIn`, chairperson counts as staff [V]) | D:9.1, 9.4, V:inGroup; AMB-ASG-07 | FIX? | P1 |
| PS-GRD-06 | Grading or reopening grading after the grades are finalized (allowed today [A]) (**decided 2026-10-28:** `reopenGrading` has no finalization check [V]) | AMB-ASG-06; SO:AMB-ASG-06; DD | REG | P1 |
| PS-GRD-07 | After grading, the counted score reaches the gradebook, and the final grade recalculates only when the scheme is Published | T:GradebookSyncServiceTest | READY-VCI | P1 |
| PS-GRD-08 | A grading route with an assessment id from another offering answers 404 | T:AssessmentRoutesScopeTest | READY-VCI | P1 |
| PS-GBK-01 | Calculation oracles, table-driven: category %, ungraded left out, excused left out, periods, weight normalisation, 2-decimal rounding | T:GradingEngineServiceTest | READY-VCI | P1 |
| PS-GBK-02 | Scale band lookup at boundaries (96.99 / 97, 75 / 74.99) and a score between stored band ends | T:GradingEngineServiceTest, AMB-ASG-08 | READY-VCI | P1 |
| PS-GBK-03 | A scheme cannot be published unless weights total exactly 100 (and, with periods, each period's categories too); changes refused while finalized | T:GradingSchemeServiceTest | READY-VCI | P1 |
| PS-GBK-04 | A published scheme later edited so weights no longer total 100: engine normalises, publish rule requires 100 (an edit that breaks 100 must be rejected) (**decided 2026-10-28:** `updateCategory` does not re-check 100% [V]) | AMB-ASG-02; SO:AMB-ASG-02; DD | REG | P1 |
| PS-GBK-05 | Manual score 0..max or clear; excused; INC needs a reason and a valid `Y-m-d` date; INC and override still allowed after finalization | T:GradeFinalizationServiceTest | READY-VCI | P1 |
| PS-GBK-06 | Offline sheet: signature, locked cells, reordered columns, row errors skipped, `EX` for excused, apply refused when finalized, CSV BOM, XLSX formulas | T:GradeSheetTest | READY-VCI | P1 |
| PS-GBK-07 | Curve preview, apply, undo; locked once finalized; one live adjustment per target | T:GradeAdjustmentServiceTest | READY-VCI | P2 |
| PS-GBK-08 | Reports: class gradebook, summary, student report, "mine" PDF; audited | T:GradeReportServiceTest | READY-VCI | P2 |
| PS-GBK-09 | "Incomplete" (not yet calculated) versus "INC" (formal incomplete) naming | AMB-ASG-10; SO:AMB-ASG-10 | READY-IBR | P3 |
| PS-GBK-10 | A student sees "Incomplete" and no percentage until the scheme is published | TC:GRD-03 | READY-IBR | P1 |
| PS-GBK-11 | Withdrawn / dropped students are excluded from the engine and tagged | T:GradingEngineServiceTest | READY-VCI | P2 |

### Finalization, override requests, sign-off (FIN / OVR / SGN)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-FIN-01 | Readiness blockers: scheme not published, publish problem, handed-in work waiting, open override requests; finalize blocked while work waits | TC:GBK-04 (draft), T:GradeFinalizationServiceTest | READY-VCI | P1 |
| PS-FIN-02 | Finalize freezes each student's value with missing work counted as 0; an override wins; INC stays INC | T:GradeFinalizationServiceTest | READY-VCI | P1 |
| PS-FIN-03 | Only the instructor finalizes; the instructor and the chairperson can reopen | T:GradeRoutesTest | READY-VCI | P1 |
| PS-FIN-04 | Reopen is refused while a sign-off is current, and the message names the right person when an administrator signed off (today it says "the dean") (**after the new commits:** after 48543c7: the toolbar tag still says "Signed off by the dean" (gradebook-toolbar.php:58) while the detail says the signer's name (line 164) [V]) (**decided 2026-10-28:** wording must be "Signed off by <name>" [V]) | D:2.4; DD:AMB-ASG-04 | REG | P2 |
| PS-FIN-05 | Finalize tells the signer (dean, or admins where there is no dean) and the parents; a failure telling parents does not block; reopen says if grades were already sent to the SIS | T:GradeFinalizationServiceTest | READY-VCI | P2 |
| PS-OVR-01 | Where the institution has any dean, an override is a request and the grade stays until approval; where it has none, it applies at once (0–100, reason, history) | D:2.2, T:GradeOverrideRequestServiceTest | READY-IBR | P1 |
| PS-OVR-02 | First-step routing for every combination of requester role (instructor / chair / dean) and chair, dean, admin availability | D:1.1, 1.5, U5 | READY-IBR | P1 |
| PS-OVR-03 | Endorse, send back (comment required), resubmit, withdraw, approve, reject (reason required) | T:OverrideRequestChainTest | READY-VCI | P1 |
| PS-OVR-04 | With no eligible dean the institution admin decides: approve or reject with a reason, no send-back | D:1.1, 1.3; DOC-22, PLN-03 | READY-IBR | P1 |
| PS-OVR-05 | All institution admins are notified; any one decides; the first decision wins | D:1.4 | READY-IBR | P1 |
| PS-OVR-06 | Nobody decides their own request, admins included; a dean's own request goes to the admin; with one admin it waits and the requester is told it needs a second admin | D:1.5, 1.6 | READY-IBR | P1 |
| PS-OVR-07 | When a dean is assigned, requests waiting for the admin move to the dean | D:1.2 | READY-IBR | P1 |
| PS-OVR-08 | The chair and the requester act at the same moment: only the first counts, the second is told, one final state, one notification | D:16.1 | READY-IBR | P1 |
| PS-OVR-09 | Approve failure restores the previous status; losers see "That request was just acted on" | T:OverrideRequestRaceTest | READY-VCI | P2 |
| PS-OVR-10 | An approved override after sign-off applies and is recorded as a later change | T:GradeSignoffServiceTest | READY-VCI | P2 |
| PS-OVR-11 | Requesting an override while grades are finalized (accepted today [A]; the direct path stays) (**decided 2026-10-28:** `request()` has no finalization check [V]) | AMB-ASG-05; SO:AMB-ASG-05; DD | REG | P2 |
| PS-OVR-12 | A dean who loses `grades.approve` makes waiting requests re-route to the admins (`grade.override_rerouted`), triggered from the permission change | D:7.2, U1e, AMB-ASG-09 | READY-IBR | P1 |
| PS-OVR-13 | Approvals and Decided pages for dean and admin: filters, grade breakdown, reasons shown | T:DeanApprovalsPageTest, InstitutionApprovalsPageTest | READY-VCI | P2 |
| PS-OVR-14 | An instructor (not an admin) is refused on the institution Approvals and Sign-off pages | T:InstitutionApprovalsPageTest (4 tests only) | READY-VCI | P1 |
| PS-OVR-15 | The requester sees that the chair step was skipped and why a request waits ("needs a second admin", "nobody holds the permission") | D:U5, 7.3 | READY-IBR | P2 |
| PS-SGN-01 | States blocked / ready / signed off; an open override request blocks | T:GradeSignoffServiceTest | READY-VCI | P1 |
| PS-SGN-02 | A dean may sign off a section they teach | D:D3 | READY-IBR | P1 |
| PS-SGN-03 | An admin signs off only where the department has no dean (or a dean without `grades.signoff`), holds the permission, and does not teach the section; another admin does; with one admin it waits and they are told | D:2.1, D4; PLN-03 | READY-IBR | P1 |
| PS-SGN-04 | The admin's sign-off works exactly like the dean's: one section or a whole program, reopen with a reason, password check | D:2.4; PLN-03 | READY-IBR | P1 |
| PS-SGN-05 | Sign-offs given stay valid when the signer changes; a new dean can reopen one with a reason | D:2.5 | READY-IBR | P1 |
| PS-SGN-06 | An institution with no deans needs no sign-off | D:2.2 | READY-IBR | P1 |
| PS-SGN-07 | Whether reopening a sign-off asks for the password (reason plus password; password missing today [A]) (**decided 2026-10-28:** reopen needs a reason, not the password (route comment, I4)) | AMB-ASG-04; SO:AMB-ASG-04; DD:AMB-ASG-04 | READY-VCI | P2 |
| PS-SGN-08 | Who may reopen a sign-off an administrator gave, and the wording shown to the instructor ("Signed off by the dean") (a dean may reopen an administrator's sign-off; open: wording for the instructor, reconcile with D:1.1/1.3) (**after the new commits:** after 48543c7: same mixed wording [V]) (**decided 2026-10-28:** the department's decider may reopen an administrator's sign-off; wording must be "Signed off by <name>"; toolbar line 58 says "the dean" [V]) | AMB-ASG-04, GAP-GRD-03; PLN-03; SO:AMB-ASG-04; DD:AMB-ASG-04 | REG | P2 |
| PS-SGN-09 | Program sign-off signs the ready sections, leaves the rest and reports counts | T:GradeSignoffServiceTest | READY-VCI | P2 |
| PS-SGN-10 | Sign-off is not a lock: finalization already locks; reopened sign-off holds later SIS changes | T:SisGradeExporterTest | READY-VCI | P2 |

### Discussions, announcements, calendar, notifications, files (DSC / ANN / CAL / NOT / FIL)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-DSC-01 | Who may view and post: enrolled student, assigned instructor, chair with access; dean and institution admin read-only | T:DiscussionRoutesTest | READY-VCI | P1 |
| PS-DSC-02 | Title required ≤ 255 (create refuses, edit truncates); empty reply refused; no body length limit | T:DiscussionServiceTest | READY-VCI | P2 |
| PS-DSC-03 | Pin, lock, delete others' posts and mark an answer need `discussions.manage` | D:D6 | READY-IBR | P1 |
| PS-DSC-04 | Attachments: allowed types, 25 MB, rejection messages | T:DiscussionServiceTest | READY-VCI | P2 |
| PS-DSC-05 | Reactions toggle, one per person per post; `reply_count` stays correct under concurrent replies | none (read-then-write) | EXP | P3 |
| PS-DSC-06 | Unread tracking, filters (all, pinned, mine, unanswered, modules, unread), sorts, page size 10 | T:DiscussionServiceTest | READY-VCI | P2 |
| PS-DSC-07 | A student's reply completes a discussion item; a locked thread refuses replies | T:DiscussionServiceTest | READY-VCI | P2 |
| PS-DSC-08 | A chairperson posts and moderates as an instructor (**decided 2026-10-28:** 5140068; moderation by course role) | D:9.4; AMB-ASG-07, MISS-GRD-01; DD | FIX? | P1 |
| PS-ANN-01 | Author-only edit and delete; any instructor of the offering pins | T:AnnouncementServiceTest | READY-VCI | P1 |
| PS-ANN-02 | Attachments: allowed types, 25 MB, ≤ 10 a post, only the author's files, orphans purged | T:AnnouncementServiceTest | READY-VCI | P2 |
| PS-ANN-03 | Replies and reactions: any viewer except a dropped student; reply non-empty; one reaction per user, emoji ≤ 32 | T:AnnouncementServiceTest | READY-VCI | P2 |
| PS-ANN-04 | Feed order (pinned, newest), page size 10, search, focus loads enough pages | T:AnnouncementServiceTest | READY-VCI | P3 |
| PS-ANN-05 | A calendar post dated for a later day: when the feed, dashboard, Announcements app, parent tab, calendar and parent notification show it | AMB-COM-01; SO:AMB-COM-01 | REG? | P1 |
| PS-ANN-06 | An author with two roles shows each post once in the course feed | AMB-COM-03; SO:AMB-COM-03 | REG? | P2 |
| PS-ANN-07 | Announcements app: four levels in order, `?q` words all match, `?level` fallback, counts before search, load failure shows an error | none (no page test) | READY-VCI | P1 |
| PS-ANN-08 | Department / institution / platform announcements: visibility, end dates, audience, scope recipients | T:DepartmentAnnouncementServiceTest | READY-VCI | P1 |
| PS-ANN-09 | Draft and scheduled states for course announcements | MISS-COM-01; SO:MISS-COM-01 | NB | P2 |
| PS-ANN-10 | Students and instructors are notified of a new course announcement | MISS-COM-02; SO:MISS-COM-02 | NB | P2 |
| PS-ANN-11 | Reaction message and allowed set for an empty or over-long emoji (**decided 2026-10-28:** allowed: 👍 ❤️ 😂 😮; message "Pick a reaction."; the server accepts any value up to 32 characters today [V]) | AMB-COM-08; DD:AMB-COM-08 | REG? | P3 |
| PS-CAL-01 | Event visibility: student sees only published assessments in published module / unit / item; instructor and chair see drafts muted; archived excluded | T:CalendarEventServiceTest | READY-VCI | P1 |
| PS-CAL-02 | Range is inclusive; invalid `from` / `to` gives 400 | T:CalendarRoutesTest | READY-VCI | P2 |
| PS-CAL-03 | Reminders: owner only (404 otherwise), own courses only, work in a read-only institution, blocked for a closed offering | D:8.2, T:CalendarRoutesTest | READY-IBR | P1 |
| PS-CAL-04 | Schedule grid: Mon–Sun, 30-minute rows, unknown day skipped, short class one row, link to the course | T:ScheduleServiceTest | READY-VCI | P3 |
| PS-CAL-05 | Calendar and schedule for completed or closed offerings | AMB-COM-06; SO:AMB-COM-06 | READY-IBR | P3 |
| PS-CAL-06 | The student schedule route renders (no route test today) | none | READY-VCI | P2 |
| PS-NOT-01 | One notification per user per dedupe key; an urgent one also emails and records `emailed_at` only on success; a failed email keeps the in-app row | T:NotificationsTest | READY-VCI | P1 |
| PS-NOT-02 | Every trigger fires once: backup failed / stale, each subscription state, plan limits at 90% and 100%, term ending, no instructor, invitations expiring, SIS failure | T:NotificationsTest | READY-VCI | P1 |
| PS-NOT-03 | `/notifications` shows the newest 50; the bell loads 7 at a time with `?before`, clamped 1–50 | T:NotificationPagesTest | READY-VCI | P3 |
| PS-NOT-04 | Marking one notification read versus a malformed id marking everything read | AMB-COM-02; SO:AMB-COM-02 | REG? | P2 |
| PS-NOT-05 | Marking read from the bell panel; paging past 50 | MISS-COM-03; SO:MISS-COM-03 | NB | P3 |
| PS-NOT-06 | Override and sign-off events notify the right people: requester, chair, dean, all admins (first decision wins), old and new chair | D:1.4, 2.3, 16.0b, 16.0c | READY-IBR | P1 |
| PS-NOT-08 | Notification links after a student leaves the institution | AMB-COM-07; SO:AMB-COM-07 | READY-IBR | P3 |
| PS-FIL-01 | File access matrix: every file kind × role (owner, logo, institution admin read-only, instructor, student enrolled / completed / dropped, parent, other tenant) answers 404 when denied, never 403 | T:FileAccessServiceTest; PLN-09 | READY-VCI | P1 |
| PS-FIL-02 | A student cannot fetch a resource that is unpublished or behind an unmet prerequisite | T:FileAccessServiceTest | READY-VCI | P1 |
| PS-FIL-03 | A parent can never fetch a file (no test today) | T:ParentAuthorizationMatrixTest (indirect) | READY-VCI | P1 |
| PS-FIL-04 | Range requests ≤ 2 MB slices, 416, inline only for safe types, `nosniff`, private caching | T:FileRouteTest | READY-VCI | P2 |
| PS-FIL-05 | Another student's submission file is not reachable through an announcement attachment id | T:FileAccessServiceTest (gap) | READY-VCI | P1 |
| PS-FIL-06 | Abandoned `uploading` file rows are cleaned (no cleanup today) | AMB-DB-03; SO:AMB-DB-03 | NB | P3 |
| PS-FIL-07 | The institution logo is visible to members of that institution only | T:FileAccessServiceTest (gap) | READY-VCI | P2 |

### Dean, chairperson, parent, student areas (DEA / CHA / PRT / STU)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-DEA-01 | Dashboard attention items appear in the stated priority order from the stated rules | T:DeanAttentionRulesTest | READY-VCI | P2 |
| PS-DEA-02 | Dean reach follows the live assignment; a dean who loses it reaches nothing and sees the explanation page | T:DeanAccessServiceTest, TC:DEAN-11 (draft) | READY-VCI | P1 |
| PS-DEA-03 | Follow-ups: who may ask whom, field limits, one open per sender / recipient / subject, pass-down, either side closes once, overdue rule | T:FollowUpServiceTest | READY-VCI | P1 |
| PS-DEA-04 | Department announcement: audience and scope recipients computed live, in-app only, end keeps history, edit only while active | T:DepartmentAnnouncementServiceTest | READY-VCI | P1 |
| PS-DEA-05 | Teaching load, Monitor Department and the department report show correct figures and exports | T:TeachingLoadServiceTest (calculations unread) | READY-VCI | P2 |
| PS-DEA-06 | Chairperson review lists only chairs' writes in courses the chair does not teach; "reviewed" is the dean's mark | T:ChairpersonReviewServiceTest | READY-VCI | P2 |
| PS-DEA-07 | Dean read-only course API has no write route | T:DeanCourseViewTest | READY-VCI | P1 |
| PS-CHA-01 | Chair reach is the actively chaired sub-departments only; a chair who also instructs is judged by the area's role | T:CourseMembershipTest | READY-VCI | P1 |
| PS-CHA-02 | Every successful chair write in a course is audited as `course.chairperson_action` | T:ChairpersonCourseAccessTest | READY-VCI | P1 |
| PS-CHA-03 | Chair cannot finalize but can reopen | T:GradeRoutesTest | READY-VCI | P1 |
| PS-CHA-04 | Monitor Program is gated by `program.monitor` and not offered to an institution admin by default | D:7.5 | READY-IBR | P1 |
| PS-CHA-05 | Follow-ups: create and pass-down need `followups.manage`; replying to and closing a received one needs none | D:7.6 | READY-IBR | P1 |
| PS-CHA-06 | Chairperson end-to-end journeys (override endorse / send back, course access, monitor) have no catalog cases | none | READY-VCI | P1 |
| PS-PRT-01 | Every parent route is covered by the authorization matrix after any route change; only the two account forms accept a write | T:ParentAuthorizationMatrixTest | READY-VCI | P1 |
| PS-PRT-02 | Dashboard: current versus past terms, attention order at-risk / missing / final, 14-day windows, standing only when the scheme is published | T:ParentDashboardServiceTest | READY-VCI | P1 |
| PS-PRT-03 | Notifier: each event once per parent, emailed only when the child's switch is on, announcements never emailed, no grade in any message, portal off tells nobody | T:ParentNotifierTest | READY-VCI | P1 |
| PS-PRT-04 | Privacy canary: no score, reply, attachment or reaction on parent tabs; a child who is also staff has no grades; deactivated child stays reachable, deleted does not | T:ParentPrivacyCanaryTest; PLN-09 | READY-VCI | P1 |
| PS-PRT-05 | Grade report PDF only once final; no item scores or class mean; each download audited | T:ParentGradeReportTest | READY-VCI | P2 |
| PS-PRT-06 | An at-risk message is sent once per child per course ever (once per episode, re-armed on recovery) | AMB-COM-04; SO:AMB-COM-04 | REG? | P3 |
| PS-PRT-07 | A future-dated announcement notifies parents at once | AMB-COM-01; SO:AMB-COM-01 | REG? | P2 |
| PS-PRT-08 | A parent link across two institutions cannot exist (one account, one institution), so the notifier needs no cross-institution case | D:D1; AMB-COM-05 | READY-IBR | P3 |
| PS-STU-01 | Dashboard widgets and caps (8 tasks, 8 announcements, 6 reminders); no page test exists | T:DashboardServiceTest | READY-VCI | P2 |
| PS-STU-02 | Profile shows parents' names, never emails; "No parent can see your progress." when none | T:StudentProfilePageTest | READY-VCI | P2 |

### Data integrity, frontend, CI (DAT / FE / CI)

| ID | Scenario | Oracle | Status | Pri |
|---|---|---|---|---|
| PS-DAT-01 | Migrations apply cleanly from empty and on a copy holding data; the six tied timestamps; the staged migration's down and re-run (**after the new commits:** seven migrations were added after 27f2012) | none; PLN-05 | CHK | P1 |
| PS-DAT-02 | Database-enforced uniques hold under concurrency: open request, sign-off, finalization, adjustment, follow-up, attempt, enrolment | T:OverrideRequestRaceTest | READY-VCI | P1 |
| PS-DAT-03 | Non-strict SQL mode: over-long strings truncated and invalid enums coerced when written through the query builder | V:schema | EXP | P2 |
| PS-DAT-04 | Constraint probes: non-student enrolment, duplicate `student_number`, `progress_percent` > 100, `passing_percentage` > 100, negative units, capacity 0, end before start, duplicate settings / response rows (**decided 2026-10-28:** `student_number` unique per institution; natural keys; cycles refused in PHP) | none; MISS-DB-01; DD:MISS-DB-01 | REG? | P2 |
| PS-DAT-05 | Tenant consistency: offering, enrolment, submission and gradebook rows that cross institutions | none | EXP | P1 |
| PS-DAT-06 | Seeds: idempotent re-run, id-dependence, the demo admin seeder refuses in production | T:CreateSystemAdminCommandTest | READY-VCI | P2 |
| PS-DAT-07 | Audit rows cannot be altered by a raw query or a cascade | none | EXP | P3 |
| PS-DAT-08 | Meaning of NULL `notifications.institution_id` and `follow_ups.student_id` 0; `program_id` on staff; which `established_year` rule applies; which academic default wins (scoped to the NULL / 0 meaning and `established_year` 1800 to current) | AMB-DB-04, AMB-DB-05, AMB-DB-06, AMB-DB-07; SO:AMB-DB-04, AMB-DB-07 | READY-IBR | P3 |
| PS-DAT-09 | Effective grading scheme precedence (explicit valid scheme, then institution default, then platform default); `user_profiles.program_id` and `active_role` rules (**decided 2026-10-28:** offering scheme, then institution defaults, then platform template; `active_role` only a held role [V]) | AMB-DB-05, AMB-DB-06; DD:AMB-DB-05, AMB-DB-06 | READY-VCI | P3 |
| PS-FE-01 | Automated accessibility check on screens not yet covered: instructor, student, chairperson, parent, course tabs | TC:DEAN-10, IAA-04 pattern | READY-VCI | P2 |
| PS-FE-02 | No sideways scroll at 360, 768, 1440 px on the same screens | TC:DEAN-10 pattern | READY-VCI | P2 |
| PS-FE-03 | Keyboard-only paths: dialogs, editors, quiz taking, menus | TC:IAA-05 pattern | READY-VCI | P2 |
| PS-FE-04 | Dark mode on every area | TC:IAA-07 (spec) | READY-VCI | P3 |
| PS-FE-05 | Loading, empty, error and filled states on each screen (UI guide rule) | docs/ui-guide.md | EXP | P2 |
| PS-FE-06 | Production build: missing manifest raises the "run npm run build" error; development branch | V:vite_helper | CHK | P2 |
| PS-FE-07 | Firefox and WebKit (Playwright runs Chromium desktop only) | none | EXP | P3 |
| PS-CI-01 | The suite passes whatever the local `.env` holds, and CI also runs with a filled sample `.env` (**after the new commits:** 2154a2f; CI also runs with `ci-full.env`; `CiFullEnvTest`) | D:14.1, 14.2; PLN-05 | FIX? | P1 |
| PS-CI-02 | Deploy: environment check, pre-deploy dump, rsync, migrate, worker restart, health poll; migrations are not auto-reverted | .github/workflows | CHK | P2 |
| PS-CI-03 | Smoke test script covers the four checks; the uptime issue opens and closes | T:SmokeTestScriptTest | READY-VCI | P3 |


## Part D. Totals

**415 proposed scenarios** across 51 modules. These are titles, not test cases.

| Status | Count | Meaning |
|---|---|---|
| `READY-IBR` | 76 | expected result from an authoritative requirement |
| `READY-VCI` | 221 | expected result is the code's behavior, confirmed (register I1.3) |
| `REG` | 8 | regression for a verified defect (expected to fail until fixed) |
| `REG?` | 21 | regression for a suspected defect (confirm by running) |
| `FIX?` | 33 | fix committed after `27f2012`, not run |
| `NB` | 17 | authoritative requirement not built yet |
| `BLK` | 0 | blocked: no expected result until a decision is recorded |
| `OUT` | 1 | out of scope by decision |
| `EXP` | 31 | exploratory charter / probe, no oracle |
| `CHK` | 7 | investigation or run, not a test case |

| Priority | Count |
|---|---|
| P1 | 223 |
| P2 | 142 |
| P3 | 50 |

P1 is large (more than half) because most P1 items restate behavior in CRIT and HIGH modules that already has PHPUnit coverage; the plan's waves, not the priority label alone, set the order of work (`qa-test-plan.md` section 12).

| Module | Scenarios | Blocked | Decisions blocking |
|---|---|---|---|
| AUT | 21 | 0 | — |
| REA | 7 | 0 | — |
| IMP | 7 | 0 | — |
| RBA | 14 | 0 | — |
| ROM | 10 | 0 | — |
| SEC | 13 | 0 | — |
| INS | 10 | 0 | — |
| SUB | 8 | 0 | — |
| USR | 5 | 0 | — |
| FLG | 4 | 0 | — |
| SET | 4 | 0 | — |
| AUD | 4 | 0 | — |
| BAK | 8 | 0 | — |
| MON | 5 | 0 | — |
| JOB | 6 | 0 | — |
| STR | 5 | 0 | — |
| CHR | 13 | 0 | — |
| TRM | 7 | 0 | — |
| OFF | 10 | 0 | — |
| ENR | 9 | 0 | — |
| PPL | 5 | 0 | — |
| CSV | 6 | 0 | — |
| PAR | 4 | 0 | — |
| BRD | 2 | 0 | — |
| ACD | 2 | 0 | — |
| IAS | 2 | 0 | — |
| RPT | 4 | 0 | — |
| SIS | 12 | 0 | — |
| MOD | 18 | 0 | — |
| QUZ | 19 | 0 | — |
| ASM | 12 | 0 | — |
| QBK | 10 | 0 | — |
| AIQ | 13 | 0 | — |
| ASG | 6 | 0 | — |
| GRD | 8 | 0 | — |
| GBK | 11 | 0 | — |
| FIN | 5 | 0 | — |
| OVR | 15 | 0 | — |
| SGN | 10 | 0 | — |
| DSC | 8 | 0 | — |
| ANN | 11 | 0 | — |
| CAL | 6 | 0 | — |
| NOT | 7 | 0 | — |
| FIL | 7 | 0 | — |
| DEA | 7 | 0 | — |
| CHA | 6 | 0 | — |
| PRT | 8 | 0 | — |
| STU | 2 | 0 | — |
| DAT | 9 | 0 | — |
| FE | 7 | 0 | — |
| CI | 3 | 0 | — |

## Part E. Sign-off update (2026-10-27)

Applies the reviewer's entries in `decision-register.md` Part F. No test was run and no code was read again: where a decision contradicts today's behavior, the status is `REG?` (suspected from the earlier code reading, tagged [A]) unless the inventory had already verified it ([V]).

| Status | Before | After | Change |
|---|---|---|---|
| `READY-IBR` | 58 | 74 | +16 (decision matches today's behavior, or the behavior is not yet checked) |
| `READY-VCI` | 213 | 213 | none |
| `REG` | 11 | 12 | +1 (PS-USR-05, verified) |
| `REG?` | 10 | 41 | +31 |
| `NB` | 11 | 28 | +17 (required, not built) |
| `BLK` | 69 | 4 | −65 (66 released, 1 added) |
| `EXP` | 34 | 34 | none |
| `CHK` | 7 | 8 | +1 (PS-ASM-11) |
| **Total** | **413** | **414** | +1 (PS-DAT-09) |

**Still blocked (4):**

| Scenario | Waiting for |
|---|---|
| PS-ACD-02 | AMB-ASG-11: the official grade scale is left provisional until the academic supervisor confirms it |
| PS-SIS-11 | MISS-HTTP-01: is an HTTP connector a deliverable? |
| PS-ANN-11 | AMB-COM-08: the supported emoji set is not named |
| PS-DAT-09 | AMB-DB-05, AMB-DB-06: scheme precedence needs the configuration model confirmed; `program_id` / `active_role` rules are not defined |

**Released but with an open sub-point** (write only the decided part):

| Scenario | Open part |
|---|---|
| PS-ASM-05 | exact boundary rules for `available_until` and `due_date` (AMB-ASM-01) |
| PS-QUZ-11 | whether archiving auto-submits a running attempt (AMB-ASM-03) |
| PS-QUZ-16 | the system cancellation rule (AMB-ASM-06) |
| PS-SGN-08 | wording shown to the instructor; reconcile with the administrator fallback of D:1.1 / 1.3 (AMB-ASG-04) |
| PS-AUT-21 | admin unlock, sign-in method list, compromised-password fail-open or fail-closed, session limits |
| PS-CHR-12 | SIS as source of truth for deans |
| PS-MOD-18 | transcoding, thumbnails, duration only where a workflow needs them |
| PS-QBK-09 | OCR for scanned PDFs |
| PS-ASG-04 | the allowlist is labelled "proposed" |
| PS-AIQ-09 | owner, reviewer and dates |
| PS-BAK-07 | the independent monitoring mechanism is a design still to be made |

**Provisional until PLN-01 is closed:** of the 155 scenarios now `READY-IBR`, `REG`, `REG?` or `NB`, 83 cite the decisions document (`D:`). 65 cite sign-off decisions (`SO:`). A few cite both.

**Stale expectations created by the sign-off.** `docs/qa/project-inventory.md` and `qa-test-plan.md` were written before it and still describe these items as open. The plan carries an update note at its top; the inventory is left as a record of the discovery step.

## Part F. Update for the code at `ae86042` (2026-10-28)

**Rule from the owner (2026-10-28):** the newer commits are the single source of truth. Applied as follows:

- Where a commit after `27f2012` **deliberately built** a behavior, that behavior is the expected result, even where an older document or a Part F sign-off said otherwise. Two scenarios changed this way: PS-CHR-08 (waiting requests move on to the dean or administrators) and PS-BAK-07 (24-hour setting).
- Where a commit **fixed** a defect or built a missing feature, the scenario is `FIX?`. A test that pins the fix is named, but **nothing was run**, so none of them is known to pass.
- Where the code **did not change** the behavior, the earlier status stands. Its expected result still comes from the sign-off or the decisions document. Treating today's code as correct there would turn every remaining defect into intended behavior, so this needs the owner's confirmation (see the register, Part H).

Seven commits were checked: `48543c7` (plan 4), `5140068` (plan 5), `cc48ddb`, `5069228` (plan 6), `2154a2f` (plan 7), `67cc8bf` (plan 8), `e4457b0` and `ae86042` (plan 9). Evidence is the commit message, the names of the tests it added, and, where marked [V], a direct read of the code. Assertions inside those tests were not read.

| Change | Scenarios |
|---|---|
| to `FIX?` (31) | PS-IMP-02, -03; PS-INS-04, -05, -06; PS-BAK-02, -03, -04, -05; PS-MON-04, -05; PS-JOB-02, -05, -06; PS-RPT-03; PS-CHR-06, -07; PS-OFF-06, -07, -08; PS-ENR-04, -05, -07; PS-MOD-10, -11; PS-QUZ-05, -06, -07; PS-AIQ-13; PS-CI-01; PS-GRD-05 |
| to `READY-VCI` because the code now decides (2) | PS-CHR-08, PS-BAK-07 |
| note added, status unchanged (4) | PS-FIN-04, PS-SGN-08 (mixed "the dean" wording), PS-ENR-09 (one `inGroup` fallback left), PS-DAT-01 (seven new migrations) |

**Not re-checked:** the 213 `READY-VCI` scenarios (written from the code at `27f2012`), the module rows of Part A (existing-coverage columns do not list the 44 test files added since), and the workflows in Part B. Plans 4–9 touched 107 files under `app/`, so some `READY-VCI` expectations may have moved.

## Part G. Final decisions applied (2026-10-28)

Every remaining decision was made under the owner's delegation (`decision-register.md` Part I). **No scenario is blocked.**

| Status | Count |
|---|---|
| `READY-IBR` | 76 |
| `READY-VCI` | 221 |
| `REG` | 8 |
| `REG?` | 21 |
| `FIX?` | 33 |
| `NB` | 17 |
| `BLK` | 0 |
| `OUT` | 1 |
| `EXP` | 31 |
| `CHK` | 7 |
| **Total** | **415** |

What each status means for the final cases:

- **`READY-IBR`, `READY-VCI`**: write the case. Its expected result is the decision or the code.
- **`REG`, `REG?`**: write the case from the decision. It is expected to fail until the defect is fixed. `REG?` is still only suspected and is confirmed by the first run.
- **`FIX?`**: write the case from the decision or the committed behavior. It is expected to pass, but nothing has been run.
- **`NB`**: write the case, marked "not built".
- **`EXP` / `CHK`**: write a charter or a check, not a step-by-step case.
- **`OUT`**: write nothing.

Changes in this pass: PS-ACD-02, PS-DAT-09, PS-QUZ-11, -12, -13 and PS-SGN-07 move to `READY-VCI`. PS-INS-08 and PS-AIQ-11 move to `READY-IBR`. PS-ENR-03, PS-GBK-04, PS-GRD-06, PS-OVR-11, PS-FIN-04, PS-SGN-08 and PS-BAK-06 move to `REG` (verified). PS-ANN-11, PS-DAT-04 and the new PS-QUZ-19 are `REG?`. PS-RBA-12 and PS-DSC-08 are `FIX?`. PS-SIS-11 is `OUT`.

**Before writing each case.** These modules' code changed after `27f2012`, so their `READY-VCI` expectations must be re-read against `ae86042` as each case is written (register I1.3). Count of `READY-VCI` scenarios per module: CHR 3, OVR 5, SGN 4, FIN 4, ENR 2, QUZ 9, IMP 4, DSC 5, FIL 6, MOD 10, BAK 3, MON 3, JOB 2, RPT 3, INS 4, OFF 4, SIS 8, CAL 4, DEA 7, ROM 2, STR 5, PPL 3, CSV 5.

## Part H. Wave 1 written (2026-10-28)

The 35 Wave 1 scenarios are written as catalog cases in `docs/test-cases/`, and TC-CRS-03, TC-DEAN-04 and TC-DEAN-05 were rewritten. None has been run. The scenario-to-case table and the analysis are in [`wave-1-test-analysis.md`](wave-1-test-analysis.md) section 11.

| File | New cases |
|---|---|
| `p1-critical-journeys.md` | TC-QUIZ-04 to TC-QUIZ-14 |
| `p2-gradebook.md` | TC-GBK-06, TC-GBK-07 |
| `p2-dean.md` | TC-DEAN-12 to TC-DEAN-14; TC-DEAN-04 and TC-DEAN-05 rewritten |
| `p2-institutional-administrator.md` | TC-CRS-04 to TC-CRS-06, TC-IAA-07; TC-CRS-03 rewritten |
| `p2-system-administrator.md` | TC-SA-13 to TC-SA-24 |

Next: Wave 2, the P1 `READY-VCI` and `READY-IBR` scenarios of the critical and high-risk modules.

## Part I. Wave 2 written (2026-10-28)

165 P1 scenarios of the critical and high-risk modules are written as 75 catalog cases (several scenarios share a case), or mapped to an existing case. That includes the high-risk modules' P1 fixes and defects, which no wave had covered. None has been run. The scenario-to-case table is in [`wave-2-test-analysis.md`](wave-2-test-analysis.md) section 7.

Next: Wave 3, the P2 scenarios.

## Part J. Wave 3 written (2026-10-28)

The P2 scenarios, the P1 scenarios of medium-risk modules, and two scenarios earlier cases only mentioned are written as 86 catalog cases (one new file, `p2-chairperson.md`), or mapped to earlier cases. None has been run. Every P1 and P2 scenario with an expected result is now covered. The scenario-to-case table is in [`wave-3-test-analysis.md`](wave-3-test-analysis.md) section 5.

Next: Wave 4, the P3 scenarios, the exploratory charters and checks, and what is still blocked.

## Part K. Wave 4 written, and the plan complete (2026-10-28)

Wave 4 added 30 P3 cases to the catalog, 12 exploratory charters ([`exploratory-charters.md`](exploratory-charters.md)) and 8 checks ([`checks.md`](checks.md)); see [`wave-4-test-analysis.md`](wave-4-test-analysis.md).

**Every one of the 415 scenarios is now placed:** 353 in catalog cases, 31 in charters, 7 in checks, 1 out of scope (PS-SIS-11). No case, charter or check has been run. The next step is execution, starting with the baseline run (CK-01).
