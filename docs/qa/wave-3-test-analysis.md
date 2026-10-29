# Wave 3 test analysis

| | |
|---|---|
| **Date** | 2026-10-28 |
| **Code under test** | `ae86042`; the catalog after Wave 2 (`2d705d0`) |
| **Method** | The `test-case-designer` skill, review gates passed under the owner's delegation, as in Waves 1 and 2. Catalog format. |
| **Status** | Written, not run. |

## 1. Scope

Wave 3 covers the P2 scenarios that no earlier case covered, and three groups that the wave definitions had left out:

- the P1 scenarios of **medium-risk** modules (users, feature flags, the audit log, CSV upload, discussions, announcements, the calendar, the dean's reach, follow-ups, department announcements, the chairperson): Waves 1 and 2 took only critical and high-risk modules;
- PS-ENR-03 and PS-TRM-05, which Wave 2's cases mentioned in a note but did not test;
- the chairperson's cases, in a new file `p2-chairperson.md` as decision MISS-DOC-01 asked.

After this wave every P1 and P2 scenario that has an expected result is written as a case or mapped to one. What is left for Wave 4: the P3 scenarios, the exploratory charters (`EXP`) and checks (`CHK`), and the scenarios still blocked (`BLK`).

## 2. Cases written: 86

| File | Cases |
|---|---|
| `p1-critical-journeys.md` | TC-AUTH-11 to 13, TC-MOD-09, 10, TC-QUIZ-22 to 28, TC-ASG-05, TC-GRD-08, TC-DSC-01, 02, TC-ANN-03 to 08, TC-CAL-01, 02, TC-NOT-01, TC-FIL-02, TC-STU-01, TC-FE-01 (28) |
| `p2-gradebook.md` | TC-GBK-13 to 16 (4) |
| `p2-dean.md` | TC-DEAN-21 to 31 (11) |
| `p2-parent.md` | TC-PRT-15, 16 (2) |
| `p2-institutional-administrator.md` | TC-IAA-13 to 15, TC-STR-08, TC-TRM-07 to 09, TC-OFF-06 to 08, TC-ENR-06, 07, TC-PPL-06, TC-UPL-03, 04, TC-BRD-04, TC-ACD-02, TC-RPT-03 (18) |
| `p2-system-administrator.md` | TC-SA-39 to 56 (18) |
| `p2-chairperson.md` (new) | TC-CHA-01 to 05 (5) |

**Expected to fail on a first run** (defects and missing features): TC-SA-40 and TC-ENR-07 (REG); TC-QUIZ-27, TC-ANN-04, TC-ANN-08, TC-NOT-01, TC-PRT-16, TC-TRM-08, TC-SA-54, TC-SA-55 (REG?); TC-AUTH-13, TC-QUIZ-28, TC-ASG-05, TC-ANN-05, TC-ANN-06, TC-TRM-09, TC-SA-46 (NB). The rest are expected to pass.

## 3. Techniques used

- **Boundaries:** structure codes and names (50; 3 and 255; years 1799, 1800, current, next), term names (2 and 100) and length (800 and 801 days), reminder titles (150 and 151), CSV files (10 MB, 50,000 rows), logos (1 MB; 31, 32, 2000, 2001 px), discussion titles (255 and 256), attachments (25 MB; 10 and 11 files), import limits (5 MB, 200,000 characters, 300 questions), generation counts (0, 1, 50, 51), system status (999 and 1001 ms; disk 89, 90, 97%), backup age (23:59 and 24:01), platform settings at each minimum and maximum.
- **Decision tables:** feature-flag resolution (TC-SA-42, nine rows); who may read, post and moderate a discussion (TC-DSC-02); who a follow-up may go to (TC-DEAN-30).
- **State transitions:** announcement draft → scheduled → published (TC-ANN-05); class times hand-entered ⇄ SIS-locked (TC-OFF-08).
- **Error guessing:** a value only a request sends (TC-NOT-01, TC-QUIZ-27), duplicates from joins (TC-ANN-04), timing (TC-ANN-08, TC-PRT-16), what the database alone must refuse (TC-SA-55), what a delete takes with it (TC-SA-40).

## 4. Found while writing

- The planning scenario PS-MOD-15 says deleting an item leaves its assessment attached to the course. That is not what the code does: deleting a quiz or assignment item deletes its assessment (`ContentDeletionServiceTest`). The code governs; TC-MOD-10 expects the deletion.
- The chairperson's browser tests (`chairperson.spec.js`) and `monitor-program.spec.js` still name no case. `p2-chairperson.md` now gives them cases to name.

## 5. Requirement traceability

**Written in this wave:**

| Scenario | Test case(s) |
|---|---|
| PS-ACD-01 | TC-ACD-02 |
| PS-AIQ-08 | TC-QUIZ-26 |
| PS-AIQ-09 | TC-QUIZ-28 |
| PS-AIQ-10 | TC-QUIZ-27 |
| PS-AIQ-11 | TC-QUIZ-26 |
| PS-ANN-01 | TC-ANN-07 |
| PS-ANN-02 | TC-ANN-03 |
| PS-ANN-03 | TC-ANN-03 |
| PS-ANN-05 | TC-ANN-08, TC-PRT-16 |
| PS-ANN-06 | TC-ANN-04 |
| PS-ANN-07 | TC-ANN-07 |
| PS-ANN-08 | TC-DEAN-31 |
| PS-ANN-09 | TC-ANN-05 |
| PS-ANN-10 | TC-ANN-06 |
| PS-ASG-04 | TC-ASG-05 |
| PS-ASM-05 | TC-QUIZ-23 |
| PS-ASM-10 | TC-QUIZ-22 |
| PS-AUD-01 | TC-SA-43 |
| PS-AUD-02 | TC-SA-43 |
| PS-AUD-03 | TC-SA-44 |
| PS-AUD-04 | TC-IAA-14 |
| PS-AUT-07 | TC-AUTH-11 |
| PS-AUT-08 | TC-AUTH-11 |
| PS-AUT-13 | TC-AUTH-12 |
| PS-AUT-17 | TC-AUTH-13 |
| PS-AUT-20 | TC-AUTH-11 |
| PS-BAK-07 | TC-SA-48 |
| PS-BAK-08 | TC-SA-48 |
| PS-BRD-02 | TC-BRD-04 |
| PS-CAL-01 | TC-CAL-02 |
| PS-CAL-02 | TC-CAL-01 |
| PS-CAL-03 | TC-CAL-02 |
| PS-CAL-06 | TC-CAL-01 |
| PS-CHA-01 | TC-CHA-01 |
| PS-CHA-02 | TC-CHA-02 |
| PS-CHA-03 | TC-CHA-03 |
| PS-CHA-04 | TC-CHA-04 |
| PS-CHA-05 | TC-CHA-05 |
| PS-CHA-06 | TC-CHA-05 |
| PS-CSV-01 | TC-UPL-04 |
| PS-CSV-02 | TC-UPL-03 |
| PS-CSV-03 | TC-UPL-04 |
| PS-CSV-04 | TC-UPL-04 |
| PS-CSV-05 | TC-UPL-03 |
| PS-DAT-04 | TC-SA-55 |
| PS-DAT-06 | TC-SA-56 |
| PS-DEA-01 | TC-DEAN-24 |
| PS-DEA-02 | TC-DEAN-29 |
| PS-DEA-03 | TC-DEAN-30 |
| PS-DEA-04 | TC-DEAN-31 |
| PS-DEA-05 | TC-DEAN-25 |
| PS-DEA-06 | TC-DEAN-26 |
| PS-DEA-07 | TC-DEAN-29 |
| PS-DSC-01 | TC-DSC-02 |
| PS-DSC-02 | TC-DSC-01 |
| PS-DSC-04 | TC-DSC-01 |
| PS-DSC-06 | TC-DSC-01 |
| PS-DSC-08 | TC-DSC-02 |
| PS-ENR-03 | TC-ENR-07 |
| PS-ENR-06 | TC-ENR-06 |
| PS-FE-01 | TC-FE-01 |
| PS-FE-02 | TC-FE-01 |
| PS-FE-03 | TC-FE-01 |
| PS-FIL-04 | TC-FIL-02 |
| PS-FIL-07 | TC-FIL-02 |
| PS-FIN-05 | TC-GBK-16 |
| PS-FLG-01 | TC-SA-42 |
| PS-FLG-03 | TC-SA-42 |
| PS-GBK-07 | TC-GBK-13 |
| PS-GBK-08 | TC-GBK-14 |
| PS-GBK-11 | TC-GBK-15 |
| PS-GRD-04 | TC-GRD-08 |
| PS-IMP-06 | TC-IAA-15 |
| PS-INS-01 | TC-SA-49 |
| PS-INS-08 | TC-SA-51 |
| PS-INS-09 | TC-SA-51 |
| PS-MOD-12 | TC-DEAN-27 |
| PS-MOD-15 | TC-MOD-10 |
| PS-MOD-16 | TC-MOD-09 |
| PS-MON-02 | TC-SA-47 |
| PS-MON-03 | TC-SA-47 |
| PS-NOT-04 | TC-NOT-01 |
| PS-OFF-02 | TC-OFF-06 |
| PS-OFF-06 | TC-OFF-07 |
| PS-OFF-07 | TC-OFF-07 |
| PS-OFF-08 | TC-OFF-08 |
| PS-OVR-13 | TC-DEAN-21 |
| PS-OVR-15 | TC-DEAN-22 |
| PS-PPL-05 | TC-PPL-06 |
| PS-PRT-05 | TC-PRT-15 |
| PS-PRT-07 | TC-PRT-16 |
| PS-QBK-03 | TC-QUIZ-24 |
| PS-QBK-05 | TC-QUIZ-24 |
| PS-QBK-07 | TC-QUIZ-25 |
| PS-RBA-05 | TC-IAA-13 |
| PS-REA-04 | TC-DEAN-28 |
| PS-REA-06 | TC-SA-54 |
| PS-ROM-03 | TC-AUTH-13 |
| PS-RPT-01 | TC-RPT-03 |
| PS-SET-01 | TC-SA-45 |
| PS-SET-02 | TC-SA-45 |
| PS-SET-03 | TC-SA-46 |
| PS-SET-04 | TC-SA-45 |
| PS-SGN-10 | TC-DEAN-23 |
| PS-STR-01 | TC-STR-08 |
| PS-STR-03 | TC-STR-08 |
| PS-STR-05 | TC-STR-08 |
| PS-STU-01 | TC-STU-01 |
| PS-STU-02 | TC-STU-01 |
| PS-SUB-02 | TC-SA-50 |
| PS-SUB-06 | TC-SA-52 |
| PS-SUB-07 | TC-SA-53 |
| PS-SUB-08 | TC-SA-50 |
| PS-TRM-01 | TC-TRM-07 |
| PS-TRM-05 | TC-TRM-09 |
| PS-TRM-06 | TC-TRM-08 |
| PS-USR-01 | TC-SA-39 |
| PS-USR-02 | TC-SA-39 |
| PS-USR-03 | TC-SA-41 |
| PS-USR-04 | TC-SA-41 |
| PS-USR-05 | TC-SA-40 |

**Already covered by an earlier case:**

| Scenario | Test case |
|---|---|
| PS-ACD-02 | TC-GBK-08 |
| PS-ASG-05 | TC-ASG-02, TC-ASG-03 |
| PS-AUT-11 | TC-IAA-08, TC-AUTH-08 |
| PS-CHR-02 | TC-CRS-03 |
| PS-CHR-04 | TC-CRS-04 |
| PS-DSC-03 | TC-IAA-07 |
| PS-DSC-07 | TC-MOD-04 |
| PS-GBK-10 | TC-GRD-03 |
| PS-GRD-03 | TC-GRD-04 |
| PS-IAS-01 | TC-IAA-08 |
| PS-IMP-05 | TC-SA-26 |
| PS-IMP-07 | TC-SA-26 |
| PS-MOD-01 | TC-MOD-02 |
| PS-MOD-05 | TC-MOD-03 |
| PS-OVR-09 | TC-DEAN-18 |
| PS-OVR-10 | TC-DEAN-19 |
| PS-QUZ-12 | TC-QUIZ-17 |
| PS-QUZ-17 | TC-QUIZ-15 |
| PS-RBA-08 | TC-AUTH-05 |
| PS-ROM-10 | TC-IAA-11 |
| PS-SGN-07 | TC-DEAN-13 |
| PS-SGN-09 | TC-DEAN-19 |
