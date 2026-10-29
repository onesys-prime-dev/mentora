# Wave 2 test analysis

| | |
|---|---|
| **Date** | 2026-10-28 |
| **Code under test** | `ae86042`; the catalog after Wave 1 (`b29d2f2`) |
| **Method** | The `test-case-designer` skill, with its two review gates passed under the owner's delegation of 2026-10-28, as in Wave 1. Cases are written in the catalog's format. |
| **Status** | Written, not run. |

## 1. Module overview

Wave 2 covers the P1 scenarios of the critical and high-risk modules whose expected result is today's behavior (`READY-VCI`) or a recorded decision (`READY-IBR`): sign-in and sessions, password re-entry, view-as, permissions, read-only states, web security, the institution lifecycle, subscriptions and plan limits, backups, monitoring and jobs, structure, chairs and deans, terms, sections, enrolment, people, parents, the data export, the SIS, course content, quizzes and authoring, AI generation, assignments, grading, the gradebook, finalization, the override chain, sign-off, notifications, files and the parent portal.

It also takes the high-risk modules' P1 scenarios that are fixed (`FIX?`) or defects (`REG`), which no wave had covered: Wave 1 took only the critical modules' (PS-IMP-02, -03, PS-ENR-04, -05, PS-RPT-03, PS-MOD-10, -11, PS-AIQ-13, PS-CI-01, PS-GRD-05, PS-GRD-06).

## 2. Scope

**In scope:** 165 scenarios. **Out of scope:** P2 (Wave 3); P3, exploratory charters and checks (Wave 4).

Scenarios that test one rule share a case, as the existing catalog does (TC-AUTH-03 holds several checks). Scenarios an existing case already covers were mapped, not written again: PS-AUT-18 (TC-AUTH-03), PS-RBA-08 (TC-AUTH-05), PS-MOD-01 (TC-MOD-02), PS-QUZ-10 (TC-QUIZ-03), PS-ASG-06 (TC-ASG-02), PS-GBK-10 (TC-GRD-03), PS-CHR-01, -02 (TC-CRS-03), PS-CHR-04 (TC-CRS-04), PS-OVR-04 (TC-DEAN-04), PS-SGN-03, -04 (TC-DEAN-05, TC-DEAN-13).

## 3. Cases written

| File | Cases |
|---|---|
| `p1-critical-journeys.md` | TC-AUTH-06 to 10, TC-MOD-03 to 08, TC-QUIZ-15 to 21, TC-ASG-03, 04, TC-GRD-04 to 07, TC-FIL-01 (25) |
| `p2-gradebook.md` | TC-GBK-08 to 12 (5) |
| `p2-dean.md` | TC-DEAN-15 to 20 (6) |
| `p2-institutional-administrator.md` | TC-IAA-08 to 12, TC-STR-07, TC-CRS-07, TC-TRM-04 to 06, TC-OFF-04, 05, TC-ENR-04, 05, TC-PPL-05, TC-PAR-02, TC-RPT-02, TC-SIS-01 to 04 (21) |
| `p2-parent.md` | TC-PRT-11 to 14 (4) |
| `p2-system-administrator.md` | TC-SA-25 to 38 (14) |

75 cases. Expected outcome on a first run: all are expected to pass except TC-GRD-07 (REG: grading after finalizing is not refused today). The `FIX?` ones rest on tests that have not been run.

## 4. Risk and density

Every area here is CRITICAL or HIGH in the plan's risk register, so every case is P1. Density follows the skill's rule (at least three P1 checks per high-risk area): each case holds several checks, and the busiest areas (the grade chain, permissions, read-only, the SIS) have four to six cases.

## 5. Techniques used

