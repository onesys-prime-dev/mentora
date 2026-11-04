# Global Grades page: design

Date: 2026-11-03. Agreed with the user in a question-by-question session (Q1–Q20). This spec covers all
five phases; each phase has its own plan in `docs/superpowers/plans/`.

## 0. Why, success and anti-goal

- **Why:** at term end an instructor opens each course one by one to grade and to finalize. That is the
  pain this page removes.
- **Success:** at term end an instructor grades what is waiting and finalizes every section from this one
  page, and opens a course only for setup (the scheme, a curve, an import). Going from the To grade queue
  to the existing Grade work page is fine, **as long as they land back on the queue**.
- **Anti-goal:** the page being **slower than opening each course**. That would make it a failure even if
  every feature worked. Budget, on 15 sections of 50 students (750 students; above the platform's high
  teaching load of 30 units, about 10 three-unit sections):
  - the page loads in under 1 s (local, demo-sized server);
  - switching section cards swaps only the grid, in under 0.5 s, about what opening one course's Grades
    tab takes;
  - the number of database queries for a page does not grow with the number of sections (a test pins it).
- Because the pain is the instructor's, the **instructor workbench is built first** (phase 1, with the
  shell); the other roles follow (§11).

## 1. What it is

One **Grades** page, opened from the Grades tile in the header Apps menu and the dashboard Quick Access
(both read `global_apps()`), that gathers grades across courses. Every role uses the same layout and sees
only what it already may. It sits on top of the per-course gradebook, which stays where it is: the page
reads the same records, embeds the same grid and calls the same endpoints, so no new rule about who may
change a grade is introduced.

Roles (Q1): **student, parent, instructor, chairperson, dean.** The institutional administrator keeps the
Reports pages and gets no Grades page.

## 2. The shared shell (Q14)

Copied from the global Announcements page (`app/Views/pages/announcements/index.php`):

```
← Back to dashboard
┌ data-page-panel (rounded-3xl white) ──────────────────────┐
│ page-head: [grades] Grades · <role description>           │
│ brand-tint bar: (tab pills with counts)  [Term ▾] [Child/ │
│                 Program ▾]  [search]                      │
│ summary strip: 3–5 metric cards                           │
│ main list: course rows / section rows / queue / grid      │
└───────────────────────────────────────────────────────────┘
```

- One controller, `App\Controllers\Grades\GradesController`, under each role's own area
  (`student/grades`, `parent/grades`, `instructor/grades`, `chairperson/grades`, `dean/grades`), the same
  way Announcements is one controller under three areas. View `pages/grades/index.php` plus a partial per
  role; page script `resources/js/pages/grades/grades.js` (loaded by `page = 'grades'`).
- Every filter (tab, term, section, child, program, page, search) is in the query string, so a view can
  be bookmarked and Back works.
- Four states for every list: loading (skeleton, for parts fetched after load), empty
  (`components/empty-state`), error (the Announcements load-error card with "Try again"), filled.
- Responsive 360–1440 px: the summary cards wrap to two columns on phones; tables and the embedded grid
  scroll sideways inside their card with the name column pinned; pickers stack under the tabs.

| Role | Tabs | Pickers | Description line |
|---|---|---|---|
| Student | none | Term | Your grades in every course, as they stand now. |
| Parent | none | Child, Term | Your child's grades in every course. |
| Instructor | Gradebook · To grade · At risk | Term | Every section you teach. |
| Chairperson | My sections (Gradebook · To grade · At risk) and Program (Sections · At risk) | Term | Your sections and your program's. |
| Dean | Sections · At risk | Program, Term | Every section in your department. |

## 3. Data and speed (Q19)

- Every number comes from the stored `gradebook_records` (the engine keeps them current when work is
  graded, a score changes, or the scheme is recalculated). One batched query per list; no recalculation
  while the page loads.
