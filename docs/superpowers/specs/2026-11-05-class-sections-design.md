# Block sections (cohorts) — design

Date: 2026-11-05. Branch: `feat/class-sections` (on top of `feat/csv-upload-natural-keys`).
Status: built; the decisions below were made by Claude on the user's instruction to "decide for me".

## Why

Until now a "section" was only a free-text label (`course_offerings.section_code`, e.g. "A") typed on each
offering. An institutional administrator enrolling a class had to enrol the same students in every course by
hand, and the CSV upload could only mislabel a section by a typo. Schools run cohorts: **BSIT 1-A** is a group
of students that takes the same set of courses together in a term.

## What a section is

A **block section** is a cohort for one term, in a program and a year level, with a capacity. It owns one
course offering per course it takes, and its members are enrolled in every one of them.

```
Term 1st Sem 2026-2027
└─ Section BSIT 1-A  (program BSIT, year 1, capacity 40)
     ├─ IT101 → offering IT101 / "BSIT 1-A"
     ├─ IT102 → offering IT102 / "BSIT 1-A"
     └─ students: Ana, Ben, Carla … (enrolled in both)
```

## Decisions (mine, for the user to check)

1. **Keep `section_code`; add a link.** `course_offerings.class_section_id` (nullable) points at the section, and the
   offering's `section_code` is the section's name. Everything that reads offerings (rosters, gradebook, courses,
   grades, messages, SIS) keeps working unchanged. *Why:* the app depends on offerings everywhere; replacing the
   column would touch all of it.
2. **The section's name is unique within the institution's term**, not per program. *Why:* it doubles as every
   offering's section code, and `(course, term, section_code)` is unique; a general-education course taken by two
   programs' blocks must not clash.
3. **Existing offerings are not migrated.** Their `class_section_id` stays empty ("standalone"). *Why:* guessing
   cohorts from "A", "B" would invent groupings that were never decided. An administrator makes the sections they
   want and adds courses to them.
4. **Membership is its own table** (`class_section_students`), and the enrolments are ordinary `enrollments` rows
   made through `EnrolmentService`. *Why:* nothing downstream changes, and every enrolment rule (student role,
   active account, teaching conflicts, SIS lock, audit, gradebook sync) holds without being copied.
5. **Irregular students are not members.** A student taking one course outside their block is enrolled in that
   offering (roster, or upload), as before. Joining a block later never enrols them twice.
6. **Removing is never deleting.** Taking a student out drops their places (status `dropped`); taking a course out
   archives its offering and is only allowed when nobody is enrolled. A section can be archived only when empty.
7. **Permissions reuse the existing ones:** `offerings.manage` for the section and its courses,
   `enrolments.manage` for its students. *Why:* a new permission would need the roles matrix, its docs and its
   tests for no new boundary.
8. **CSV/SIS:** a `section` that names an active block of the term attaches the course to that block (and enrols
   the block's students); it is listed in the preview as a note, not a refusal. Creating blocks and membership by
   file is left out for now; the screens do it.

## Non-goals

Timetable clash checking between a block's courses, prerequisites, waitlists, automatic year promotion, and
migrating existing section codes.

## Where

Migration `2026-11-22-000001_CreateClassSections`; `ClassSectionService`; `SectionsController`; views
`pages/institution/sections.php` and `section.php`; routes under `institution/sections`; the Sections tile and
menu entry. Tests: `ClassSectionServiceTest`, `InstitutionSectionsTest`, and a block case in `CsvUploadServiceTest`.
Documented in `docs/institutional-admin-area.md` (Sections) and TC-SEC-01 to 03.

## Follow-up (next branch)

Emails for accounts created and places given by uploads, manual enrolment, staff assignment and the SIS sync
(including set-password links for new students and instructors), sent in the background.