- **Scenario (layer 1):** every case has its main path first. Smoke for this wave: TC-AUTH-06, TC-GBK-08, TC-DEAN-15, TC-IAA-11.
- **Equivalence and boundary (layer 2):** lockout (4, 5 failures; the 15th minute), password re-entry (299 and 301 seconds), subscription stages (31 and 30 days before; days 14, 15, 44, 45 after; zero-day settings), file sizes (20, 25 and 500 MB, each just under, at and over), video progress (89%, 90%, below 0, above 100), grade bands (96.99 and 97.00; 74.99 and 75.00), plan limits (90%, 100%, exactly full, 1 KB over), enrolment lists (500 and 501 lines), the purge retention (89 and 90 days).
- **Decision tables (layer 3):** where an override request goes first (TC-DEAN-15, seven rows by requester and who exists); who may download a file (TC-FIL-01, by file kind and role); which completion rule applies (TC-MOD-04).
- **State transitions (layer 5):** subscription stages (TC-SA-33); term upcoming ⇄ active → completed and back (TC-TRM-04, 05); submission Submitted → Grading → Graded → reopened (TC-GRD-04); override request statuses (TC-DEAN-16).
- **Error guessing (layer 6):** concurrency (TC-DEAN-18, TC-ASG-04's double press), partial failure (TC-SIS-01, TC-SA-31), a value only a script sends (TC-SA-27, TC-QUIZ-21), information leaks (TC-QUIZ-16, TC-PRT-14, TC-SA-28).
- **Orthogonal arrays (layer 4):** not used.

## 6. Found while writing

- The default grace period is 14 days, not 7, and the end date itself is still "expiring", not "active" (`SubscriptionStatus::at`; TC-SA-33 uses the code's values).
- TC-FIL-01: a submission file reached through an announcement attachment id (PS-FIL-05) has no test; if it is served, that is a defect.
- The catalog's "Two page routes crash" note in `p1-critical-journeys.md` is out of date once TC-MOD-07 passes (commit `2154a2f`).

## 7. Requirement traceability

| Scenario | Test case(s) |
|---|---|
| PS-AIQ-01 | TC-QUIZ-20 |
| PS-AIQ-02 | TC-QUIZ-20 |
| PS-AIQ-03 | TC-QUIZ-20 |
| PS-AIQ-04 | TC-QUIZ-20 |
| PS-AIQ-06 | TC-QUIZ-20, TC-SA-29 |
| PS-AIQ-07 | TC-QUIZ-20 |
| PS-AIQ-13 | TC-SA-38 |
| PS-ASG-01 | TC-ASG-03 |
| PS-ASG-02 | TC-ASG-03 |
| PS-ASG-03 | TC-ASG-04 |
| PS-ASG-06 | TC-ASG-03, TC-ASG-02 |
| PS-ASM-01 | TC-QUIZ-18 |
| PS-ASM-02 | TC-MOD-08 |
| PS-ASM-07 | TC-QUIZ-18 |
| PS-ASM-08 | TC-QUIZ-21 |
| PS-AUT-01 | TC-AUTH-06 |
| PS-AUT-02 | TC-AUTH-06 |
| PS-AUT-04 | TC-IAA-08 |
| PS-AUT-05 | TC-AUTH-07 |
| PS-AUT-06 | TC-AUTH-07 |
| PS-AUT-09 | TC-AUTH-08 |
| PS-AUT-10 | TC-AUTH-09 |
| PS-AUT-12 | TC-AUTH-10 |
| PS-AUT-18 | TC-AUTH-06, TC-AUTH-08, TC-AUTH-09, TC-AUTH-03 |
| PS-BAK-01 | TC-SA-35 |
| PS-CHR-01 | TC-CRS-03 |
| PS-CHR-02 | TC-CRS-03 |
| PS-CHR-03 | TC-DEAN-15 |
| PS-CHR-04 | TC-CRS-04 |
| PS-CHR-05 | TC-DEAN-15 |
| PS-CHR-09 | TC-CRS-07 |
| PS-CHR-10 | TC-CRS-07 |
| PS-CHR-11 | TC-CRS-07 |
| PS-CI-01 | TC-SA-38 |
| PS-DAT-02 | TC-DEAN-18 |
| PS-ENR-01 | TC-ENR-04 |
| PS-ENR-02 | TC-ENR-04 |
| PS-ENR-04 | TC-ENR-05 |
| PS-ENR-05 | TC-ENR-05 |
| PS-ENR-07 | TC-ENR-05 |
| PS-FIL-01 | TC-FIL-01 |
| PS-FIL-02 | TC-FIL-01 |
| PS-FIL-03 | TC-FIL-01 |
| PS-FIL-05 | TC-FIL-01 |
| PS-FIN-01 | TC-GBK-12 |
| PS-FIN-02 | TC-GBK-12 |
| PS-FIN-03 | TC-GBK-12 |
| PS-GBK-01 | TC-GBK-08 |
| PS-GBK-02 | TC-GBK-08 |
| PS-GBK-03 | TC-GBK-09 |
| PS-GBK-05 | TC-GBK-10 |
| PS-GBK-06 | TC-GBK-11 |
| PS-GBK-10 | TC-GRD-03 |
| PS-GRD-01 | TC-GRD-04 |
| PS-GRD-02 | TC-GRD-04 |
| PS-GRD-05 | TC-GRD-06 |
| PS-GRD-06 | TC-GRD-07 |
| PS-GRD-07 | TC-GRD-05 |
| PS-GRD-08 | TC-QUIZ-21 |
| PS-IMP-01 | TC-SA-26 |
| PS-IMP-02 | TC-IAA-12, TC-SA-26 |
| PS-IMP-03 | TC-IAA-12, TC-SA-26 |
| PS-IMP-04 | TC-IAA-12 |
| PS-INS-02 | TC-SA-30 |
| PS-INS-03 | TC-SA-31 |
| PS-INS-07 | TC-SA-32 |
| PS-JOB-01 | TC-SA-37 |
| PS-JOB-03 | TC-SA-37 |
| PS-MOD-01 | TC-MOD-02 |
| PS-MOD-02 | TC-MOD-03 |
| PS-MOD-04 | TC-MOD-04 |
| PS-MOD-06 | TC-MOD-05 |
| PS-MOD-07 | TC-MOD-06 |
| PS-MOD-08 | TC-MOD-06 |
| PS-MOD-10 | TC-MOD-07 |
| PS-MOD-11 | TC-MOD-07 |
| PS-MOD-13 | TC-MOD-06 |
| PS-MON-01 | TC-SA-36 |
| PS-NOT-01 | TC-SA-37 |
| PS-NOT-02 | TC-SA-37 |
| PS-NOT-06 | TC-DEAN-20 |
| PS-OFF-01 | TC-OFF-04 |
| PS-OFF-03 | TC-OFF-04 |
| PS-OFF-04 | TC-OFF-05 |
| PS-OVR-01 | TC-DEAN-15 |
| PS-OVR-02 | TC-DEAN-15 |
| PS-OVR-03 | TC-DEAN-16 |
| PS-OVR-04 | TC-DEAN-17, TC-DEAN-04 |
| PS-OVR-05 | TC-DEAN-17 |
| PS-OVR-06 | TC-DEAN-17 |
| PS-OVR-07 | TC-DEAN-17 |
| PS-OVR-08 | TC-DEAN-18 |
| PS-OVR-12 | TC-DEAN-17 |
| PS-OVR-14 | TC-DEAN-17 |
| PS-PAR-01 | TC-PAR-02 |
| PS-PAR-02 | TC-PAR-02 |
| PS-PPL-01 | TC-PPL-05 |
| PS-PPL-02 | TC-PPL-05 |
| PS-PPL-03 | TC-PPL-05 |
| PS-PRT-01 | TC-PRT-11 |
| PS-PRT-02 | TC-PRT-12 |
| PS-PRT-03 | TC-PRT-13 |
| PS-PRT-04 | TC-PRT-14 |
| PS-PRT-06 | TC-PRT-13 |
| PS-QBK-01 | TC-QUIZ-18 |
| PS-QBK-02 | TC-QUIZ-19 |
| PS-QBK-04 | TC-QUIZ-19 |
| PS-QBK-06 | TC-QUIZ-19 |
| PS-QUZ-01 | TC-QUIZ-17 |
| PS-QUZ-02 | TC-QUIZ-17 |
| PS-QUZ-03 | TC-QUIZ-15 |
| PS-QUZ-04 | TC-QUIZ-15 |
| PS-QUZ-08 | TC-QUIZ-16 |
| PS-QUZ-09 | TC-QUIZ-17 |
| PS-QUZ-10 | TC-QUIZ-17, TC-QUIZ-03 |
| PS-QUZ-11 | TC-QUIZ-17 |
| PS-QUZ-13 | TC-QUIZ-17 |
| PS-RBA-02 | TC-IAA-09 |
| PS-RBA-03 | TC-IAA-09 |
| PS-RBA-04 | TC-IAA-09 |
| PS-RBA-06 | TC-IAA-09 |
| PS-RBA-07 | TC-IAA-09 |
| PS-RBA-08 | TC-AUTH-05 |
| PS-RBA-10 | TC-IAA-10 |
| PS-RBA-11 | TC-IAA-09 |
| PS-RBA-13 | TC-ENR-05 |
| PS-RBA-14 | TC-IAA-09 |
| PS-REA-01 | TC-SA-25 |
| PS-REA-02 | TC-SA-25 |
| PS-REA-03 | TC-SA-25 |
| PS-REA-05 | TC-DEAN-19, TC-SA-25 |
| PS-ROM-01 | TC-IAA-11 |
| PS-ROM-02 | TC-IAA-11 |
| PS-ROM-04 | TC-IAA-11 |
| PS-ROM-05 | TC-IAA-11 |
| PS-ROM-06 | TC-IAA-11 |
| PS-ROM-07 | TC-SA-36 |
| PS-RPT-02 | TC-RPT-02 |
| PS-RPT-03 | TC-RPT-02 |
| PS-SEC-01 | TC-SA-27 |
| PS-SEC-02 | TC-SA-27 |
| PS-SEC-03 | TC-SA-27 |
| PS-SEC-05 | TC-SA-28 |
| PS-SEC-06 | TC-IAA-10 |
| PS-SEC-10 | TC-SA-28 |
| PS-SEC-11 | TC-SA-29 |
| PS-SGN-01 | TC-DEAN-19 |
| PS-SGN-02 | TC-DEAN-19 |
| PS-SGN-03 | TC-DEAN-19, TC-DEAN-05, TC-DEAN-13 |
| PS-SGN-04 | TC-DEAN-19, TC-DEAN-05 |
| PS-SGN-05 | TC-DEAN-19 |
| PS-SGN-06 | TC-DEAN-19 |
| PS-SIS-01 | TC-SIS-01 |
| PS-SIS-02 | TC-SIS-01 |
| PS-SIS-03 | TC-SIS-02 |
| PS-SIS-04 | TC-SIS-02 |
| PS-SIS-05 | TC-SIS-03 |
| PS-SIS-06 | TC-SIS-03 |
| PS-SIS-08 | TC-SIS-02 |
| PS-SIS-09 | TC-SIS-04 |
| PS-STR-02 | TC-STR-07 |
| PS-SUB-01 | TC-SA-33 |
| PS-SUB-03 | TC-SA-34 |
| PS-SUB-04 | TC-SA-34 |
| PS-TRM-02 | TC-TRM-04 |
| PS-TRM-03 | TC-TRM-05 |
| PS-TRM-04 | TC-TRM-05 |
| PS-TRM-07 | TC-TRM-06 |