- Effective percentage: `GradebookRecordModel::effectivePercentage()` (override, then frozen final, then
  calculated). Effective band: a new `GradebookRecordModel::effectiveBandId()` with the same order
  (`overridden_grade_scale_item_id`, then `final_grade_scale_item_id`, then
  `calculated_grade_scale_item_id`). The course Grades tab (`GradeService::buildStudentGrades`) is
  switched to it as well: today it shows the calculated band beside a frozen final percentage, which the
  two pages must not do.
- A grade shows only when the course's scheme is **Published**, as on the course tab. Otherwise the row
  says "Not published yet".
- Section, program and department lists are paginated, 25 per page, and searchable.
- A course's breakdown (periods, categories, standing) is built by the engine for that one course only,
  when its row is opened (`GradeService::buildStudentGrades`, the same code the course tab uses).
- Parity test: for the same student and course, the global page and the course tab show the same
  percentage, band and tag.

## 4. Permissions (Q18)

No new permission. Each part is gated by the permission that already guards its data; a tab whose
permission is missing is not shown, and a role left with no tab has no Grades tile.

| Part | Needs |
|---|---|
| Student page | `courses.view` |
| Parent page | `children.view` (and the `parentchild` filter for the child) |
| Instructor workbench, view | `courses.view` |
| Grid editing, Finalize, Finalize ready sections | `grading.manage` (the existing `coursepermission` checks on those endpoints; without it the grid is read-only) |
| Chairperson Program tab | `program.monitor` |
| Edit as chairperson | `program.monitor` + `grading.manage` |
| Nudges | `followups.manage` |
| Dean page | `department.monitor` (as dean) |
| Dean strip links | `grades.signoff` / `grades.approve` (as dean) |

Closed terms stay read-only through the existing `closedoffering` filter. Viewing as someone writes
nothing (audit decision 3.2): the page skips its one write (the "seen" snapshot, §5.4) while
`Impersonation::active()`. A section a person can no longer reach is a 404, like the course routes.

## 5. Student (Q2–Q6)

### 5.0 Layout decisions from the phase 2 mockups (2026-11-03)

