# Test case catalog

The list of things Mentora must do, written as cases a person can follow and a
browser test can automate: who, what to set up, what to do, what must happen.
It is the source for QA on staging and for the Playwright end-to-end tests
(`docs/testing.md`). Today it holds the first set, the P1 cases in
[p1-critical-journeys.md](p1-critical-journeys.md).

**Status of this catalog: DRAFT.** The first cases were drafted from the code,
the seed data and the existing automated tests, not from a requirements
document (there is none yet). They say what the application does today. A
person who knows what it *should* do (QA, the product owner) has to confirm
each expected result before it counts as a requirement. Until then a case is
marked `Draft`. Changing a `Draft` expected result is normal and expected.

## Priorities

| Priority | Meaning | Automate in a browser? |
|---|---|---|
| **P1** | The journeys the product exists for. If one breaks, the release is not shippable: sign-in and roles, announcements, working through modules, quizzes, assignments, grading. | Yes, first |
| **P2** | Important features and the common failure cases around the P1 journeys (discussions, calendar, resources, validation messages). | Some |
| **P3** | Everything else: small settings, rare paths, cosmetic checks. | No: manual, or covered by the PHP tests |

## How a case is written

Every case has the same fields:

- **ID**: `TC-<AREA>-<NN>`, never reused. Areas: `AUTH`, `ANN` (announcements),
  `MOD` (modules and progress), `QUIZ`, `ASG` (assignments), `GRD` (grading and
  grades), `PRT` (the parent portal, in [p2-parent.md](p2-parent.md)).
- **Role**: who performs it (Student, Instructor, or Anonymous).
- **Data**: the demo accounts, course and item it uses (see below), so any two
  people run the same case.
- **Steps**: numbered, from a clean start, in plain words a tester can follow.
- **Expected**: what must be true afterwards. This is the part to review hardest.
- **Already automated**: the PHPUnit tests that prove the rule underneath
  (path and test name). These run on every pull request. A browser test adds
  what they cannot: that the page, buttons and scripts really work together.
- **Playwright**: `planned` for P1 cases we will automate; `no` otherwise.
- **Basis**: the code the expected result was read from.
- **Review**: `Draft` (derived from code), or `Confirmed by <name>, <date>`.

"Wording to confirm" in a case means the behaviour was read from the code, but
the exact text on screen was not checked in a browser.

## The demo data every case uses

Cases run on the demo data from `php spark db:seed DatabaseSeeder`
(`app/Database/Seeds/`). One shared password per role:

| Account | Email | Password | Role |
|---|---|---|---|
| Juan Dela Cruz (student1) | `student1@mentora.test` | `Student123!` | Student |
| Pedro Reyes (student2) | `student2@mentora.test` | `Student123!` | Student |
| Maria Santos (student3) | `student3@mentora.test` | `Student123!` | Student |
| Angela Cruz (student4) | `student4@mentora.test` | `Student123!` | Student |
| Michael Villanueva (student5) | `student5@mentora.test` | `Student123!` | Student |
| Maria Santos (instructor1) | `instructor1@mentora.test` | `Instructor123!` | Instructor |
| Joseph Garcia (instructor2) | `instructor2@mentora.test` | `Instructor123!` | Instructor |
| Roberto Diaz Cruz (parent1) | `parent1@mentora.test` | `Parent123!` | Parent of student1 and student5 |
| Linda Reyes (parent2) | `parent2@mentora.test` | `Parent123!` | Parent linked to nobody |

Who is in which course (term: 1st Semester 2026-2027):

| Course and section | Instructor | Students |
|---|---|---|
| **IT101-A** Introduction to Computing (the showcase) | instructor1 | student1 to student5 |
| IT101-B (an empty section) | instructor1 | none |
| **IT102-A** Computer Programming 1 | instructor2 | student1, student2, student3 |
| **IT201-A** Database Systems | instructor2 | student2, student4, student5 |

The seed deliberately leaves each IT101-A student in a different state, so a
case can pick the one that fits:

| Student | State in IT101-A |
|---|---|
| student1 | ahead of the class, all work graded |
| student2 | quiz partly graded, assignment resubmitted after a grade |
| student3 | quiz being graded, assignment handed in late |
| student4 | quiz in progress, assignment missing |
| **student5** | **enrolled, nothing started** (the clean slate most cases use) |

In IT102-A, student3 has completed only the first item (the PDF). In IT201-A all three students have
already taken the quiz.

### Two things to know before running a case

1. **Dates are relative to the day the data was seeded** (for example Quiz 1 is
   open from 10 days before to 3 days after seeding). Reseed on the morning of
   a test run, or the windows will have moved.
2. **Reseeding erases the database.** `php spark migrate:refresh --all`
   followed by `php spark db:seed DatabaseSeeder` empties every table. Run it
   only on a database made for testing, never on one with real data, and never
   on staging without the team agreeing first.

Each case consumes data (a submitted quiz cannot be submitted fresh again), so
a full run needs a fresh seed, and cases that depend on an earlier one say so.

## Adding and reviewing cases

1. Copy the layout of an existing case and use the next free ID in its area.
2. Say which demo account and item it uses; if the seed does not have the right
   state, say what to set up first.
3. Check **Already automated**: if a PHPUnit test already proves the rule, link
   it instead of duplicating it. Write a new PHPUnit test for gaps found
   (TC-AUTH-03's wrong-password check was one: see `AuthFlowTest`).
4. A reviewer changes `Draft` to `Confirmed by <name>, <date>` only after
   running the case on staging and agreeing the expected result is right.
5. When Playwright automates a case, the test names the case ID in its title,
   and the case's Playwright field gets the file name.
