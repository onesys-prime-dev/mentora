# Wave 4 test analysis

| | |
|---|---|
| **Date** | 2026-10-28 |
| **Code under test** | `ae86042`; the catalog after Wave 3 (`36afea2`) |
| **Method** | `test-case-designer` for the cases; `exploratory-testing` (SBTM) for the charters. Review gates passed under the owner's delegation. |
| **Status** | Written, not run. |

## 1. Scope

The last wave: the P3 scenarios, the exploratory scenarios (`EXP`), the checks (`CHK`), and the four that were blocked until the decisions of 2026-10-28.

| Kind | Where | Count |
|---|---|---|
| P3 test cases | the P-files (`p1-critical-journeys.md` 15, `p2-institutional-administrator.md` 8, `p2-system-administrator.md` 5, `p2-gradebook.md` 1, `p2-parent.md` 1) | 30 cases |
| Exploratory charters | [`exploratory-charters.md`](exploratory-charters.md): 12 charters, 60–90 minutes each | 31 scenarios |
| Checks | [`checks.md`](checks.md): 8 checks, the baseline run first | 7 scenarios and the baseline |
| Out of scope | none written | PS-SIS-11 (decision MISS-HTTP-01) |

The formerly blocked: PS-ACD-02 is TC-GBK-08 (Wave 2); PS-ANN-11 is TC-ANN-10; PS-DAT-09 is TC-SA-61; PS-SIS-11 is out of scope.

Charters and checks are kept out of the catalog on purpose: the catalog's README defines a case by its expected result, and a charter has none by design.

Mapped, not written again: PS-PAR-03 (TC-PAR-02 tests both switches), PS-REA-07 (TC-SA-25 tests the 300-second window).

## 2. Expected to fail on a first run

TC-ANN-10 (REG? today the server accepts any reaction), TC-ASG-06, TC-UPL-05, TC-SA-57 (REG?), TC-AUTH-15, TC-MOD-12, TC-NOT-03, TC-FIL-03 (NB). The rest are expected to pass.

## 3. Coverage of the whole plan

All 415 planning scenarios now have a place: 353 in catalog cases (written in Waves 1–4 or already in the catalog), 31 in charters, 7 in checks, 1 out of scope. A script checked this against `coverage-matrix.md` Part C, and that every PHP test a case cites exists. The catalog holds 285 cases, none with a repeated ID.

## 4. Found while writing

- The Vite manifest lives at `public/build/.vite/manifest.json`, not `public/build/manifest.json` (CK-07).
- How Shield's 30-day "remember me" combines with the idle limit is not covered by any decision; TC-AUTH-14 takes the code's behavior as the expected result and asks the tester to record it.