- **The bar:** the left side carries a term summary line, the right side the term picker:
  "**1st Sem 2026–2027** · 7 courses · 21 units · ends Dec 20" (past terms: "ended Dec 20"; units
  exclude withdrawn courses; the date is the term's `end_date`; no date → that part is left out). The
  picker's label is "Change term". No filter pills, no search.
- **The expanded course** is a small table, not a breakdown wall: one column per grading period ("Prelim
  30%"), one row of period percentages ("not yet" when nothing is graded in it), then a "Show
  categories ▾" toggle that reveals each period's categories with weight and percentage, then one
  line: the standing chip, "Passing is {p}%", the curve note when one applies, and **Open course
  grades →**. A scheme without periods shows its categories as the table's columns and has no toggle.

### 5.1 Terms and summary (Q3, Q4)

- A **term picker** listing every term the student has an enrolment in (`enrolled`, `completed`,
  `withdrawn`; never `dropped`), newest first. It opens on the latest `active` term; with none, the latest
  term by start date. `?term=` chooses another.
- **Summary strip:** Units (sum of units of the term's courses, withdrawn ones left out) · **GWA** ·
  Passed · Failed · INC.
  - **GWA** = Σ(scale value × units) / Σ units over the term's courses that are **finalized**, whose scheme
    uses the institution's default scale (`AcademicDefaults::forInstitution()['scaleId']`), and that have a
    band with a `numeric_value`. A failed grade (5.00) counts. Shown with two decimals.
  - Courses left out are named in a short note under the strip: "2 courses not counted: IT102 (in
    progress), GE101 (uses a different scale)". Reasons: in progress, not published, Incomplete, Withdrawn,
    no scale, a different scale.
  - With no default scale at all, the GWA card is hidden; percentages still show.
  - Passed / Failed count finalized courses; INC counts courses with an Incomplete set (`inc_set_at`).

### 5.2 Rows (Q2, Q4)

One row per course of the term: code and title, section, units, instructor(s), **scale grade** (e.g.
**1.75**) with the percentage beside it (88.40%), and a **status chip**:

| Chip | When |
|---|---|
| Not published yet | the scheme is not Published |
| In progress | published, not finalized (the running grade, with its tag: On track / At risk / Incomplete) |
| Finalized | `finalized_at` set, no open sign-off |
| Signed off | an open `grade_signoffs` row (`open_marker = 1`) |
| Withdrawn | the enrolment or the record says Withdrawn |

A course with no scale shows the percentage alone. INC rows show "INC · finish by Dec 20, 2026".

### 5.3 Expanded row (Q5)

The row expands in place (a button with `aria-expanded`; the breakdown is fetched once from
`student/api/grades/courses/{offering}`) into the small table of §5.0: period percentages, categories
behind "Show categories", the standing (On track / At risk / INC with reason and finish-by date),
"Passing is {p}%", "adjusted by your instructor" when a curve applies, and **Open course grades →**
(the course's Grades tab). Items are not listed here; the course tab has them.

### 5.4 Real time (Q6)

- Fresh on every load, from the stored records; no polling, no push.
- Each row: "Updated 5 min ago", from the record's `last_calculated_at`. The engine recalculates only when
  something grade-related happens, so this is the last time the grade could have changed. (`updated_at`
  is not used: the engine rewrites it on every recalculation.)
- **Changed since you last looked:** a table `grades_seen` (viewer, student, offering, signature,
  seen_at) keeps, per viewer and course, a signature of what was shown (effective percentage, band, tag,
  finalized, signed off). A row whose signature differs from the stored one gets a "Changed" badge; then
  the stored one is replaced. A course seen for the first time gets no badge. Parents have their own rows
  (viewer = parent, student = child).

## 6. Parent (Q13)

The student page through the parent boundary (`ParentCourseTabs::grades`): a **child picker**
(`select-link`, only with more than one child), the same term picker and summary, the same rows.
Expanded rows show periods, categories and standing, **never an item, a manual score or an assessment**.
Each finalized row has **Download report** (the existing PDF route, audited as `grades.report_exported`).
The "changed" badge is per parent.

Phase 3 layout (agreed 2026-11-03): the student page's layout, at `parent/children/{id}/grades`
(`parent/grades` opens the first linked child; the portal-off and not-linked pages are the existing parent
ones). The page head carries the existing "Showing [Name ▾]" child switcher (`pages/parent/partials/
child-switcher`, two or more children only) and the description "Your child's grades in every course."
The bar, summary, rows and small breakdown are the student's. Course links and "Open course grades →" go
to the parent's view of the course (`parent/children/{id}/courses/{offering}/{slug}…`); a finalized row
adds **Download report**. A child who is also staff shows nothing, as on the parent course tab
(`ParentAccessService::readsAsStudent`). The parent's app menu gets a Grades tile.

## 7. Instructor workbench (Q7–Q9, Q15, Q16)

- **Section cards** above the content, one per section the instructor teaches this term: code and
  section, students, a waiting-to-grade badge, and a readiness dot (green: ready to finalize; amber: work
  waiting; grey: finalized). They scroll sideways on phones. `?section=` holds the choice; without one the
  page opens on the section with the most waiting work.
- **Gradebook tab:** the existing roster grid, fetched from that section's `…/grades/roster` (an HTML
  fragment) and run by the existing roster JS, the same component as on the course tab. Above it a slim
  **action bar:** readiness status, **Finalize** (the existing checklist dialog), **Download** (class
  sheet or summary), **Open in course →** (scheme, curve, import and reports stay in the course). Every
  cell saves as it is edited, so switching cards loses nothing.
- **To grade tab:** ungraded submissions across all sections, grouped by assessment ("IT101-A · Lab 2 ·
  14 waiting · oldest 3 days"), oldest first, filters for section, type and late work. **Grade →** opens
  the existing Grade work page with a return address; its back link then reads "← Back to To grade" and
  returns to the queue with the same filters. The return address is accepted only when it points at this
  page on this site.
- **At risk tab:** students across the instructor's sections who are at risk **for either reason**, one
  row per student and section, with a reason column and a filter (Grade / Activity / INC):
  - **grade standing:** At risk (failing so far), Failed, or INC ("Failing · 58%", "INC · finish by Dec
    20", "INC · overdue");
  - **activity:** the dashboard's rule (`RosterRiskService`: inactive N days, or behind on published
    items) ("Inactive 9 days", "Behind on 3 items").
  So the dashboard's "At risk students" card and this tab list the same students. No "message the
  student" link until Mentora has an Inbox.
- **What "waiting to grade" means (one definition everywhere):** the Assessments tab's "To grade" rule:
  pending work that can still change the grade (for an assignment, only the latest submission), on
  assessments that are not Archived (`AssessmentOverviewService` / `AssessmentResultService::resultFor`).
  The card badge, the amber dot, the To grade queue, the summary count, and **Finalize's blocker** all use
  it. Phase 1 changes `GradeFinalizationService::ungradedWork` to this rule, so an older, replaced attempt
  no longer blocks finalizing (a behaviour change, agreed).
- **Finalize ready sections:** finalizes, in one confirm, every section whose checklist is clear, through
  the existing finalization; it reports which were done and which were not and why.
- **Download all:** one XLSX with a sheet per section, in the existing class sheet layout.
- Not included: copying a scheme to other sections.

## 8. Chairperson (Q10, Q11)

- **My sections:** the instructor workbench for the sections they teach.
- **Program:** the sections of the sub-departments they chair, as a table (instructor, average, at risk,
  ungraded, finalize status). A row opens that section's grid **read-only**; **Edit as chairperson**
  unlocks it, says the edits are audited (`course.chairperson_action`, as today), and asks a reason for an
  override.
- From the Program tab: **nudge** the instructor through Follow-ups, pre-filled with the issue; a
  **finalization tracker** ("18 of 24 finalized · 4 ready · 2 blocked", with a not-finalized filter and the
  blocker); the **program at-risk list** (the same component as the instructor's); **Download program**.
- The chairperson does **not** finalize for the instructor, **enforced on the server**: the
  finalization routes refuse a chairperson acting in the chairperson area on a section they do not teach
  (phase 4 adds the check to the existing `chairperson/api/courses` finalization routes; reopening stays
  as it is).
- "Read-only until Edit as chairperson" is a **page** behaviour only: the server already lets a
  chairperson edit program grades and audits it, and that stays.

## 9. Dean (Q12)

One **Department** view: a program picker over the same section table with a **Signed off** column. Grids
are always read-only. A strip, "Finalized 40/52 · Signed off 31 · Override requests waiting: 3", whose
counts link to the existing **Sign-off** and **Approvals** pages (decisions, re-authentication and the
audit stay there). Also nudges, the department at-risk list and the department download.

## 10. Entry points (Q17)

- The Grades tile gets a URL per role in `global_apps()`; dean and parent lists get a Grades tile.
- **Tile badges** (menu and Quick Access): instructor and chairperson, waiting to grade; student and
  parent, grades changed since the last visit; dean, sections ready to sign off. Counted by the same
  queries as the page's summary strip.
- "All my grades →" (student, parent) and "All my sections →" (instructor) on each course's Grades tab.
- "See all" on the dashboard cards about grades or at-risk students, to the matching tab.
- Monitor Program and Monitor Department link to the Program / Department tab and back.
- Notifications keep their current links.

## 11. Phases (Q20)

Each phase is its own branch, created and checked before its first edit; no commits by Claude. The
instructor goes first (§0).

1. **Shell and instructor workbench** (`feat/global-grades-shell`): route, controller, shared layout,
  instructor tile URL, term picker, section cards, embedded grid and action bar, To grade (with the
  back-to-queue link on Grade work), At risk, Finalize ready sections, Download all, the speed budget and
  its query-count test.
2. **Student** (`feat/global-grades-student`): student tile URL, summary and GWA, rows, expanded rows,
  `grades_seen` and the Changed badge, `effectiveBandId()` and the parity fix.
3. **Parent** (`feat/global-grades-parent`): child picker, parent boundary, Download report, parent tile.
4. **Chairperson** (`feat/global-grades-chairperson`): My sections and Program tabs, Edit as chairperson,
  nudges, tracker, program download.
5. **Dean and entry points** (`feat/global-grades-dean`): Department view and strip, dean tile, tile
  badges, the course-tab, dashboard and Monitor links, Playwright cases for every role.

A separate small change made during the design and kept as its own commit on the phase 1 branch: the
platform's default high teaching load is now **30 units** (`AcademicDefaults::PLATFORM_HIGH_LOAD_UNITS`,
was 21), with the three tests that named 21 updated.

## 12. Defaults taken (accepted with the summary)

- No default scale: no GWA card, percentages still shown.
- Every download is audited (`grades.report_exported` pattern).
- Empty states in the app's voice: "No courses this term" ("No courses yet" when the student has no term at all), "Nothing waiting to grade".
- A section the person can no longer reach: 404.

## 13. Builder notes (calls made after the review; the code settles them)

- **Making the grid embeddable.** The roster fragment and its JS are tied to the course page:
  `api.js` reads `window.coursesConfig.apiBaseUrl`, `roster.js` renders into `#course-content`, the
  fragment has a "Back to Grades" button, and Finalize / Download live in the Grades tab toolbar
  (`partials/gradebook-toolbar.php`), not in the fragment. Phase 1 makes the API base and the container
  parameters (the course tab passes what it uses today, so it does not change), hides the back button when
  embedded, adds a caller-set read-only switch (used by the chairperson and dean later), and reuses the
  finalize dialog from the toolbar partial in the action bar.
- **No lost edits.** Manual scores save after a debounce; switching section cards first flushes pending
  saves and waits for them.
- **Grade work and the way back.** Grade work is not its own page: it is the course page at
  `…/assessments?assessment=X&grade=1`, loaded by `loadGradeWork`, whose next-student navigation rebuilds
  the query string. Phase 1 carries a `return` value (a same-site path to the Grades page only) through
  that navigation and relabels `[data-back-to-assessments]` "← Back to To grade" when it is present.
- **Readiness in batches.** `GradeFinalizationService::readiness()` builds the engine context per
  section, which breaks the query budget. The cards use a batched check of the blockers only (scheme
  Published and without a publish problem, nothing waiting, no open override request). Missing scores
  are a note, as they are today. The full `readiness()` still runs in the Finalize dialog and in
  Finalize ready sections. The query test follows `tests/database/DashboardQueryBudgetTest.php`.
- **Never create a scheme by looking.** A section whose Grades tab was never opened has no scheme;
  `GradingSchemeService::forOffering` would create one and recalculate. The global lists treat no scheme
  as "Not published yet" and never call it, which keeps loads fast and keeps view-as write-free. Opening
  a section's grid (the existing roster endpoint) behaves as the course tab does today.
- **Bulk endpoints repeat the course checks.** Finalize ready sections and Download all sit outside
  `api/courses`, so the `coursemember`, `closedoffering` and `coursepermission` filters do not run there.
  The endpoints check, per section, that the person teaches it (as instructor), that the offering is
  active, and `grading.manage`, and report each refusal by section.
- **"Class sheet"** is `GradeReportController::classSheet` (XLSX, audited as `grades.report_exported`),
  not the importable `GradeSheetController::export`. Download all builds one workbook of those sheets and
  writes one audit entry per section.
