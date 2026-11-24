// The responsive sweep: every screen in SCREENS at every window size (support/responsive.js), checked for
// sideways page scroll, touch targets (phone and tablet sizes), axe findings and, on Linux, a screenshot
// baseline. A normal run checks the smoke sizes in light; RESPONSIVE_FULL=1 (the nightly Responsive
// workflow) checks the whole matrix in light and dark, plus 200% zoom. The WebKit project runs the 375px
// phone only, with the iPhone's own touch.
//
// Later phases append to SCREENS. `as` is an account key from support/sign-in.js (null: signed out);
// `path` is a string or an async (page) => string, for a screen whose address has to be looked up first;
// `scope` limits the touch-target and axe checks to part of the page; `overflowScope` limits the overflow
// check to one element (the shell screens own the frame, not the page content; without it the whole page is
// checked); `dialogs` are the ids of dialogs inside the scope to open and check at the touch sizes where
// their opener shows (the ☰ menu has none from lg up), or { id, open } for a dialog opened through the page's
// own controls (open(page) does it, see via()), which is also checked for fitting the window and by axe.
import { expect, test } from "@playwright/test";
import { dialogPanel, expectAccessible, expectInViewport, expectNoPageOverflow, expectNoPanelOverflow, expectTouchTargets, setTheme, sizes, snap, zoom200 } from "./support/responsive.js";
import { runSql } from "./support/database.js";
import { confirmPassword, dateIn, signInAsAdmin } from "./support/admin.js";
import { courseAddress, openCourse, openItem, openModule, openTab, signInAs } from "./support/course.js";
import { accounts, submitLoginForm } from "./support/sign-in.js";
import { disableMessages, enableMessages } from "./support/messages.js";

const SCREENS = [
    { name: "login", path: "/login", as: null },
    {
        name: "ui-kit",
        path: "/ui-kit",
        as: "student",
        scope: "[data-responsive-kit]",
        dialogs: ["kit-fullscreen", "kit-sheet", "kit-menu", "kit-resp-confirm", "kit-comp-form"],
    },
    // Header panels are not dialogs; the shell tests below open them.
    { name: "student-shell", path: "/notifications", as: "student", scope: "[data-app-header]", overflowScope: "[data-app-header]" },
    {
        name: "institution-shell",
        path: "/institution/dashboard",
        as: "institutionAdmin",
        scope: "[data-app-header], [data-institution-menu]",
        overflowScope: "[data-app-header]",
        dialogs: ["nav-menu"],
    },
    { name: "student-dashboard", path: "/student/dashboard", as: "student", scope: "main" },
    { name: "student-courses", path: "/student/courses", as: "student", scope: "main" },
    { name: "student-course-overview", path: (page) => coursePath(page, "overview"), as: "student", scope: "main" },
    { name: "student-course-announcements", path: (page) => coursePath(page, "announcements"), as: "student", scope: "main" },
    { name: "student-module-item", path: moduleItemPath, as: "student", scope: "main" },
    // The seed's reading item the clean-slate student can open first. There is no PDF row: the only PDFs in
    // student5's courses (IT101-A "Parts of a Computer", "Data Types Cheat Sheet") sit behind prerequisites, and
    // IT102-A's PDFs are in a course student5 is not enrolled in, so this student cannot open one.
    { name: "student-item-reading", path: (page) => moduleItemPath(page, READING_TITLE), as: "student", scope: "main" },
    // Scoped to the loaded list, so open() waits for it instead of checking the course skeleton.
    { name: "student-assessments", path: (page) => coursePath(page, "assessments"), as: "student", scope: "[data-assessments-overview]" },
    // Scoped to main once calendar.js has drawn the month, so open() never checks the server skeleton.
    { name: "student-calendar", path: "/student/calendar", as: "student", scope: "main:has([data-calendar-cell])" },
    { name: "student-schedule", path: "/student/schedule", as: "student", scope: "main" },
    // The menu entry redirects to the first child's calendar (/parent/children/{id}/calendar).
    { name: "parent-calendar", path: "/parent/calendar", as: "parent", scope: "main:has([data-calendar-cell])" },
    // Scoped to main once the course rows have rendered.
    { name: "student-grades", path: "/student/grades", as: "student", scope: "main:has([data-grades-row])" },
    { name: "parent-grades", path: "/parent/grades", as: "parent", scope: "main:has([data-grades-row])" },
    // Messages ships off: the file switches it on once (beforeAll). Scoped to the app once its list has rows.
    { name: "student-messages", path: "/messages", as: "student", scope: "#messages-app:has([data-role=rows] button)" },
    { name: "student-notifications", path: "/notifications", as: "student", scope: "[data-notifications-page]" },
    { name: "student-announcements", path: "/student/announcements", as: "student", scope: "main" },
    { name: "instructor-dashboard", path: "/instructor/dashboard", as: "instructor", scope: "main:has([data-phone-tabs])" },
    // Scoped to main once the selected student's work has rendered (the list says who is selected), not the skeleton.
    { name: "instructor-grade-work", path: (page) => gradeWorkPath(page), as: "instructor", scope: 'main:has([data-grade-work-student-list]:not([data-selected-student-id=""]))' },
    // A student whose work is graded (the score is locked), for the Regrade dialog.
    {
        name: "instructor-grade-work-graded",
        path: (page) => gradeWorkPath(page, '[data-student-status="Graded"]'),
        as: "instructor",
        scope: 'main:has([data-grade-work-student-list]:not([data-selected-student-id=""]))',
        dialogs: [via("regrade-confirm-modal", (page) => page.locator("[data-regrade-open]"))],
    },
    {
        name: "instructor-quiz-builder",
        path: (page) => quizBuilderPath(page),
        as: "instructor",
        scope: "main:has([data-question-list] [data-question-card])",
        dialogs: [
            via("question-settings-modal", (page) => page.locator("[data-question-list] [data-open-question-settings]").first()),
            via("ai-generate-modal", (page) => page.locator("[data-open-ai-generate]").filter({ visible: true }).first(), openPalette),
            via("import-questions-modal", (page) => page.locator("[data-open-import-questions]")),
            via("export-questions-modal", (page) => page.locator("[data-open-export-questions]")),
        ],
    },
    // IT101-A's Assessments tab, scoped to the loaded list (not the course skeleton).
    {
        name: "instructor-assessments",
        path: (page) => instructorAssessmentsPath(page),
        as: "instructor",
        scope: "[data-assessments-overview]",
        dialogs: [via("assessment-create-modal", (page) => page.locator("[data-create-assessment-button]").filter({ visible: true }).first())],
    },
    // "Assignment 1: Grade Calculator" in the editor, scoped to main once the editor (its settings) has loaded. Its
    // dialogs are opened and closed with Escape: nothing is saved (a settings switch is on in the page only).
    {
        name: "instructor-assessment-editor",
        path: (page) => assessmentEditorPath(page),
        as: "instructor",
        scope: "main:has(#editor-inspector-panel)",
        dialogs: [
            via("assessment-delete-modal", (page) => page.locator("footer [data-delete-assessment]")),
            via("submission-type-modal", (page) => page.locator("[data-configure-submission-type]")),
            via("rubric-editor-modal", (page) => page.locator("[data-rubric-container] [data-edit-rubric]")),
            via("rubric-criteria-editor-modal", (page) => page.locator("#rubric-editor-modal [data-edit-rubric-criteria]").first(), async (page) => {
                await via("rubric-editor-modal", (p) => p.locator("[data-rubric-container] [data-edit-rubric]")).open(page);
            }),
            via("rubric-criteria-create-modal", (page) => page.locator("#rubric-editor-modal [data-add-rubric-criteria]").first(), async (page) => {
                await via("rubric-editor-modal", (p) => p.locator("[data-rubric-container] [data-edit-rubric]")).open(page);
            }),
            via("rubric-selector-modal", (page) => page.locator("[data-rubric-options] [data-use-existing-rubric]"), showRubricOptions),
            via("rubric-create-modal", (page) => page.locator("[data-rubric-options] [data-create-new-rubric]"), showRubricOptions),
            // Last: it leaves the settings side panel open over the page below lg.
            via("assignees-modal", (page) => page.locator("#editor-inspector-panel [data-specific-open]"), async (page) => {
                await openSettings(page);
                await switchOn(page, "[data-specific-toggle]");
            }),
        ],
    },
    // "Quiz 1: Variables and Data Types" in the editor, for its password dialog (the switch is on in the page only).
    {
        name: "instructor-quiz-editor",
        path: (page) => assessmentEditorPath(page, "Quiz 1: Variables and Data Types"),
        as: "instructor",
        scope: "main:has(#editor-inspector-panel)",
        dialogs: [
            via("quiz-password-modal", (page) => page.locator("#editor-inspector-panel :is([data-password-create], [data-password-edit])").filter({ visible: true }).first(), async (page) => {
                await openSettings(page);
                await switchOn(page, "[data-password-toggle]");
            }),
        ],
    },
    // IT101-A's gradebook grid (the Grades tab's ?roster deep link), scoped to main once the script has drawn its rows.
    {
        name: "instructor-gradebook",
        path: (page) => instructorGradesPath(page, "A", "?roster=1"),
        as: "instructor",
        scope: "main:has([data-gradebook-roster] [data-roster-row])",
        dialogs: [via("grade-override-modal", (page) => page.locator("[data-roster-row] [data-open-override]").first())],
    },
    // IT101-A's Grades tab (the toolbar over the assessment list), for the toolbar's dialogs.
    {
        name: "instructor-grades-tab",
        path: (page) => instructorGradesPath(page, "A"),
        as: "instructor",
        scope: "main:has([data-gradebook-toolbar])",
        dialogs: [
            via("grade-curve-modal", (page) => page.locator("[data-open-curve]")),
            via("grade-sheet-import-modal", (page) => rowEntry(page, page.locator('[data-gradebook-toolbar] [data-row]:has([aria-controls="grade-sheet-menu-sheet"])'), "[data-toggle-grade-sheet-menu]", "[data-open-grade-sheet-import]")),
        ],
    },
    // The Grades page across every section, scoped to main once the chosen section's grid has loaded into it.
    { name: "instructor-grades-page", path: "/instructor/grades", as: "instructor", scope: "main:has([data-grades-grid] [data-roster-row])" },
    // The grading scheme editor has no address of its own: IT101-A's Grades tab, then its Grading Scheme button.
    // Its "+ Add manual item" opens the grade item dialog (closed with Escape: nothing is saved).
    {
        name: "instructor-scheme-editor",
        path: (page) => instructorGradesPath(page, "A"),
        then: openSchemeEditor,
        as: "instructor",
        scope: "main:has([data-grading-scheme-editor])",
        dialogs: [via("grade-item-create-modal", (page) => page.locator("[data-grading-scheme-editor] [data-add-grade-item]").first())],
    },
    // IT101-A's Modules tab, scoped to main once the module cards have loaded into it (not the course skeleton).
    {
        name: "instructor-modules",
        path: (page) => instructorModulesPath(page),
        as: "instructor",
        scope: "main:has(#modules-container [data-module-wrapper])",
        dialogs: [
            via("module-editor-modal", (page) => rowEntry(page, moduleRow(page), "[data-module-actions]", "[data-edit-module]")),
            via("module-dependencies-modal", (page) => rowEntry(page, moduleRow(page), "[data-module-actions]", "[data-module-dependencies]"), null, (dialog) => primaryReady(dialog, "[data-dependencies-save]")),
            via("content-transfer-modal", (page) => rowEntry(page, moduleRow(page), "[data-module-actions]", '[data-transfer][data-transfer-action="copy"]'), null, (dialog) => primaryReady(dialog, "[data-transfer-next]")),
        ],
    },
    // IT101-A's "Getting Started" module page (?module=…&view=1), scoped to main once its units have loaded.
    {
        name: "instructor-module",
        path: (page) => instructorModulePath(page),
        as: "instructor",
        scope: "main:has([data-learning-unit-list] [data-learning-unit-row])",
        dialogs: [
            via("learning-unit-editor-modal", (page) => rowEntry(page, page.locator("[data-learning-unit-row]").first(), "[data-learning-unit-actions]", "[data-edit-learning-unit]")),
            via("learning-item-create-modal", (page) => page.locator("[data-create-learning-item-button]").filter({ visible: true }).first()),
            via("learning-item-dependencies-modal", (page) => rowEntry(
                page,
                page.locator("[data-learning-item-wrapper]").filter({ has: page.getByText("How Computers Represent Data", { exact: true }) }),
                "[data-learning-item-actions]",
                "[data-learning-item-dependencies]",
            ), null, (dialog) => primaryReady(dialog, "[data-dependencies-save]")),
        ],
    },
    // The learning item editor (?item=…&editor=1) of a reading, a video and a PDF in "Getting Started", scoped to main
    // once the editor has loaded (the reading's once Tiptap has drawn its text).
    { name: "instructor-item-editor-reading", path: (page) => itemEditorPath(page, READING_TITLE), as: "instructor", scope: "main:has([data-learning-item-editor] #reading-editor [contenteditable])" },
    { name: "instructor-item-editor-video", path: (page) => itemEditorPath(page, "Course Orientation Video"), as: "instructor", scope: "main:has([data-learning-item-editor] [data-video-editor])" },
    { name: "instructor-item-editor-pdf", path: (page) => itemEditorPath(page, "Parts of a Computer"), as: "instructor", scope: "main:has([data-learning-item-editor] [data-pdf-editor])" },
    { name: "instructor-item-editor-link", path: (page) => itemEditorPath(page, "Course Syllabus and Class Policies"), as: "instructor", scope: "main:has([data-learning-item-editor] [data-link-editor])" },
    // Scrolled to the end: at the top its action row lies under the pinned footer (axe counts a covered button as too small).
    { name: "instructor-item-editor-quiz", path: (page) => itemEditorPath(page, "Course Pre-Test"), then: scrollToEnd, as: "instructor", scope: "main:has([data-learning-item-editor] [data-open-assessment])" },
    // IT101-A's Overview, Announcements, Resources (the "Course Orientation" unit's files) and Discussions tabs as
    // instructor1 sees them, each scoped to main once the tab's own content has loaded (not the course skeleton).
    { name: "instructor-course-overview", path: (page) => instructorCoursePath(page, "overview"), as: "instructor", scope: "main:has([data-overview-page])" },
    { name: "instructor-announcements", path: (page) => instructorCoursePath(page, "announcements"), as: "instructor", scope: "main:has([data-announcements-page] [data-announcement-card])" },
    { name: "instructor-resources", path: (page) => resourcesUnitPath(page), as: "instructor", scope: "main:has([data-resources-list] [data-resource-row])" },
    // With one thread of the file's own (seeded by its marker on every visit, removed in afterAll): the seed has none.
    { name: "instructor-discussions", path: (page) => discussionsPath(page), as: "instructor", scope: "main:has([data-discussions-results] [data-thread-open])" },
    { name: "parent-dashboard", path: "/parent/dashboard", as: "parent", scope: "main:has([data-parent-course])" },
    // #course-content loses aria-busy once the tab's fragment has loaded.
    { name: "parent-course", path: parentCoursePath, as: "parent", scope: "main:has(#course-content:not([aria-busy]))" },
    { name: "parent-profile", path: "/profile", as: "parent", scope: "main" },
    { name: "student-profile", path: "/profile", as: "student", scope: "main" },
    // The chairperson's and the dean's screens (Phase 6). The Grades pages are scoped to main once the selected
    // section's gradebook has loaded into it (not its skeleton). The dean is dean2: dean1's college has no courses,
    // so every one of its pages is an empty state.
    { name: "chairperson-dashboard", path: "/chairperson/dashboard", as: "chairperson", scope: "main" },
    { name: "chairperson-courses", path: "/chairperson/courses", as: "chairperson", scope: "main" },
    { name: "chairperson-monitor-program", path: "/chairperson/monitor-program", as: "chairperson", scope: "main" },
    { name: "chairperson-grades", path: "/chairperson/grades", as: "chairperson", scope: "main:has([data-grades-grid] [data-roster-row])", dialogs: [via("nudge-dialog", (page) => page.locator("[data-nudge]"))] },
    { name: "chairperson-override-requests", path: "/chairperson/override-requests", as: "chairperson", scope: "main" },
    { name: "chairperson-follow-ups", path: "/chairperson/follow-ups", as: "chairperson", scope: "main" },
    { name: "chairperson-announcements", path: "/chairperson/announcements", as: "chairperson", scope: "main" },
    { name: "dean-dashboard", path: "/dean/dashboard", as: "dean2", scope: "main" },
    { name: "dean-courses", path: "/dean/courses", as: "dean2", scope: "main" },
    { name: "dean-grades", path: "/dean/grades", as: "dean2", scope: "main:has([data-grades-grid] [data-roster-row])", dialogs: [via("nudge-dialog", (page) => page.locator("[data-nudge]"))] },
    { name: "dean-approvals", path: "/dean/approvals", as: "dean2", scope: "main" },
    { name: "dean-signoff", path: "/dean/signoff", as: "dean2", scope: "main" },
    { name: "dean-announcements", path: "/dean/announcements", as: "dean2", scope: "main" },
    { name: "dean-teaching-load", path: "/dean/teaching-load", as: "dean2", scope: "main" },
    { name: "dean-chairperson-review", path: "/dean/chairperson-review", as: "dean2", scope: "main" },
    { name: "dean-monitor-college", path: "/dean/monitor-college", as: "dean2", scope: "main" },
    // The shared Course Workspace as the chairperson (IT101-A) and the dean (the first course on dean2's Courses
    // page) see it, each scoped to main once the tab's own content has loaded (not the course skeleton).
    ...["chairperson", "dean"].flatMap((role) => [
        { name: `${role}-course-overview`, path: (page) => staffCoursePath(page, role, "overview"), as: role === "dean" ? "dean2" : role, scope: "main:has([data-overview-page])" },
        { name: `${role}-course-modules`, path: (page) => staffCoursePath(page, role, "modules"), as: role === "dean" ? "dean2" : role, scope: "main:has(#modules-container [data-module-wrapper])" },
        { name: `${role}-course-grades`, path: (page) => staffCoursePath(page, role, "grades"), as: role === "dean" ? "dean2" : role, scope: "main:has([data-gradebook-toolbar])" },
    ]),
    // Grade Work as the chairperson reaches it (the Grades tab's ?assessment=…&grade=1, the address their dashboard's
    // Needs Attention links to), with Maria Santos picked. The chairperson grades (grading.manage), so the score bar
    // is theirs. The dean's group has no Grade Work: a row on their Grades tab opens the assessment view instead.
    { name: "chairperson-grade-work", path: (page) => staffGradeWorkPath(page, "chairperson"), then: pickMaria, as: "chairperson", scope: 'main:has([data-grade-work-student-list]:not([data-selected-student-id=""]))' },
    { name: "dean-assessment-view", path: (page) => staffCoursePath(page, "dean", "grades"), then: openAssignmentRow, as: "dean2", scope: "main:has(#course-content [data-assessments-breadcrumb])" },
    // IT101-A's "Getting Started" module page as the chairperson opens it (her dialogs are the instructor's, below).
    { name: "chairperson-course-module", path: staffModulePath, as: "chairperson", scope: "main:has([data-learning-unit-list] [data-learning-unit-row])" },
    // The chairperson's own calendar: the day sheet today's cell opens, and the forms it offers her (Add Assessment,
    // as she holds courses.manage, and Add Reminder), each closed with Escape: nothing is saved.
    {
        name: "chairperson-calendar",
        path: "/chairperson/calendar",
        as: "chairperson",
        scope: "main:has([data-calendar-cell])",
        dialogs: [
            via("calendar-modal", todayCell),
            via("calendar-modal", calendarForm, (page) => openCalendarAction(page, "add-assessment")),
            via("calendar-modal", calendarForm, (page) => openCalendarAction(page, "add-reminder")),
        ],
    },
    // Messages as the chairperson and the dean: New message (with people found), Settings, and New group, which only
    // they may start (with one person picked, so the chip shows). Scoped to the app once its list has loaded.
    ...["chairperson", "dean"].map((role) => ({
        name: `${role}-messages`,
        path: "/messages",
        as: role === "dean" ? "dean2" : role,
        scope: "#messages-app:has([data-state=loading][hidden])",
        dialogs: [
            via("messages-new", (page) => page.locator("[data-role=new]"), null, (dialog) => findPeople(dialog, "[data-role=picker-input]", "[data-role=picker-results] button")),
            via("messages-prefs", (page) => page.locator("[data-role=prefs-open]")),
            via("messages-group", (page) => page.locator("[data-role=new-group]"), null, async (dialog) => {
                await findPeople(dialog, "[data-role=group-search]", "[data-role=group-results] button");
                await dialog.locator("[data-role=group-results] button").first().click();
                await expect(dialog.locator("[data-role=group-chips] li").first()).toBeVisible();
                // The pick redraws the results: focus stays in the dialog (so the sweep's Escape still closes it).
                await expect(dialog.locator("[data-role=group-search]")).toBeFocused();
            }),
        ],
    })),
    // The institution administrator's and the platform administrator's screens (Phase 7). `as: "systemAdmin"` signs in
    // with the emailed code (open() below). The three onboarding pages sit on an institution seeded for the row (seedOnboarding,
    // marker RF7ONB): the seeded ones are onboarded already and redirect to their profile. Ids come from the seeded lists (listedPath). Seeded rows of a task's own
    // carry the marker RF7 and are removed by it (runSql returns no ids), before the test and in finally.
    ...["dashboard", "structure", "terms", "offerings", "terms/copy", "sections", "people", "people/parents", "courses", "roles", "people/upload", "export", "reports", "reports/courses", "reports/at-risk", "integrations", "settings", "settings/parents", "subscription", "audit"].map((p) => ({
        name: `institution-${p.replaceAll("/", "-")}`,
        path: `/institution/${p}`,
        as: "institutionAdmin",
        scope: "main",
        ...(p === "structure" && { dialogs: structureDialogs() }),
        ...(p === "terms" && { dialogs: [via("term-form", (page) => page.locator('[data-dialog-open="term-form"]').first())] }),
        ...(p === "offerings" && { dialogs: offeringDialogs() }),
        ...(p === "sections" && { dialogs: [via("section-add", (page) => page.locator('[data-dialog-open="section-add"]'))] }),
        ...(p === "people" && { dialogs: peopleDialogs() }),
        ...(p === "people/parents" && { dialogs: rowDialogs(["parent-link", "parent-confirm"], "table [data-row]:has([data-dialog-open=parent-confirm])") }),
        ...(p === "courses" && { dialogs: [via("course-add", (page) => page.locator('[data-dialog-open="course-add"]')), ...rowDialogs(["course-edit", "course-confirm"])] }),
    })),
    { name: "institution-offering-schedule", path: (page) => listedPath(page, "/institution/offerings", /\/offerings\/\d+\/schedule$/), as: "institutionAdmin", scope: "main" },
    { name: "institution-terms-copy-plan", path: copyPlanPath, as: "institutionAdmin", scope: "main" },
    { name: "institution-report-offering", path: (page) => listedPath(page, "/institution/reports/courses", /\/reports\/offerings\/\d+/), as: "institutionAdmin", scope: "main" },
    { name: "institution-term-close", path: (page) => listedPath(page, "/institution/terms", /\/terms\/\d+\/close$/), as: "institutionAdmin", scope: "main" },
    {
        name: "institution-section",
        path: (page) => listedPath(page, "/institution/sections", /\/sections\/\d+$/),
        as: "institutionAdmin",
        scope: "main",
        dialogs: [
            ...["section-edit", "section-course", "section-students"].map((id) => via(id, (page) => page.locator(`main [data-dialog-open="${id}"]`).first())),
            // The row "Take out" buttons sit in the rows' ⋯ sheets on a phone; "Archive this section" is always on the page.
            via("section-confirm", (page) => page.locator('[aria-label="Archive this section"] [data-dialog-open="section-confirm"]')),
        ],
    },
    {
        name: "institution-enrolments",
        path: (page) => listedPath(page, "/institution/offerings", /\/enrolments\/offering\/\d+$/),
        as: "institutionAdmin",
        scope: "main",
        dialogs: [
            via("roster-add", (page) => page.locator('[data-dialog-open="roster-add"]')),
            via("roster-status", (page) => rowEntry(page, page.locator("[data-row]").first(), "body", '[data-dialog-open="roster-status"]')),
        ],
    },
    ...["dashboard", "monitoring", "audit-log", "roles", "flags", "institutions", "institutions/new", "plans", "plans/new", "users", "users/new", "settings/general", "settings/security", "backups", "archive"].map((p) => ({
        name: p === "dashboard" ? "admin-home" : `admin-${p.replaceAll("/", "-")}`,
        path: `/admin/${p}`,
        as: "systemAdmin",
        scope: "main",
        ...(p === "audit-log" && { dialogs: [via("audit-settings", (page) => page.locator('[data-dialog-open="audit-settings"]'))] }),
    })),
    ...["", "/edit", "/users", "/audit", "/subscription", "/integrations", "/flags"].map((tail) => ({
        name: `admin-institution${tail.replaceAll("/", "-")}`,
        path: async (page) => `${await listedPath(page, "/admin/institutions", /\/institutions\/\d+$/)}${tail}`,
        as: "systemAdmin",
        scope: "main",
    })),
    // Editing a plan asks for the password first (the reauth filter); answered, the page returns to the edit form.
    { name: "admin-plan-edit", path: (page) => listedPath(page, "/admin/plans", /\/plans\/\d+\/edit$/), then: confirmPlanEdit, as: "systemAdmin", scope: "main" },
    {
        name: "admin-user",
        path: (page) => listedPath(page, "/admin/users", /\/users\/\d+$/),
        as: "systemAdmin",
        scope: "main",
        // The password step a guarded action opens: the reset is answered "confirm your password" here, so nothing is sent.
        dialogs: [via("reauth-dialog", (page) => page.locator("main form[data-dialog-form] button[type=submit]").filter({ hasText: "Send a password reset link" }), (page) => page.route(/\/admin\/users\/\d+\/reset-password$/, (route) => (route.request().method() === "POST" ? route.fulfill({ status: 403, contentType: "application/json", body: JSON.stringify({ error: "reauth_required" }) }) : route.continue())))],
    },
    // The password check every destructive admin action lands on (the plan's edit address redirects to it).
    { name: "admin-reauth", path: (page) => listedPath(page, "/admin/plans", /\/plans\/\d+\/edit$/), as: "systemAdmin", scope: "main:has(input[name=password])" },
    // An institution that has not finished onboarding, seeded by its marker for the row and removed after it.
    { name: "admin-onboarding", path: (page) => onboardingPath(page, ""), seed: seedOnboarding, as: "systemAdmin", scope: "main:has([data-onboarding-steps])" },
    { name: "admin-onboarding-subscription", path: (page) => onboardingPath(page, "/subscription"), seed: seedOnboarding, as: "systemAdmin", scope: "main:has([data-onboarding-steps])" },
    { name: "admin-onboarding-administrator", path: (page) => onboardingPath(page, "/administrator"), seed: seedOnboarding, as: "systemAdmin", scope: "main:has([data-onboarding-steps])" },
];

// RF7ONB: a pending institution (no subscription, no administrator), found by its code (runSql returns no ids).
let onboardingSeeded = false;
const cleanOnboarding = () => runSql("DELETE FROM institutions WHERE code LIKE 'RF7ONB%'");

function seedOnboarding() {
    cleanOnboarding();
    onboardingSeeded = true;
    runSql("INSERT INTO institutions (name, code, status, created_at, updated_at) VALUES ('RF7 Onboarding University', 'RF7ONB', 'pending', NOW(), NOW())");
}

test.beforeAll(() => cleanOnboarding()); // a killed run cannot leave the pending institution behind
test.afterEach(() => {
    if (onboardingSeeded) {
        onboardingSeeded = false;
        cleanOnboarding();
    }
});

/** The seeded institution's onboarding page for a step; the last one is reached by answering the plan step. */
async function onboardingPath(page, step) {
    const base = await listedPath(page, "/admin/institutions?q=RF7ONB", /\/institutions\/\d+\/onboarding$/);

    if (step === "/administrator") {
        await page.goto(`${base}/subscription`);
        await page.locator("select[name=plan_id]").selectOption({ index: 1 });
        await page.locator("input[name=period_start]").fill(dateIn(0));
        await page.locator("input[name=period_end]").fill(dateIn(365));
        await page.getByRole("button", { name: "Continue" }).click();
        await expect(page).toHaveURL(/\/onboarding\/administrator$/);
    }

    return `${base}${step}`;
}

/** Answers the "confirm your password" page the plan form sits behind, then waits for the form. */
async function confirmPlanEdit(page) {
    await confirmPassword(page);
    await expect(page).toHaveURL(/\/plans\/\d+\/edit$/);
}

/**
 * The dean's sign-off and teaching-load pages open on the newest term, and earlier specs add empty ones, so a
 * bare address can show no instructors and no sections. The demo seed's term (the active one) is id 1.
 */
const SEEDED_TERM = "?term=1";

/** The first link on a list page whose address matches `pattern` (not anchored: the address may carry /index.php). */
async function listedPath(page, list, pattern) {
    await page.goto(list);
    const hrefs = await page.locator("a[href]").evaluateAll((links) => links.map((a) => a.getAttribute("href")));
    const paths = hrefs.map((href) => new URL(href, page.url()).pathname);
    const found = paths.find((path) => pattern.test(path));
    expect(found, `a link matching ${pattern} on ${list}`).toBeTruthy();

    return found;
}

/** The copy page with a source term that has offerings and another term as the target, so its offering list shows. */
async function copyPlanPath(page) {
    await page.goto("/institution/terms/copy");
    const path = new URL(page.url()).pathname;
    const read = (name) => page.locator(`select[name="${name}"] option`).evaluateAll((os) => os.filter((o) => o.value).map((o) => ({ value: o.value, text: o.textContent })));
    const sources = (await read("source")).filter((o) => !/\(0 offerings\)/.test(o.text));
    const targets = await read("target");
    // Earlier specs copy offerings into a term, so on a fresh run the first pair can have nothing left to copy
    // (every box disabled): take the first pair whose list still has an offering that can be ticked.
    for (const source of sources) {
        for (const target of targets.filter((t) => t.value !== source.value)) {
            const candidate = `${path}?source=${source.value}&target=${target.value}`;
            await page.goto(candidate);
            if ((await page.locator('[data-copy-form] tbody input[name="offerings[]"]:enabled').count()) > 0) {
                return candidate;
            }
        }
    }
    expect(null, "a source term and a different target with an offering that can still be copied").toBeTruthy();
    return path;
}

// The chairperson holds courses.manage and grading.manage (AuthGroups), so her course pages draw the instructor's
// dialogs (the same files): they are opened the instructor's way on her pages too. "Edit as chairperson" asks first.
for (const [chair, instructor] of [["chairperson-course-modules", "instructor-modules"], ["chairperson-course-module", "instructor-module"], ["chairperson-course-grades", "instructor-grades-tab"]]) {
    // A copy: a later push onto one row's list must not reach the other's.
    SCREENS.find((s) => s.name === chair).dialogs = [...SCREENS.find((s) => s.name === instructor).dialogs];
}
SCREENS.find((s) => s.name === "chairperson-grades").dialogs.push(via("edit-as-chair-dialog", (page) => page.locator("[data-edit-as-chair]")));

/** IT101-A's "Getting Started" module page as the chairperson opens it, by the id its card on her Modules tab carries. */
async function staffModulePath(page) {
    const modules = await staffCoursePath(page, "chairperson", "modules");
    await page.goto(modules);
    const id = await page.locator("[data-module-wrapper]").filter({ hasText: MODULE_TITLE }).first().getAttribute("data-module-id");

    return `${modules}?module=${id}&view=1`;
}

/** The calendar's cell for today (the day sheet's opener). */
async function todayCell(page) {
    return page.locator(`[data-calendar-cell="${await page.locator("#calendar-app").getAttribute("data-today")}"]`);
}

/** The open calendar form's submit button (the calendar shell is already open, so nothing is clicked again). */
function calendarForm(page) {
    return page.locator("#calendar-modal [data-calendar-form] button[type=submit]");
}

/** Today's day sheet, then one of its actions, until that action's form is in the calendar shell. */
async function openCalendarAction(page, action) {
    await via("calendar-modal", todayCell).open(page);
    const button = page.locator(`#calendar-modal [data-calendar-action="${action}"]`);
    await expect(button, action).toBeVisible();
    await button.dispatchEvent("click");
    await expect(page.locator("#calendar-modal [data-calendar-form]")).toBeVisible();
}

/**
 * Types two letters into a people search until it has found someone (the search is debounced and fetched). The
 * results scroll in their own list (and in a short window the panel scrolls too): the last person is scrolled into
 * view, so it is the one measured on screen.
 */
async function findPeople(dialog, input, results) {
    await dialog.locator(input).fill("an");
    await expect(dialog.locator(results).first()).toBeVisible();
    await dialog.locator(results).last().evaluate((el) => el.scrollIntoView({ block: "nearest", behavior: "instant" }));
}

// Messages on for every screen in this file, so each one (its header's Messages icon, the parent course tab) looks
// the same whatever the test order or -g filter; the sync of the course chats is slow, so it runs once.
test.beforeAll(() => enableMessages());

// The other specs share this database and expect Messages off and no chats left (as messages.spec.js leaves it).
test.afterAll(() => disableMessages());

// The discussion thread this file seeds (the seed has none) goes too.
test.afterAll(() => removeThread());

/** The first course card on the parent's dashboard (the address may carry /index.php, so the match is not anchored). */
async function parentCoursePath(page) {
    await page.goto("/parent/dashboard");
    const href = await page.locator("[data-parent-course] a").first().getAttribute("href");

    return new URL(href, page.url()).pathname;
}

const READING_TITLE = "Welcome to Introduction to Computing";

const gradeWorkPaths = new Map();

/**
 * Grade Work for IT101-A's "Assignment 1: Grade Calculator" with one student selected (default Maria Santos, who
 * handed it in late), reached as an instructor would: the item's View Submissions, then the student (from the
 * students panel below lg). The address is the same for the whole run, so it is looked up once per student.
 */
async function gradeWorkPath(page, student = '[data-student-name="Maria Santos"]') {
    if (gradeWorkPaths.has(student)) {
        return gradeWorkPaths.get(student);
    }

    // Looked up at laptop size, where the module outline is a plain list (the address is the same at every size).
    const viewport = page.viewportSize();
    await page.setViewportSize({ width: 1366, height: 768 });
    await openCourse(page, "instructor", "IT101", "A");
    await openModule(page, "Programming Fundamentals");
    await openItem(page, "Assignment 1: Grade Calculator");
    await page.getByRole("button", { name: "View Submissions" }).click();

    const row = page.locator(`[data-grade-work-student]${student}`).first();
    // A click that lands before the page script has attached does nothing, so the step repeats until it takes.
    await expect(async () => {
        if (!(await row.isVisible())) {
            await page.locator("[data-side-panel-toggle]").click({ timeout: 2_000 });
        }
        await row.click({ timeout: 2_000 });
        await expect(page).toHaveURL(/[?&]student=\d+/, { timeout: 3_000 });
    }).toPass({ timeout: 30_000 });

    await page.setViewportSize(viewport);
    const url = new URL(page.url());
    gradeWorkPaths.set(student, url.pathname + url.search);

    return gradeWorkPaths.get(student);
}

let quizBuilderAddress = null;

/**
 * The Quiz Builder for IT101-A's "Quiz 1: Variables and Data Types", reached as an instructor would: the Assessments
 * tab, the quiz's edit button, then the builder. Looked up once at laptop size (the address is the same at every size).
 */
async function quizBuilderPath(page) {
    if (quizBuilderAddress) {
        return quizBuilderAddress;
    }

    const viewport = page.viewportSize();
    await page.setViewportSize({ width: 1366, height: 768 });
    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Assessments");

    const title = "Quiz 1: Variables and Data Types";
    const card = page.locator("[data-assessment]").filter({ hasText: title }).locator("[data-edit-assessment-button]");
    const draftRow = page.locator("[data-edit-assessment-button]").filter({ hasText: title });
    await card.or(draftRow).first().click();
    // A click that lands before the editor's script has attached does nothing, so the step repeats until it takes.
    await expect(async () => {
        await page.locator("[data-enter-quiz-builder]").click({ timeout: 2_000 });
        await expect(page).toHaveURL(/[?&]builder=1/, { timeout: 3_000 });
    }).toPass({ timeout: 30_000 });

    await page.setViewportSize(viewport);
    const url = new URL(page.url());
    quizBuilderAddress = url.pathname + url.search;

    return quizBuilderAddress;
}

let instructorAssessmentsAddress = null;

/** IT101-A's Assessments tab as instructor1 sees it (the address is the same at every size, so it is looked up once). */
async function instructorAssessmentsPath(page) {
    if (!instructorAssessmentsAddress) {
        await openCourse(page, "instructor", "IT101", "A");
        await openTab(page, "Assessments");
        const url = new URL(page.url());
        instructorAssessmentsAddress = url.pathname + url.search;
    }

    return instructorAssessmentsAddress;
}

const assessmentEditorAddresses = new Map();

/**
 * The editor of one of IT101-A's assessments (default "Assignment 1: Grade Calculator"), reached as an instructor
 * would: the Assessments tab, then its edit button (?assessment=…&editor=1). Looked up once per title at laptop size.
 */
async function assessmentEditorPath(page, title = "Assignment 1: Grade Calculator") {
    if (assessmentEditorAddresses.has(title)) {
        return assessmentEditorAddresses.get(title);
    }

    const viewport = page.viewportSize();
    await page.setViewportSize({ width: 1366, height: 768 });
    await openCourse(page, "instructor", "IT101", "A");
    await openTab(page, "Assessments");

    // A card, or a draft's row (its edit button is the row itself).
    const card = page.locator("[data-assessment]").filter({ hasText: title }).locator("[data-edit-assessment-button]");
    const draftRow = page.locator("[data-edit-assessment-button]").filter({ hasText: title });
    // A click that lands before the page script has attached does nothing, so the step repeats until it takes.
    await expect(async () => {
        await card.or(draftRow).first().click({ timeout: 2_000 });
        await expect(page).toHaveURL(/[?&]editor=1/, { timeout: 3_000 });
    }).toPass({ timeout: 30_000 });

    await page.setViewportSize(viewport);
    const url = new URL(page.url());
    assessmentEditorAddresses.set(title, url.pathname + url.search);

    return assessmentEditorAddresses.get(title);
}

const gradesAddresses = new Map();

/** IT101's section's Grades tab as instructor1 opens it (looked up once per section), with an optional query. */
async function instructorGradesPath(page, section, query = "") {
    if (!gradesAddresses.has(section)) {
        const href = await courseAddress(page, "instructor", "IT101", section);
        // Not anchored: the address may carry /index.php before /instructor (the e2e server's indexPage).
        gradesAddresses.set(section, `${new URL(href, page.url()).pathname.match(/\/instructor\/courses\/\d+\/[^/]+/)[0]}/grades`);
    }

    return gradesAddresses.get(section) + query;
}

/** From the Grades tab, the Grading Scheme button (repeated until a tap lands after the page script has attached). */
async function openSchemeEditor(page) {
    const button = page.locator("[data-open-grading-scheme]");
    const editor = page.locator("[data-grading-scheme-editor]");

    await expect(async () => {
        if (await button.isVisible()) {
            await button.click({ timeout: 2_000 });
        }
        await expect(editor).toBeVisible({ timeout: 3_000 });
    }).toPass({ timeout: 30_000 });
}

let instructorModulesAddress = null;

/** IT101-A's Modules tab as instructor1 opens it (the address is the same at every size, so it is looked up once). */
async function instructorModulesPath(page) {
    if (!instructorModulesAddress) {
        const href = await courseAddress(page, "instructor", "IT101", "A");
        // Not anchored: the address may carry /index.php before /instructor (the e2e server's indexPage).
        instructorModulesAddress = `${new URL(href, page.url()).pathname.match(/\/instructor\/courses\/\d+\/[^/]+/)[0]}/modules`;
    }

    return instructorModulesAddress;
}

const MODULE_TITLE = "Getting Started";
let instructorModuleAddress = null;

/** IT101-A's "Getting Started" module page, by the id its card on the Modules tab carries. */
async function instructorModulePath(page) {
    if (!instructorModuleAddress) {
        const modules = await instructorModulesPath(page);
        await page.goto(modules);
        const id = await page.locator("[data-module-wrapper]").filter({ hasText: MODULE_TITLE }).first().getAttribute("data-module-id");
        instructorModuleAddress = `${modules}?module=${id}&view=1`;
    }

    return instructorModuleAddress;
}

/** Once the item editor has loaded, the window scrolled to its end (where the pinned footer covers nothing). */
async function scrollToEnd(page) {
    await expect(page.locator("[data-learning-item-editor] [data-open-assessment]").first()).toBeVisible();
    await page.evaluate(() => globalThis.scrollTo({ top: globalThis.document.documentElement.scrollHeight, behavior: "instant" }));
}

const itemEditorAddresses = new Map();

/** The editor of an item in IT101-A's "Getting Started" (?item=…&editor=1), by the id its row on the module page carries. */
async function itemEditorPath(page, title) {
    if (!itemEditorAddresses.has(title)) {
        await page.goto(await instructorModulePath(page));
        const row = page.locator("[data-learning-item-wrapper]").filter({ has: page.getByText(title, { exact: true }) });
        const id = await row.first().getAttribute("data-learning-item");
        itemEditorAddresses.set(title, `${await instructorModulesPath(page)}?item=${id}&editor=1`);
    }

    return itemEditorAddresses.get(title);
}

let instructorCourseAddress = null;

/** One of IT101-A's tabs as instructor1 opens it (the course's address is looked up once). */
async function instructorCoursePath(page, feature) {
    if (!instructorCourseAddress) {
        const href = await courseAddress(page, "instructor", "IT101", "A");
        // Not anchored: the address may carry /index.php before /instructor (the e2e server's indexPage).
        instructorCourseAddress = new URL(href, page.url()).pathname.match(/\/instructor\/courses\/\d+\/[^/]+/)[0];
    }

    return `${instructorCourseAddress}/${feature}`;
}

let resourcesUnitAddress = null;

/** The Resources tab inside "Getting Started" › "Course Orientation", reached through its folders (looked up once). */
async function resourcesUnitPath(page) {
    if (!resourcesUnitAddress) {
        await page.goto(await instructorCoursePath(page, "resources"));
        // A click that lands before the page script has attached does nothing, so each step repeats until it takes.
        await expect(async () => {
            await page.locator("[data-resources-open-module]").filter({ hasText: MODULE_TITLE }).click({ timeout: 2_000 });
            await expect(page.locator("[data-resources-open-unit]").first()).toBeVisible({ timeout: 3_000 });
        }).toPass({ timeout: 30_000 });
        await expect(async () => {
            await page.locator("[data-resources-open-unit]").filter({ hasText: "Course Orientation" }).click({ timeout: 2_000 });
            await expect(page).toHaveURL(/[?&]unit=\d+/, { timeout: 3_000 });
        }).toPass({ timeout: 30_000 });
        const url = new URL(page.url());
        resourcesUnitAddress = url.pathname + url.search;
    }

    return resourcesUnitAddress;
}

const THREAD_TITLE = "RF9D-Week 1 questions";
const offeringIt101A = "(SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id WHERE c.course_code = 'IT101' AND o.section_code = 'A')";
// By its marker (runSql returns no ids); its replies, reactions and reads go with it (ON DELETE CASCADE).
const removeThread = () => runSql("DELETE FROM discussion_threads WHERE title LIKE 'RF9D-%'");

/** A thread by instructor1 in IT101-A with one reply by student2, made fresh (any earlier one removed first). */
function seedThread() {
    removeThread();
    const body = JSON.stringify({ version: 1, blocks: [{ type: "paragraph", content: [{ text: "Ask anything about the first week here.", marks: [] }] }] });
    runSql(`INSERT INTO discussion_threads (offering_id, author_id, title, body, reply_count, last_activity_at, created_at, updated_at)
        SELECT ${offeringIt101A}, u.id, '${THREAD_TITLE}', '${body}', 1, NOW(6), NOW(), NOW() FROM users u JOIN auth_identities i ON i.user_id = u.id WHERE i.secret = 'instructor1@mentora.test'`);
    runSql(`INSERT INTO discussion_replies (thread_id, author_id, body, created_at, updated_at)
        SELECT t.id, i.user_id, 'Is the quiz open book?', NOW(), NOW() FROM discussion_threads t JOIN auth_identities i ON i.secret = 'student2@mentora.test' WHERE t.title = '${THREAD_TITLE}'`);
}

/** IT101-A's Discussions tab, with the file's thread seeded. */
async function discussionsPath(page) {
    seedThread();

    return instructorCoursePath(page, "discussions");
}

const staffCourseAddresses = new Map();

/** A course tab as the chairperson (IT101-A) or the dean (the first course their Courses page lists) opens it. */
async function staffCoursePath(page, role, feature) {
    if (!staffCourseAddresses.has(role)) {
        // IT101 A, not the first card: earlier specs add empty courses that can come first on the dean's list.
        const href = await courseAddress(page, role, "IT101", "A");
        // Not anchored: the address may carry /index.php before the role (the e2e server's indexPage).
        staffCourseAddresses.set(role, new URL(href, page.url()).pathname.match(new RegExp(`/${role}/courses/\\d+/[^/]+`))[0]);
    }

    return `${staffCourseAddresses.get(role)}/${feature}`;
}

const staffGradeWorkAddresses = new Map();

/** "Assignment 1: Grade Calculator"'s Grade Work from the course's Grades tab (?assessment=…&grade=1), looked up once. */
async function staffGradeWorkPath(page, role) {
    if (!staffGradeWorkAddresses.has(role)) {
        const grades = await staffCoursePath(page, role, "grades");
        await page.goto(grades);
        const row = page.locator("[data-assessment]").filter({ hasText: "Assignment 1: Grade Calculator" }).first();
        staffGradeWorkAddresses.set(role, `${grades}?assessment=${await row.getAttribute("data-assessment")}&grade=1`);
    }

    return staffGradeWorkAddresses.get(role);
}

/** On Grade Work, picks Maria Santos (from the students panel below lg), repeated until the pick has loaded. */
async function pickMaria(page) {
    await pickStudent(page, "Maria Santos");
}

/** Opens the students panel when it is shut and picks the student, repeated until the page has taken the pick. */
async function pickStudent(page, name) {
    const row = page.locator(`[data-grade-work-student][data-student-name="${name}"]`).first();
    await expect(async () => {
        const id = await row.getAttribute("data-grade-work-student-id", { timeout: 2_000 });
        // Opened only when it is shut (the toggle shows below lg), so a retry never closes it.
        const toggle = page.locator('[data-side-panel-toggle][aria-expanded="false"]');
        if (await toggle.isVisible()) {
            await toggle.click({ timeout: 2_000 });
        }
        await row.click({ timeout: 2_000 });
        await expect(page.locator(`[data-grade-work-student-list][data-selected-student-id="${id}"]`)).toBeAttached({ timeout: 3_000 });
    }).toPass({ timeout: 30_000 });
    // Below lg the students panel closes once a student is picked; wait for that before measuring.
    await expect(page.locator('[data-side-panel-toggle][aria-expanded="true"]')).toHaveCount(0);
}

/** On the Grades tab, a row click on "Assignment 1: Grade Calculator", repeated until its assessment view has loaded. */
async function openAssignmentRow(page) {
    const row = page.locator("[data-assessment]").filter({ hasText: "Assignment 1: Grade Calculator" }).first();
    // The view's breadcrumb is in the page once it has loaded (shown or not: a phone hides it).
    const loaded = page.locator("#course-content [data-assessments-breadcrumb]");
    await expect(async () => {
        if (await row.isVisible()) {
            await row.click({ timeout: 2_000 });
        }
        await expect(loaded).toBeAttached({ timeout: 3_000 });
    }).toPass({ timeout: 30_000 });
}

/** The first course on the student's course list, opened on one of its tabs. */
async function coursePath(page, feature) {
    await page.goto("/student/courses");
    const href = await page.locator('ul[aria-label="Your courses"] a[href*="/student/courses/"]').first().getAttribute("href");

    // Not anchored: the address may carry /index.php before /student (the e2e server's indexPage).
    return `${new URL(href, page.url()).pathname.match(/\/student\/courses\/\d+\/[^/]+/)[0]}/${feature}`;
}

/**
 * The student's first course that has a module with an item they can open, opened on that item (the address the
 * Overview links use: the Modules tab with ?item=). Earlier specs enrol the student in offerings with no content,
 * so the first course on the list is not always the one with modules.
 */
async function moduleItemPath(page, title) {
    await page.goto("/student/courses");
    const hrefs = await page.locator('ul[aria-label="Your courses"] a[href*="/student/courses/"]').evaluateAll((as) => as.map((a) => a.href));
    // Not anchored: the address may carry /index.php before /student (the e2e server's indexPage).
    const bases = [...new Set(hrefs.map((href) => new URL(href).pathname.match(/\/student\/courses\/\d+\/[^/]+/)[0]))];
    expect(bases.length, "the student has a course").toBeGreaterThan(0);

    for (const [i, base] of bases.entries()) {
        const modules = `${base}/modules`;
        await page.goto(modules);

        const card = page.locator("[data-module-wrapper]").first();
        const unlocked = page.locator("[data-learning-item-wrapper]:not([data-locked])");
        const items = title ? unlocked.filter({ hasText: title }) : unlocked;
        // A course with no module (an offering an earlier spec added) is passed over at once.
        await card.or(page.getByText("No modules yet")).first().waitFor({ timeout: 15_000 });
        if ((await card.count()) === 0) continue;
        // A click that lands before the page script has attached does nothing, so the step repeats until it takes.
        try {
            await expect(async () => {
                const content = card.locator("[data-module-content]").first();
                if (!((await content.getAttribute("class")) ?? "").includes("max-h-[2000px]")) {
                    await card.locator("[data-module]").first().click({ timeout: 2_000 });
                }
                await card.getByRole("button", { name: "Go to module" }).click({ timeout: 3_000 });
                await expect(items.first()).toBeAttached({ timeout: 3_000 });
            }).toPass({ timeout: i === bases.length - 1 ? 30_000 : 20_000 });
        } catch (error) {
            if (i === bases.length - 1) throw error;
            continue;
        }

        return `${modules}?item=${await items.first().getAttribute("data-learning-item")}`;
    }

    expect(null, "a course with a module and an item the student can open").toBeTruthy();
}

const FULL = process.env.RESPONSIVE_FULL === "1";
const THEMES = FULL ? ["light", "dark"] : ["light"];

/** Phones and tablets (up to a 1024px iPad held sideways) are touched; the larger sizes use a mouse. */
const isTouch = (size) => size.w <= 1024;

/** Signs in as the screen's account (if any) and opens the screen in the given theme. */
async function open(page, screen, theme) {
    await setTheme(page, theme);

    if (screen.as === "systemAdmin") {
        await signInAsAdmin(page);
    } else if (screen.as) {
        const { email, password } = accounts[screen.as];
        await submitLoginForm(page, email, password);
        await expect(page).not.toHaveURL(/\/login$/);
    }

    await page.goto(typeof screen.path === "function" ? await screen.path(page) : screen.path);
    // A screen without an address of its own is reached from the page at `path`.
    await screen.then?.(page);
    // Otherwise a 404 or a wrong page passes the scoped checks with nothing to check.
    await expect(page.locator(screen.scope ?? "body").first()).toBeVisible();
}

/**
 * Clicks a control until its dialog is open. A click that lands before the page's script has attached does
 * nothing (a slow machine, such as a CI runner, hits this), so it is repeated until it takes.
 */
async function openDialog(trigger, dialog) {
    await expect(async () => {
        if (!(await dialog.isVisible())) {
            await trigger.click({ timeout: 2_000 });
        }

        await expect(dialog).toBeVisible({ timeout: 2_000 });
    }).toPass({ timeout: 20_000 });
}

/** The offerings page's dialogs: the add button's, then the first row's own (through its ⋯ sheet on a phone). */
function offeringDialogs() {
    const entry = (id) => via(id, (page) => rowEntry(page, page.locator("[data-row]").first(), "body", `[data-dialog-open="${id}"]`));

    return [via("offering-add", (page) => page.locator('[data-dialog-open="offering-add"]')), entry("offering-edit"), entry("offering-instructor"), entry("offering-confirm")];
}

/** Dialogs opened from a list row's own entries (through its ⋯ sheet on a phone, else the entry itself or `toggle`'s dropdown). */
function rowDialogs(ids, rowSelector = "[data-row]", toggle = "body") {
    return ids.map((id) => via(id, (page) => rowEntry(page, page.locator(rowSelector).first(), toggle, `[data-dialog-open="${id}"]`)));
}

/** The people page's dialogs: Invite, then the first row's, from its Actions dropdown (md and up) or its ⋯ sheet. */
function peopleDialogs() {
    return [
        via("person-invite", (page) => page.locator('[data-dialog-open="person-invite"]')),
        ...rowDialogs(["person-edit", "person-roles", "person-confirm"], "[data-row]", "[data-person-menu] summary"),
        // Not the signed-in administrator's own row (nobody views as themselves).
        ...rowDialogs(["person-view-as"], "[data-row]:has([data-view-as])", "[data-person-menu] summary"),
    ];
}

/** The structure page's dialogs: the first college's own, then (after opening its first department) the department's. */
function structureDialogs() {
    const inCollege = (page) => page.locator("[data-view^=college-]:visible");
    const inDepartment = (page) => page.locator("[data-view^=department-]:visible");
    const toDepartment = async (page) => {
        // Already inside a department (an earlier dialog's page is reused): nothing to do.
        if ((await inDepartment(page).count()) === 0) {
            await inCollege(page).locator("[data-select^=department-]").first().click();
        }
    };

    return [
        via("structure-college-new", (page) => page.locator('[data-dialog-open="structure-college-new"]')),
        via("structure-college", (page) => inCollege(page).locator('[data-dialog-open="structure-college"]')),
        via("structure-dean", (page) => inCollege(page).locator('[data-dialog-open="structure-dean"]')),
        via("structure-sub", (page) => inCollege(page).locator('[data-dialog-open="structure-sub"]').first()),
        via("structure-confirm", (page) => inCollege(page).locator('[data-dialog-open="structure-confirm"]').first()),
        via("structure-chair", (page) => inDepartment(page).locator('[data-dialog-open="structure-chair"]'), toDepartment),
        via("structure-program", (page) => inDepartment(page).locator('[data-dialog-open="structure-program"]').first(), toDepartment),
    ];
}

/**
 * A `dialogs` entry opened through the page's own controls: `before(page)` sets the page up (opens a side panel,
 * say), then `opener(page)` gives the control (a locator, or a promise of one) that opens the dialog.
 */
function via(id, opener, before, ready) {
    return {
        id,
        open: async (page) => {
            await before?.(page);
            const control = await opener(page);
            await expect(control, `#${id}'s opener`).toBeVisible();
            // Centred, so a pinned bar (the editor's footer, a sticky toolbar) cannot be over it.
            // (Not a control in a pinned bar's panel, which rides on the bar.)
            await control.evaluate((el) => el.closest("[data-action-bar]") || el.scrollIntoView({ block: "center", behavior: "instant" }));
            const dialog = page.locator(`#${id}`);
            // The control's own click handler, not a tap at its spot: in a 375px-tall window held sideways the pinned
            // header and bars cover most of the page, and Playwright's own scroll puts the control under one of them.
            // (The page's touch check already holds every control to 44px.)
            await expect(async () => {
                if (!(await dialog.isVisible())) {
                    await control.dispatchEvent("click");
                }
                await expect(dialog).toBeVisible({ timeout: 2_000 });
            }).toPass({ timeout: 20_000 });
            // A dialog that loads its data (`ready` waits for it): checked loaded, not half way.
            await ready?.(dialog);
        },
    };
}

/**
 * The dialog has loaded: its primary button is enabled (it is disabled while the data loads) and done fading in (its
 * opacity transition), so axe sees its real colours.
 */
async function primaryReady(dialog, selector) {
    const button = dialog.locator(selector);
    await expect(button).toBeEnabled();
    await expect.poll(() => button.evaluate((el) => globalThis.getComputedStyle(el).opacity)).toBe("1");
}

/**
 * The open dialog's panel lies inside the window, and so does its last button (the primary action, last by the
 * dialog contract): a long body scrolls inside the panel while the action stays on screen.
 */
async function expectDialogFits(dialog) {
    const { panel, last, w, h } = await dialogPanel(dialog).evaluate((el) => {
        const buttons = [...el.querySelectorAll("button")].filter((b) => b.checkVisibility({ visibilityProperty: true }));

        return {
            panel: el.getBoundingClientRect().toJSON(),
            last: buttons.at(-1) ? { ...buttons.at(-1).getBoundingClientRect().toJSON(), text: buttons.at(-1).innerText.trim() } : null,
            w: globalThis.document.documentElement.clientWidth,
            h: globalThis.innerHeight,
        };
    });
    const inside = (r) => r.left >= -1 && r.top >= -1 && r.right <= w + 1 && r.bottom <= h + 1;
    const where = (r) => `${Math.round(r.left)},${Math.round(r.top)} to ${Math.round(r.right)},${Math.round(r.bottom)} in ${w}×${h}`;

    expect(inside(panel), `the panel at ${where(panel)}`).toBe(true);
    if (last) {
        expect(inside(last), `the last button "${last.text}" at ${where(last)}`).toBe(true);
    }
}

/**
 * A row's menu entry: through the row's ⋯ bottom sheet where it shows (below md, shared/row-actions.js moves the
 * entries in), else from its dropdown (`toggle`).
 */
async function rowEntry(page, row, toggle, entry) {
    const phone = row.locator("[data-row-actions-open]").first();
    if (await phone.isVisible()) {
        const sheet = page.locator(`#${await phone.getAttribute("aria-controls")}`);
        await openDialog(phone, sheet);

        return sheet.locator(entry).first();
    }

    // A floating menu (modules/action-menus.js) is moved onto the page while it is open.
    const item = row.locator(entry).or(page.locator(`body > [data-action-menu-panel] ${entry}`)).filter({ visible: true }).first();
    await expect(async () => {
        if (!(await item.isVisible())) {
            await row.locator(toggle).first().click({ timeout: 2_000 });
        }
        await expect(item).toBeVisible({ timeout: 2_000 });
    }).toPass({ timeout: 20_000 });

    return item;
}

/** The assessment editor's settings: a side panel below lg (opened from the top bar), the pane beside it from lg. */
async function openSettings(page) {
    const panel = page.locator("#editor-inspector-panel");
    await expect(async () => {
        if (!(await panel.isVisible())) {
            await page.locator('[data-side-panel-toggle][aria-controls="editor-inspector-panel"]').click({ timeout: 2_000 });
        }
        await expect(panel).toBeVisible({ timeout: 2_000 });
    }).toPass({ timeout: 20_000 });
}

/** Switches a settings toggle on in the page only (nothing is saved until Save), so what it reveals shows. */
async function switchOn(page, field) {
    const input = page.locator(`#editor-inspector-panel ${field}`);
    if (!(await input.isChecked())) {
        await page.locator(`#editor-inspector-panel label:has(${field})`).click();
    }
}

/** The quiz builder's question types and Build Faster tools: the pane from lg, a side panel at md–lg, the bar's panel below md. */
async function openPalette(page) {
    const bar = page.locator("[data-quiz-builder-bar] [data-action-bar-toggle]");
    // The bar's closed panel is clipped to no height, which Playwright still counts as visible: its toggle says.
    if (await bar.isVisible()) {
        await expect(async () => {
            if ((await bar.getAttribute("aria-expanded")) !== "true") {
                await bar.click({ timeout: 2_000 });
            }
            await expect(bar).toHaveAttribute("aria-expanded", "true", { timeout: 2_000 });
        }).toPass({ timeout: 20_000 });

        return;
    }

    const ai = page.locator("#quiz-builder-palette [data-open-ai-generate]");
    await expect(async () => {
        if (!(await ai.isVisible())) {
            await page.locator('[data-side-panel-toggle][aria-controls="quiz-builder-palette"]').click({ timeout: 2_000 });
        }
        await expect(ai).toBeVisible({ timeout: 2_000 });
    }).toPass({ timeout: 20_000 });
}

/** The rubric choices (Use Existing, Create New), which the page hides while the assignment has a rubric: shown without removing it. */
async function showRubricOptions(page) {
    await page.locator("[data-rubric-options]").evaluate((el) => el.classList.replace("hidden", "flex"));
}

// The copy page's offering list (the sweep row above covers axe and touch targets; this adds what a click does).
for (const size of sizes()) {
    test.describe(`copy list @${size.name}`, () => {
        test.use({ viewport: { width: size.w, height: size.h }, ...(isTouch(size) ? { hasTouch: true, isMobile: true } : {}) });
        for (const theme of THEMES) {
            test(`institution-terms-copy-plan list @${size.name} ${theme}`, async ({ page }, testInfo) => {
                test.skip(testInfo.project.name === "webkit" || size.w === 768, "chromium only, every size but 768");
                await open(page, { path: copyPlanPath, as: "institutionAdmin", scope: "main" }, theme);

                const grid = page.locator("[data-copy-form] [data-scroll-x]");
                const all = grid.locator("[data-check-all]");
                const boxes = grid.locator('tbody input[name="offerings[]"]');
                await expect(boxes.first()).toBeVisible();
                await grid.evaluate((el) => el.scrollTo({ left: el.scrollWidth }));
                await all.scrollIntoViewIfNeeded();
                // Pinned: scrolled all the way along, the header tick is still at the grid's left edge.
                const gridBox = await grid.boundingBox();
                const allBox = await all.boundingBox();
                expect(allBox.x - gridBox.x, "select-all stays at the grid's left edge").toBeLessThan(40);
                // Every box starts ticked, so the header is ticked: one click clears, the next ticks them all again.
                await all.click();
                await expect(grid.locator('tbody input[name="offerings[]"]:checked')).toHaveCount(0);
                await all.click();
                await expect(grid.locator('tbody input[name="offerings[]"]:enabled:not(:checked)')).toHaveCount(0);
                if (!isTouch(size)) {
                    // A hovered row's pinned tick cell takes the row's own hover colour (in dark mode too).
                    const row = grid.locator("tbody tr.group").first();
                    await row.locator("td").nth(1).hover();
                    const same = (tr) => {
                        const own = globalThis.getComputedStyle(tr).backgroundColor;
                        return own !== "rgba(0, 0, 0, 0)" && globalThis.getComputedStyle(tr.querySelector("td.sticky")).backgroundColor === own;
                    };
                    await expect.poll(() => row.evaluate(same), { message: "the pinned cell matches its hovered row" }).toBe(true);
                }
                if (size.w < 640) {
                    await expectInViewport(page, "[data-copy-bar] button[type=submit]");
                    expect(await page.locator("[data-copy-bar]").evaluate((el) => globalThis.getComputedStyle(el).position)).toBe("sticky");
                    expect(await page.locator("[data-copy-bar]").evaluate((el) => globalThis.getComputedStyle(el).backgroundColor)).not.toBe("rgba(0, 0, 0, 0)");
                }
                await expectNoPageOverflow(page);
                if (isTouch(size)) await expectTouchTargets(page, "main");
                await expectAccessible(page, "main");
            });
        }
    });
}

const moduleRow = (page) => page.locator("[data-module-wrapper]").filter({ hasText: MODULE_TITLE }).first();

for (const size of sizes()) {
    test.describe(`@${size.name}`, () => {
        test.use({
            viewport: { width: size.w, height: size.h },
            ...(isTouch(size) ? { hasTouch: true, isMobile: true } : {}),
        });

        for (const screen of SCREENS) {
            for (const theme of THEMES) {
                test(`${screen.name} @${size.name} ${theme}`, async ({ page }, testInfo) => {
                    test.skip(testInfo.project.name === "webkit" && size.w !== 375, "WebKit runs the 375px phone only");
                    // Each dialog is opened, checked (axe too) and closed in turn.
                    if (isTouch(size)) {
                        test.setTimeout(testInfo.timeout + 15_000 * (screen.dialogs?.length ?? 0));
                    }

                    screen.seed?.();
                    await open(page, screen, theme);

                    await expectNoPageOverflow(page, screen.overflowScope);
                    if (isTouch(size)) {
                        await expectTouchTargets(page, screen.scope);
                    }
                    await expectAccessible(page, screen.scope);
                    await snap(page, `${screen.name}-${size.name}-${theme}`);

                    if (!isTouch(size)) {
                        return;
                    }

                    for (const entry of screen.dialogs ?? []) {
                        const { id, open: openVia } = typeof entry === "string" ? { id: entry } : entry;
                        const dialog = page.locator(`#${id}`);
                        if (openVia) {
                            await openVia(page);
                        } else {
                            const trigger = page.locator(`:is(${screen.scope ?? "body"}) [data-dialog-open="${id}"]`);
                            // The ☰ menu's opener is lg:hidden; below lg every opener must show.
                            if (size.w >= 1024 && !(await trigger.isVisible())) {
                                continue;
                            }
                            await expect(trigger, `#${id}'s opener`).toBeVisible();
                            await openDialog(trigger, dialog);
                        }

                        await expectTouchTargets(page, `#${id}`);
                        await expectNoPageOverflow(page, screen.overflowScope);
                        await expectNoPanelOverflow(dialog);
                        if (openVia) {
                            await expectDialogFits(dialog);
                            await expectAccessible(page, `#${id}`);
                        }

                        await page.keyboard.press("Escape");
                        await expect(dialog).toBeHidden();
                    }
                });
            }
        }
    });
}

// The animated close, which the sweep (reduced motion) never plays: every way out ends hidden, with focus
// back on the opener and the page scrollable again, including a fast double click on ×.
test.describe("dialog close with motion", () => {
    test.use({ reducedMotion: "no-preference", viewport: { width: 1366, height: 768 } });

    test("ui-kit dialog closes by ×, Escape, backdrop and a double ×", async ({ page }, testInfo) => {
        test.skip(testInfo.project.name === "webkit", "WebKit runs the 375px phone only");

        await open(page, SCREENS[1], "light");
        const opener = page.locator('[data-responsive-kit] [data-dialog-open="kit-fullscreen"]');
        const dialog = page.locator("#kit-fullscreen");
        const close = dialog.locator("[data-dialog-close]").first();

        const closes = {
            "×": () => close.click(),
            Escape: () => page.keyboard.press("Escape"),
            backdrop: () => dialog.click({ position: { x: 5, y: 5 } }),
            "double ×": () => close.dblclick(),
        };

        for (const [how, act] of Object.entries(closes)) {
            await openDialog(opener, dialog);
            await act();

            await expect(dialog, how).toBeHidden();
            await expect(opener, how).toBeFocused();
            await expect(page.locator("body"), how).not.toHaveClass(/\boverflow-hidden\b/);
        }
    });
});

// The shell's own checks (header, side menus, lock), each at the one size it is about.
test.describe("shell", () => {
    test.skip(({ browserName }) => browserName === "webkit", "WebKit runs the 375px phone sweep only");

    const signIn = async (page, as, path) => open(page, { path, as, scope: "[data-app-header]" }, "light");
    const phone = (width, height) => ({ viewport: { width, height }, hasTouch: true, isMobile: true });

    test.describe("320 phone", () => {
        test.use(phone(320, 640));

        test("phone header fits at 320", async ({ page }) => {
            // A page with no <h1> of its own, so the header shows its title (layouts/app.php).
            await signIn(page, "student", "/student/schedule");
            const title = page.locator("[data-header-title]");
            await expect(title).toBeVisible();
            await title.evaluate((el) => {
                el.textContent = "A very long page title ".repeat(6).slice(0, 120);
            });

            await expectInViewport(page, '[data-profile-menu-trigger][aria-label^="More"]');
            await expectInViewport(page, "#notifications-button");
            await expectInViewport(page, "[data-brand-logo] img >> visible=true");
            const { height } = await page.locator("[data-app-header]").boundingBox();
            // One line is 69px; a header that wraps to a second line is over 100px.
            expect(height, "the phone header is one line").toBeLessThanOrEqual(80);
        });
    });

    for (const [w, h] of [[375, 812], [700, 900]]) {
        test.describe(`${w} panels`, () => {
            // Read the panel's rect after it has settled, not mid-animation.
            test.use({ ...phone(w, h), reducedMotion: "reduce" });

            test(`⋯ and bell panels stay on screen at ${w}`, async ({ page }) => {
                await signIn(page, "student", "/student/dashboard");

                for (const [trigger, panel] of [
                    ["[data-profile-menu-trigger] >> visible=true", "#profile-menu-panel"],
                    ["#notifications-button", "#notifications-panel"],
                ]) {
                    await openDialog(page.locator(trigger), page.locator(panel));
                    await expectInViewport(page, panel);
                    await page.keyboard.press("Escape");
                    await expect(page.locator(panel), panel).toBeHidden();
                }
            });
        });
    }

    test.describe("1024 rail", () => {
        test.use({ viewport: { width: 1024, height: 768 } });

        test("rail overlay does not move the page at 1024", async ({ page }) => {
            await signIn(page, "institutionAdmin", "/institution/dashboard");
            const main = page.locator("main");
            const before = (await main.boundingBox()).x;

            await page.locator("[data-institution-menu]").hover();
            await expect.poll(async () => (await page.locator("[data-institution-menu] [data-side-menu-panel]").boundingBox()).width).toBeGreaterThanOrEqual(240);

            expect((await main.boundingBox()).x, "main's left edge").toBe(before);
        });
    });

    // The system administrator signs in with an emailed code, which the SCREENS `as` sign-in cannot do.
    test.describe("1024 admin rail on touch", () => {
        test.use({ viewport: { width: 1024, height: 768 }, hasTouch: true, isMobile: true });

        test("admin-shell: the peek button opens the rail and a group link is reachable", async ({ page }) => {
            await signInAsAdmin(page);
            const menu = page.locator("[data-admin-menu]");
            const toggle = menu.locator("[data-menu-collapse]");
            const link = menu.locator('a[href$="/admin/flags"]');

            await expect(link).toBeHidden();
            await expect(toggle).toHaveAttribute("aria-label", "Expand the menu");
            await expectTouchTargets(page, "[data-admin-menu]");

            await expect(async () => {
                await toggle.tap({ timeout: 2_000 });
                await expect(menu).toHaveAttribute("data-peek", "true", { timeout: 2_000 });
            }).toPass({ timeout: 20_000 });
            await expect(toggle).toHaveAttribute("aria-label", "Collapse the menu");
            await expect.poll(async () => (await menu.locator("[data-side-menu-panel]").boundingBox()).width).toBeGreaterThanOrEqual(240);

            await menu.locator('[data-menu-group="Platform"] summary').tap();
            await expect(link).toBeVisible();
            await expectTouchTargets(page, "[data-admin-menu]");
            await expectInViewport(page, '[data-admin-menu] a[href$="/admin/flags"]');
            await expectNoPageOverflow(page);

            await link.tap();
            await expect(page).toHaveURL(/\/admin\/flags$/);
        });
    });

    test("admin-monitoring: a live refresh at 375 keeps the viewport, the height and the focus", async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await signInAsAdmin(page);
        await page.goto("/admin/monitoring");
        const box = page.locator("[data-auto-refresh]");
        await box.focus();
        await page.mouse.wheel(0, 150);
        const state = () => page.evaluate(() => {
            const { document: doc, scrollY } = globalThis;

            return { y: Math.round(scrollY + (doc.querySelector("[data-page-panel]")?.scrollTop ?? 0)), h: doc.documentElement.scrollHeight, focus: doc.activeElement?.hasAttribute("data-auto-refresh") };
        });
        await page.waitForResponse((r) => r.url().includes("monitoring/live"));
        const before = await state();
        const points = () => page.locator('[data-spark="sessions"]').getAttribute("points");
        const drawn = await points();
        expect(before.y, "the page was scrolled before the refresh").toBeGreaterThan(0);

        await page.waitForResponse((r) => r.url().includes("monitoring/live"), { timeout: 15_000 });
        await expect.poll(points, "the refresh repainted the graph").not.toBe(drawn);
        expect(await state()).toEqual(before);
        expect(before.focus, "focus stays on the live-updates box").toBe(true);
    });

    test("admin-audit-log: at 768 every column that is shown fits its table", async ({ page }) => {
        await page.setViewportSize({ width: 768, height: 1024 });
        await signInAsAdmin(page);
        await page.goto("/admin/audit-log");
        const table = page.locator("[data-table]");

        for (const name of ["Target", "Institution"]) {
            await expect(table.locator("thead th", { hasText: name })).toBeVisible();
        }

        const { scroll, client } = await table.evaluate((el) => ({ scroll: el.scrollWidth, client: el.clientWidth }));
        expect(scroll, "no column is clipped").toBeLessThanOrEqual(client);
        await expectNoPageOverflow(page);
    });

    // "Show the source IP" switched on (the settings row the Display settings dialog saves), removed again in finally.
    for (const width of [768, 1024]) {
        test(`admin-audit-log: with the source IP shown, at ${width} the shown columns fit and Source IP is there from lg`, async ({ page }) => {
            const clean = () => runSql("DELETE FROM settings WHERE `key` = 'auditShowSourceIp'");
            clean();

            try {
                runSql("INSERT INTO settings (class, `key`, value, type, context, created_at, updated_at) VALUES ('Config\\\\Platform', 'auditShowSourceIp', '1', 'boolean', NULL, NOW(), NOW())");
                await page.setViewportSize({ width, height: 1024 });
                await signInAsAdmin(page);
                await page.goto("/admin/audit-log");
                const table = page.locator("[data-table]");
                const ip = table.locator("thead th", { hasText: "Source IP" });

                await expect(ip).toHaveCount(1);
                if (width >= 1024) {
                    await expect(ip).toBeVisible();
                } else {
                    await expect(ip).toBeHidden();
                }

                const { scroll, client } = await table.evaluate((el) => ({ scroll: el.scrollWidth, client: el.clientWidth }));
                expect(scroll, "no column is clipped").toBeLessThanOrEqual(client);
                await expectNoPageOverflow(page);
            } finally {
                clean();
            }
        });
    }

    // RF7: a deleted and a past institution seeded by the marker (runSql returns no ids), removed before and in finally.
    test.describe("admin-archive rows with data", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("a deleted institution's restore and purge sit in the row sheet at 375", async ({ page }) => {
            const clean = () => runSql("DELETE FROM institutions WHERE code LIKE 'RF7%'");
            clean();

            try {
                runSql("INSERT INTO institutions (name, code, status, created_at, updated_at, deleted_at) VALUES ('RF7 Deleted University', 'RF7DEL', 'active', NOW(), NOW(), NOW() - INTERVAL 1 DAY), ('RF7 Past University', 'RF7PST', 'past', NOW(), NOW(), NULL)");
                await signInAsAdmin(page);
                await page.goto("/admin/archive");
                const row = page.locator('[data-section="deleted"] [data-row]').filter({ hasText: "RF7 Deleted University" });
                await expect(row).toBeVisible();
                await expectNoPageOverflow(page);
                await expectTouchTargets(page, "main");
                await expectAccessible(page, "main");

                const phone = row.locator("[data-row-actions-open]");
                const sheet = page.locator(`#${await phone.getAttribute("aria-controls")}`);
                await openDialog(phone, sheet);
                await expect(sheet.locator('form[action$="/restore"] button[type=submit]')).toBeVisible();
                await sheet.locator("summary", { hasText: "Purge now" }).click();
                await expect(sheet.locator('form[action$="/purge"] input[name=confirm_name]')).toBeVisible();
                await expect(sheet.locator('form[action$="/purge"] button[type=submit]')).toBeVisible();
                await expectTouchTargets(page, `#${await sheet.getAttribute("id")}`);
            } finally {
                clean();
            }
        });
    });

    test("lock applies only when wide and tall", async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 768 });
        await signIn(page, "student", "/student/dashboard");
        const overflowY = () => page.locator("body").evaluate((el) => globalThis.getComputedStyle(el).overflowY);

        expect(await overflowY(), "1366×768").toBe("hidden");
        for (const [width, height] of [[1366, 700], [1024, 900]]) {
            await page.setViewportSize({ width, height });
            expect(await overflowY(), `${width}×${height}`).not.toBe("hidden");
        }
    });

    test("a locked page fits main and scrolls inside its cards", async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 768 });
        await signIn(page, "student", "/student/dashboard");

        const m = await page.locator("main").evaluate((main) => {
            const root = [...main.firstElementChild.children].find((el) => el.tagName === "DIV");
            const scrollers = [...root.querySelectorAll("*")].filter((el) => /auto|scroll/.test(globalThis.getComputedStyle(el).overflowY) && el.scrollHeight > el.clientHeight);

            return { root: root.getBoundingClientRect().bottom, main: main.getBoundingClientRect().bottom, scrollers: scrollers.length };
        });

        expect(m.root, "the page root's bottom against main's").toBeLessThanOrEqual(m.main + 1);
        expect(m.scrollers, "cards that scroll their own overflow").toBeGreaterThan(0);
    });

    test.describe("375 phone menu", () => {
        test.use(phone(375, 812));

        test("phone menu opens and closes", async ({ page }) => {
            await signIn(page, "institutionAdmin", "/institution/dashboard");
            const opener = page.locator('[data-dialog-open="nav-menu"]');
            const menu = page.locator("#nav-menu");

            await openDialog(opener, menu);
            await expectTouchTargets(page, "#nav-menu");
            await expect(menu.locator("[data-menu-tile]").first()).toBeVisible();

            await page.keyboard.press("Escape");
            await expect(menu).toBeHidden();
            await expect(opener).toBeFocused();
        });
    });
});

// The shared components, on /ui-kit's Components section, each checked at the size it is about. The sweep
// above already runs touch targets, axe, overflow and snapshots over the section at every size.
test.describe("components", () => {
    const kit = SCREENS.find((s) => s.name === "ui-kit");
    const people = '[data-kit="people"] [data-table]';
    const grid = '[data-kit="grid"] [data-scroll-x][data-sticky-first]';
    const panelBox = (dialog) => dialog.locator("[data-dialog-panel]").boundingBox();

    test.describe("1366 laptop", () => {
        test.use({ viewport: { width: 1366, height: 768 } });

        test("ui-kit split divider drags and resets on double click", async ({ page }) => {
            await open(page, kit, "light");
            const split = page.locator('[data-split][data-split-key="ui-kit-demo"]');
            const handle = split.locator("[data-split-handle]");
            const start = split.locator("[data-split-start]");
            await handle.scrollIntoViewIfNeeded();
            const reset = (await start.boundingBox()).width;
            const box = await handle.boundingBox();
            const y = box.y + box.height / 2;
            const x = box.x + box.width / 2;

            await page.mouse.move(x, y);
            await page.mouse.down();
            await page.mouse.move(x - 40, y, { steps: 4 });
            await page.mouse.up();
            await expect.poll(async () => (await start.boundingBox()).width).toBeCloseTo(reset - 40, -1);
            await expect(handle).toHaveAttribute("aria-valuenow", String(Math.round(reset - 40)));

            await handle.dblclick();
            await expect.poll(async () => (await start.boundingBox()).width).toBeCloseTo(reset, -1);
        });
    });

    // WebKit runs these too: they are the 375px phone, where Safari's own table and sticky handling matter.
    test.describe("375 phone", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("list table becomes list rows on a phone", async ({ page }) => {
            await open(page, kit, "light");
            const table = page.locator(people);
            const sheet = page.locator("#kit-people-actions");

            // The heading row is still there for a screen reader, but takes no room.
            await expect(table.locator("thead")).toHaveCSS("position", "absolute");
            expect((await table.locator("thead").boundingBox()).height).toBeLessThanOrEqual(1);

            const panels = table.locator("tbody[data-panel]");
            await expect(panels).toHaveCount(2);
            await expect(panels.first()).toHaveCSS("display", "block");
            await expect(panels.locator("[data-group-name]")).toHaveText(["Section A", "Section B"]);
            await expect(panels.first().locator("[data-group-heading]")).toBeVisible();
            await expect(table.locator('[data-row] > [data-role="primary"]').first()).toHaveText("Ann Reyes");
            await expect(table.locator('[data-row] > [data-label="Email"]').first()).toBeHidden();
            await expect(table.locator('[data-row] > [data-role="actions"]').first()).toBeHidden();

            await openDialog(table.locator("[data-row-actions-open]").first(), sheet);
            await expect(sheet.locator("[data-dialog-title]")).toHaveText("Ann Reyes");
            await expect(sheet.locator("[data-row-actions-fields]")).toContainText("Email");
            await expect(sheet.locator("[data-row-actions-fields]")).toContainText("ann.reyes@example.edu");
            await expect(sheet.locator("[data-row-actions-fields]")).toContainText("Last active");
            await expect(sheet.locator("[data-row-actions-slot] button")).toHaveText(["Send a message", "Remove from section"]);
            await expectTouchTargets(page, "#kit-people-actions");
            await expectNoPanelOverflow(sheet);
        });

        test("row action returns its nodes on close", async ({ page }) => {
            await open(page, kit, "light");
            const row = page.locator(`${people} [data-row]`).first();
            const more = row.locator("[data-row-actions-open]");
            const sheet = page.locator("#kit-people-actions");

            await openDialog(more, sheet);
            await expect(row.locator('[data-role="actions"] button')).toHaveCount(0);

            await page.keyboard.press("Escape");
            await expect(sheet).toBeHidden();
            await expect(row.locator('[data-role="actions"] button')).toHaveText(["Send a message", "Remove from section"]);
            await expect(sheet.locator("[data-row-actions-slot] > *")).toHaveCount(0);
            await expect(more).toBeFocused();
        });

        test("grid scroll pins the first column", async ({ page }) => {
            await open(page, kit, "light");
            const scroller = page.locator(grid);
            await scroller.scrollIntoViewIfNeeded();

            const room = await scroller.evaluate((el) => el.scrollWidth - el.clientWidth);
            expect(room, "the grid is wider than a phone").toBeGreaterThanOrEqual(200);
            await expect(scroller).not.toHaveAttribute("data-fade-start");
            const nextLeft = () => scroller.evaluate((el) => el.querySelector("tr[data-roles-row] > :nth-child(2)").getBoundingClientRect().left);
            const before = await nextLeft();

            await scroller.evaluate((el) => (el.scrollLeft = 200));
            await expect(scroller).toHaveAttribute("data-fade-start");

            const edges = await scroller.evaluate((el) => ({
                box: el.getBoundingClientRect().left + el.clientLeft,
                head: el.querySelector("thead tr > :first-child").getBoundingClientRect().left,
                cell: el.querySelector("tr[data-roles-row] > :first-child").getBoundingClientRect().left,
            }));
            const after = await nextLeft();
            expect(Math.abs(edges.cell - edges.box), "the first column's cell against the grid's left edge").toBeLessThanOrEqual(1);
            expect(Math.abs(edges.head - edges.box), "the first column's heading against the grid's left edge").toBeLessThanOrEqual(1);
            expect(Math.abs(before - after - 200), `the next column moved left by the scroll (${Math.round(before)} to ${Math.round(after)})`).toBeLessThanOrEqual(1);

            // At the far end: the pinned column keeps its shadow; the section heading, which spans the whole row,
            // has none (it would show as a stray shadow at the table's right edge).
            await scroller.evaluate((el) => (el.scrollLeft = el.scrollWidth));
            await expect(scroller).not.toHaveAttribute("data-fade-end");
            const shadows = await scroller.evaluate((el) => ({
                cell: globalThis.getComputedStyle(el.querySelector("tr[data-roles-row] > :first-child")).boxShadow,
                section: globalThis.getComputedStyle(el.querySelector("tr[data-roles-section-head] > :first-child")).boxShadow,
            }));
            expect(shadows.cell, "the pinned column's shadow").not.toBe("none");
            expect(shadows.section, "the section heading's shadow").toBe("none");
        });

        test("the active tab's underline overlaps the strip's border", async ({ page }) => {
            await open(page, kit, "light");
            const edges = await page.locator('[data-kit="tabs"] nav[data-tabs]').evaluate((nav) => ({
                nav: nav.getBoundingClientRect().bottom,
                tab: nav.querySelector('[aria-current="page"]').getBoundingClientRect().bottom,
            }));
            expect(Math.abs(edges.tab - edges.nav), `the underline ends at ${edges.tab}, the border at ${edges.nav}`).toBeLessThanOrEqual(0.5);
        });

        test("a metric card keeps its icon at 250px wide", async ({ page }) => {
            await open(page, kit, "light");
            const shown = await page.locator('[data-kit="cards"]').evaluate((cards) => {
                const slot = globalThis.document.createElement("div");
                slot.style.width = "250px";
                cards.append(slot);

                return [...cards.querySelectorAll(".\\@container")].slice(0, 2).map((card) => {
                    slot.append(card);

                    return card.querySelector("svg").getBoundingClientRect().width > 0;
                });
            });
            expect(shown, "both cards' icons show (default and prominent)").toEqual([true, true]);
        });

        test("stepper shows the summary on a phone", async ({ page }) => {
            await open(page, kit, "light");
            const stepper = page.locator('[data-kit="stepper"]');
            const summary = stepper.locator("[data-stepper-summary]");

            await expect(summary).toBeVisible();
            await expect(summary).toContainText("Step 2 of 3");
            await expect(summary).toContainText("Check the rows");
            await expect(summary.getByRole("progressbar")).toHaveAttribute("aria-valuenow", "2");
            // The full list stays for a screen reader, with its hooks.
            await expect(stepper.locator("ol[data-stepper]")).toHaveCSS("position", "absolute");
            await expect(stepper.locator('[data-step="2"]')).toHaveAttribute("data-state", "current");
        });
    });

    test.describe("laptop and tablet", () => {
        test.skip(({ browserName }) => browserName === "webkit", "WebKit runs the 375px phone only");

        test("priority columns hide at md and show again at lg", async ({ page }) => {
            await page.setViewportSize({ width: 800, height: 900 });
            await open(page, kit, "light");
            const table = page.locator(people);
            const heading = (label) => table.locator("thead th", { hasText: label });
            const cell = (label) => table.locator(`[data-row] > [data-label="${label}"]`).first();

            await expect(heading("Program")).toBeVisible();
            await expect(heading("Email")).toBeHidden();
            await expect(cell("Email")).toBeHidden();
            await expect(heading("Last active")).toBeHidden();

            await page.setViewportSize({ width: 1100, height: 900 });
            await expect(heading("Email")).toBeVisible();
            await expect(cell("Email")).toBeVisible();
            await expect(heading("Last active"), "priority 3 waits for xl").toBeHidden();
        });

        test("form dialog is full screen on a phone and centered on a laptop", async ({ page }) => {
            await page.setViewportSize({ width: 375, height: 812 });
            await open(page, kit, "light");
            const form = page.locator("#kit-comp-form");
            const confirm = page.locator("#kit-resp-confirm");

            for (const [w, h] of [[375, 812], [1366, 768]]) {
                await page.setViewportSize({ width: w, height: h });

                await openDialog(page.locator('[data-kit="form-dialog"] [data-dialog-open="kit-comp-form"]'), form);
                const r = await panelBox(form);
                if (w === 375) {
                    expect(r.x <= 1 && r.y <= 1 && r.width >= w - 1 && r.height >= h - 1, `the form's panel at ${JSON.stringify(r)} fills ${w}×${h}`).toBe(true);
                    // The form's own submit row is pinned to the bottom, and its Cancel gives way to the top bar's.
                    const submit = await form.getByRole("button", { name: "Create course" }).boundingBox();
                    expect(submit.y + submit.height, "the pinned submit button's bottom").toBeGreaterThan(h - 100);
                    await expect(form.locator("[data-dialog-body] [data-dialog-close]")).toBeHidden();
                } else {
                    expect(r.width, "the form's panel width").toBeLessThan(w * 0.6);
                    expect(Math.abs(r.x + r.width / 2 - w / 2), "the form's panel is centered").toBeLessThanOrEqual(2);
                }
                await page.keyboard.press("Escape");
                await expect(form).toBeHidden();

                await openDialog(page.locator('[data-responsive-kit] [data-dialog-open="kit-resp-confirm"]'), confirm);
                const c = await panelBox(confirm);
                expect(c.width * c.height, `the confirm (${Math.round(c.width)}×${Math.round(c.height)}) stays small at ${w}`).toBeLessThan(0.5 * w * h);
                await page.keyboard.press("Escape");
                await expect(confirm).toBeHidden();
            }
        });

        // A dialog body is a query container (form-grid); a dropdown in it must still show whole.
        test("a dropdown inside a form dialog is not clipped", async ({ page }) => {
            await page.setViewportSize({ width: 375, height: 812 });
            await open(page, kit, "light");
            const form = page.locator("#kit-comp-form");
            const dropdown = form.locator("[data-kit-dropdown]");

            for (const [w, h] of [[375, 812], [1366, 768]]) {
                await page.setViewportSize({ width: w, height: h });
                await openDialog(page.locator('[data-kit="form-dialog"] [data-dialog-open="kit-comp-form"]'), form);
                await dropdown.locator("summary").click();

                const last = dropdown.getByRole("button", { name: "Instructors only" });
                await expect(last).toBeVisible();
                const onTop = await last.evaluate((el) => {
                    const r = el.getBoundingClientRect();

                    return el.contains(globalThis.document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2));
                });
                expect(onTop, `the dropdown's last item shows at ${w}`).toBe(true);

                await page.keyboard.press("Escape");
                await page.keyboard.press("Escape");
                await expect(form).toBeHidden();
            }
        });

        test("a form in a narrow container stacks", async ({ page }) => {
            await page.setViewportSize({ width: 1366, height: 768 });
            await open(page, kit, "light");
            const columns = (which) => page.locator(`[data-kit-form="${which}"] .form-grid`).evaluate((el) => globalThis.getComputedStyle(el).gridTemplateColumns.split(" ").length);

            expect(await columns("narrow"), "columns in the narrow card").toBe(1);
            expect(await columns("wide"), "columns in the wide card").toBe(2);
        });
    });
});

// The instructor dashboard's phone tabs: To grade, Sections, Updates below lg; the whole grid from lg up.
test.describe("instructor", () => {
    const dashboard = SCREENS.find((s) => s.name === "instructor-dashboard");
    const card = (page, title) => page.locator("main span.text-xl").filter({ hasText: new RegExp(`^\\s*${title}\\s*$`) });

    test.describe("375 phone", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("only To grade shows, and Sections switches to the teaching widgets", async ({ page }) => {
            await open(page, dashboard, "light");

            const tabs = page.getByRole("tablist", { name: "Dashboard sections" });
            await expect(tabs.getByRole("tab")).toHaveText(["To grade", "Sections", "Updates"].map((t) => new RegExp(`^\\s*${t}`)));
            await expect(card(page, "Grading Queue")).toBeVisible();
            await expect(card(page, "Needs Your Attention")).toBeVisible();
            await expect(card(page, "Teaching Load")).toBeHidden();
            await expect(card(page, "Quick Access")).toBeHidden();

            // A tap that lands before the script has attached does nothing, so it is repeated until it takes.
            const sections = tabs.getByRole("tab", { name: /^Sections/ });
            await expect(async () => {
                await sections.tap({ timeout: 2_000 });
                await expect(sections).toHaveAttribute("aria-selected", "true", { timeout: 2_000 });
            }).toPass({ timeout: 20_000 });

            for (const title of ["Teaching Load", "Class Progress", "Unpublished", "Quick Access"]) {
                await expect(card(page, title), title).toBeVisible();
            }
            await expect(card(page, "Grading Queue")).toBeHidden();
            await expectNoPageOverflow(page);
            // The sweep only sees the first tab, so the switched-to panels are checked here.
            // (The tab itself is mid-press-scale just after the tap, so it is left out of the touch check.)
            await expectTouchTargets(page, "main [data-phone-panel]");
            await expectAccessible(page, "main");
        });

        test("the grading queue shows at most five rows", async ({ page }) => {
            await open(page, dashboard, "light");

            // The seed's instructor has a late submission waiting, so the queue is not empty.
            const rows = page.locator("[data-queue-row]");
            await expect(rows.first()).toBeVisible();
            expect(await rows.filter({ visible: true }).count()).toBeLessThanOrEqual(5);
        });
    });

    test.describe("1366 laptop", () => {
        test.use({ viewport: { width: 1366, height: 768 } });

        test("no tabs, every widget shows", async ({ page }) => {
            await open(page, dashboard, "light");

            await expect(page.locator("[data-phone-tabs]")).toBeHidden();
            for (const title of ["Grading Queue", "Needs Your Attention", "Teaching Load", "Class Progress", "Unpublished", "Quick Access"]) {
                await expect(card(page, title), title).toBeVisible();
            }
            for (const link of await page.locator("main [data-view-all]").all()) {
                await expect(link).toBeHidden();
            }
        });
    });

    // Grade Work: below md the grading panel is a sticky score bar whose controls open upward; below lg the
    // students list is a side panel; from lg up it is a pane the divider resizes.
    const gradeWork = SCREENS.find((s) => s.name === "instructor-grade-work");
    const bar = (page) => page.locator("[data-expandable-grade-work-actions-panel]");
    const box = (locator) => locator.evaluate((el) => el.getBoundingClientRect().toJSON());
    const overlaps = (a, b) => a.top < b.bottom && b.top < a.bottom && a.left < b.right && b.left < a.right;
    // The grading case grades Pedro Reyes's late resubmission (attempt 2): no other spec reads that attempt, while
    // grading.spec.js needs Maria Santos still "Submitted". A second run in the same server finds it graded and
    // reopens it through Edit, so the case never depends on the order the files run in.
    const pedro = '[data-student-name="Pedro Reyes"]';

    test.describe("375 grade work", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("the score bar sits at the bottom, opens the rubric upward, keeps the focused field clear and saves", async ({ page }) => {
            test.slow();
            await open(page, { ...gradeWork, path: (p) => gradeWorkPath(p, pedro) }, "light");

            // Open it for grading (or, when an earlier run graded it, reopen the grade): either way it reloads editable.
            const state = await bar(page).getAttribute("data-panel-state");
            if (state === "locked") {
                await page.locator("[data-regrade-open]").click();
                await page.locator("[data-confirm-regrade]").click();
            } else if (state !== "editing") {
                await page.locator("[data-expand-grade-work-actions]").click();
            }
            await expect(bar(page)).toHaveAttribute("data-panel-state", "editing");

            const save = bar(page).locator("[data-grade-work-submit-grade]");
            // "Save" only: it stays on this student (no next-student step yet).
            await expect(save).toHaveText(/^\s*Save\s*$/, { useInnerText: true });
            const closed = await box(bar(page));
            expect(Math.abs(closed.bottom - 812), "the bar rests on the bottom edge").toBeLessThanOrEqual(1);

            // "Rubric" opens the criteria upward inside the bar: the row stays on the bottom edge, the panel rises above it.
            const toggle = bar(page).locator("[data-action-bar-toggle]");
            await expect(toggle).toHaveText(/Rubric/);
            await expect(async () => {
                await toggle.tap({ timeout: 2_000 });
                await expect(toggle).toHaveAttribute("aria-expanded", "true", { timeout: 2_000 });
            }).toPass({ timeout: 20_000 });
            await bar(page).locator("[data-with-rubric-grade-work-toggle]").tap();
            const points = bar(page).locator("[data-grade-work-rubric-criteria-points]");
            await expect(points).toHaveCount(3);
            await expect(points.first()).toBeVisible();
            const panel = await box(bar(page).locator("[data-action-bar-panel]"));
            expect(panel.top).toBeLessThan(closed.top - 100);
            expect(Math.abs(panel.bottom - closed.top)).toBeLessThanOrEqual(1);
            expect(Math.abs((await box(bar(page))).bottom - 812)).toBeLessThanOrEqual(1);

            // Levels (the mode switch and each criterion's score) are at least 40px; every control is a 44px target.
            expect((await box(points.first())).height).toBeGreaterThanOrEqual(40);
            expect((await box(bar(page).locator("[data-with-rubric-grade-work-toggle]"))).height).toBeGreaterThanOrEqual(40);
            await expectTouchTargets(page, "[data-expandable-grade-work-actions-panel]");

            await points.nth(0).fill("10");
            await points.nth(1).fill("8");
            await points.nth(2).fill("6");
            await expect(bar(page).locator("[data-grade-work-score-preview]")).toHaveText("24/30");

            // Review Focus 2: a focused rubric score or the feedback box is never under the bar's Save row.
            for (const field of [points.nth(2), bar(page).locator("[data-grade-work-feedback]")]) {
                await field.tap();
                await expect(field).toBeFocused();
                const r = await box(field);
                const row = await box(save);
                expect(overlaps(r, row), "the focused field is clear of the Save row").toBe(false);
                expect(r.top).toBeGreaterThanOrEqual(0);
                expect(r.bottom).toBeLessThanOrEqual(812);
            }
            await bar(page).locator("[data-grade-work-feedback]").fill("Clear code; add tests.");
            await expectNoPageOverflow(page);

            const graded = page.waitForResponse((r) => /\/submissions\/\d+\/grade$/.test(r.url()) && r.request().method() === "PATCH");
            await save.tap();
            expect((await graded).ok()).toBe(true);
            await expect(bar(page)).toHaveAttribute("data-panel-state", "locked");

            // The students panel slides in from the Students toggle and shows the grade.
            const students = page.locator("#grade-work-students");
            await page.locator("[data-side-panel-toggle]").tap();
            await expect(students).toBeVisible();
            await expect(students.locator(`[data-grade-work-student]${pedro}`)).toContainText("Graded");
            await expectTouchTargets(page, "#grade-work-students");
            await page.keyboard.press("Escape");
            await expect(students).toBeHidden();
        });

        test("a student with no submission: the bar shows the empty state and Save is disabled, even with a long name at 320", async ({ page }) => {
            // Review Focus 4: a 40-character unbroken surname on the student shown (Angela Cruz, put back after).
            const long = "Cruzvillanuevadelarosasantiagomontemayor";
            runSql(`UPDATE user_profiles SET last_name = '${long}' WHERE first_name = 'Angela' AND last_name = 'Cruz'`);
            try {
                await open(page, { ...gradeWork, path: (p) => gradeWorkPath(p, '[data-student-name^="Angela"]') }, "light");
                await expect(page.locator("#student-work-container")).toContainText(long);

                await expect(bar(page)).toHaveAttribute("data-panel-state", "disabled");
                await expect(bar(page).locator("[data-grade-work-status-label]")).toHaveText(/No Submission/);
                await expect(bar(page).locator("[data-grade-work-submit-grade]")).toBeDisabled();
                await expect(bar(page).locator("[data-action-bar-toggle]")).toHaveCount(0);
                const r = await box(bar(page));
                expect(Math.abs(r.bottom - 812)).toBeLessThanOrEqual(1);

                // Nothing spills sideways at 320 either, the long name included.
                await page.setViewportSize({ width: 320, height: 640 });
                await expectNoPageOverflow(page);
                await expectTouchTargets(page, gradeWork.scope);
            } finally {
                runSql(`UPDATE user_profiles SET last_name = 'Cruz' WHERE first_name = 'Angela' AND last_name = '${long}'`);
            }
        });
    });

    test.describe("1366 grade work", () => {
        test.use({ viewport: { width: 1366, height: 768 } });

        test("dragging the divider resizes the students list and a reload keeps it", async ({ page }) => {
            await open(page, gradeWork, "light");

            const students = page.locator("#grade-work-students");
            const handle = page.locator('[data-split][data-split-key="grade-work-students"] [data-split-handle]');
            await expect(page.locator("[data-side-panel-toggle]")).toBeHidden();
            await expect(students).toBeVisible();
            // The grading controls are a plain block here: never inert, Submit in its old place.
            expect(await bar(page).locator("[data-action-bar-panel]").evaluate((el) => el.inert)).toBe(false);
            await expect(bar(page).locator("[data-action-bar-toggle]")).toBeHidden();

            const before = (await box(students)).width;
            // Today's 290px column until the instructor drags it.
            expect(before).toBeCloseTo(290, 0);
            const h = await box(handle);
            await page.mouse.move(h.left + h.width / 2, h.top + 40);
            await page.mouse.down();
            await page.mouse.move(h.left + h.width / 2 + 80, h.top + 40, { steps: 5 });
            await page.mouse.up();
            await expect.poll(async () => (await box(students)).width).toBeCloseTo(before + 80, -1);

            await page.reload();
            await expect(page.locator(gradeWork.scope)).toBeVisible();
            await expect.poll(async () => (await box(students)).width).toBeCloseTo(before + 80, -1);
        });
    });

    // Quiz Builder: below md a sticky bar with the save status and "＋ Add question", which opens the question types
    // upward; at md–lg the palette is a side panel; cards reorder with ▲ ▼ on touch. Quiz 1's questions are put back
    // in their seed order (by id) and an added question is deleted, so later specs see the seed.
    const builder = SCREENS.find((s) => s.name === "instructor-quiz-builder");
    const qbar = (page) => page.locator("[data-quiz-builder-bar]");
    const cards = (page) => page.locator("[data-question-list] > [data-question-card]");
    const cardIds = (page) => cards(page).evaluateAll((all) => all.map((c) => c.dataset.questionId));
    const restoreOrder = (ids) => runSql(
        `UPDATE assessment_questions SET order_index = CASE id ${ids.map((id, i) => `WHEN ${Number(id)} THEN ${i + 1}`).join(" ")} END WHERE id IN (${ids.map(Number).join(",")})`,
    );

    test.describe("375 quiz builder", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("the bar is pinned, a focused points field clears it, and Add question opens the types upward and adds one", async ({ page }) => {
            test.slow();
            await open(page, builder, "light");

            const closed = await box(qbar(page));
            expect(Math.abs(closed.bottom - 812), "the bar rests on the bottom edge").toBeLessThanOrEqual(1);
            await expect(qbar(page).locator("[data-builder-save-message]")).toHaveText("All changes saved");
            await expect(page.locator("#quiz-builder-palette")).toBeHidden();

            // Review Focus 2: a points field in the list, scrolled until its lower part is under the bar, then tapped.
            const points = cards(page).nth(1).locator("[data-question-points-input]");
            // (WebKit can settle a scroll a few pixels off, so it is nudged until the field sits 12px under the bar.)
            await expect(async () => {
                await points.evaluate((el) => {
                    const bar = globalThis.document.querySelector("[data-quiz-builder-bar]").getBoundingClientRect();
                    globalThis.scrollBy({ top: el.getBoundingClientRect().bottom - bar.top - 12, behavior: "instant" });
                });
                const field = await box(points);
                const barTop = (await box(qbar(page))).top;
                expect(field.bottom, "the field starts partly under the bar").toBeGreaterThan(barTop);
                expect(field.top + field.height / 2, "but its middle, where the tap lands, is above it").toBeLessThan(barTop);
            }).toPass({ timeout: 10_000 });
            await points.tap();
            await expect(points).toBeFocused();
            await expect.poll(async () => (await box(points)).bottom, { message: "the focused field clears the bar" })
                .toBeLessThanOrEqual((await box(qbar(page))).top);
            expect(Math.abs((await box(qbar(page))).bottom - 812), "the bar is still on the bottom edge").toBeLessThanOrEqual(1);
            expect(await points.getAttribute("inputmode")).toBe("decimal");

            // "＋ Add question" opens the types upward: the row stays on the edge, the panel rises above it.
            const toggle = qbar(page).locator("[data-action-bar-toggle]");
            await expect(async () => {
                await toggle.tap({ timeout: 2_000 });
                await expect(toggle).toHaveAttribute("aria-expanded", "true", { timeout: 2_000 });
            }).toPass({ timeout: 20_000 });
            const essay = qbar(page).locator('[data-palette-item][data-palette-type="Essay"]');
            await expect(essay).toBeVisible();
            await expect.poll(async () => (await box(qbar(page).locator("[data-action-bar-panel]"))).height).toBeGreaterThan(4 * 44);
            const panel = await box(qbar(page).locator("[data-action-bar-panel]"));
            const row = await box(toggle);
            expect(panel.bottom).toBeLessThanOrEqual(row.top);
            expect(Math.abs((await box(qbar(page))).bottom - 812)).toBeLessThanOrEqual(1);
            await expectTouchTargets(page, "[data-quiz-builder-bar]");
            await expectAccessible(page, "[data-quiz-builder-bar]");

            const count = await cards(page).count();
            let added = null;
            try {
                const created = page.waitForResponse((r) => /\/assessments\/\d+\/questions$/.test(r.url()) && r.request().method() === "POST");
                await essay.tap();
                const response = await created;
                expect(response.ok()).toBe(true);
                added = String((await response.json()).question.id);

                await expect(cards(page)).toHaveCount(count + 1);
                const last = cards(page).last();
                await expect(last).toHaveAttribute("data-question-id", added);
                await expect(last.locator("[data-question-type-badge]")).toHaveText("Essay");
                await expect(toggle).toHaveAttribute("aria-expanded", "false");
                await expect(last.locator("[data-question-text]")).toBeFocused();
                await expect(last.locator('[data-question-move="down"]')).toBeDisabled();
                await expectTouchTargets(page, builder.scope);

                // Put it away through the app, which also recounts the quiz's total and rescores.
                await last.locator("[data-delete-question]").tap();
                const deleted = page.waitForResponse((r) => r.url().endsWith(`/questions/${added}`) && r.request().method() === "DELETE");
                await page.locator("[data-confirm-delete-question]").tap();
                expect((await deleted).ok()).toBe(true);
                added = null;
                await expect(cards(page)).toHaveCount(count);
            } finally {
                if (added) {
                    runSql(`DELETE FROM assessment_questions WHERE id = ${Number(added)}`);
                }
            }
        });

        test("moving the second card up swaps it, focus stays on the arrow, and a reload keeps the order", async ({ page }) => {
            await open(page, builder, "light");

            const ids = await cardIds(page);
            try {
                await expect(cards(page).nth(0).locator('[data-question-move="up"]')).toBeDisabled();
                const up = cards(page).nth(1).locator('[data-question-move="up"]');
                expect((await box(up)).height).toBeGreaterThanOrEqual(44);

                const saved = page.waitForResponse((r) => /\/questions\/reorder$/.test(r.url()) && r.request().method() === "PATCH");
                // A tap that lands before the script has attached does nothing, so it is repeated until it takes.
                await expect(async () => {
                    if ((await cardIds(page))[0] !== ids[1]) {
                        await up.tap({ timeout: 2_000 });
                    }
                    expect((await cardIds(page))[0]).toBe(ids[1]);
                }).toPass({ timeout: 20_000 });
                expect((await saved).ok()).toBe(true);

                const swapped = [ids[1], ids[0], ...ids.slice(2)];
                expect(await cardIds(page)).toEqual(swapped);
                await expect(cards(page).nth(0).locator("[data-question-number]")).toHaveText("Q1");
                // Now first, its up arrow is disabled, so focus moved to its down arrow.
                await expect(cards(page).nth(0).locator('[data-question-move="down"]')).toBeFocused();
                await expect(page.locator("[data-question-move-status]")).toHaveText(`Question moved to position 1 of ${ids.length}.`);

                await page.reload();
                await expect(page.locator(builder.scope)).toBeVisible();
                await expect.poll(() => cardIds(page)).toEqual(swapped);
            } finally {
                restoreOrder(ids);
            }
        });
    });

    // The AI review tray only shows once a generation runs, so its controls are revealed in the page and checked here.
    test.describe("375 quiz builder AI tray", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        for (const theme of ["light", "dark"]) {
            test(`the tray header wraps and its buttons are touch-sized (${theme})`, async ({ page }) => {
                await open(page, builder, theme);

                await page.evaluate(() => {
                    const tray = globalThis.document.querySelector("[data-ai-review-tray]");
                    const show = (el) => { el.classList.remove("hidden"); el.classList.add("flex"); };
                    show(tray);
                    for (const hook of ["accept-clear", "generating", "shortfall"]) {
                        show(tray.querySelector(`[data-ai-tray-${hook}]`));
                    }
                    tray.querySelector("[data-ai-tray-shortfall-text]").textContent = "Only 3 of 5 questions were written.";
                });

                const tray = page.locator("[data-ai-review-tray]");
                await expect(tray).toBeVisible();
                await expectNoPageOverflow(page);
                await expectTouchTargets(page, "[data-ai-review-tray]");
                await expectAccessible(page, "[data-ai-review-tray]");
            });
        }
    });

    test.describe("834 quiz builder", () => {
        test.use({ viewport: { width: 834, height: 1112 }, hasTouch: true, isMobile: true });

        test("the palette is a side panel opened from the top bar", async ({ page }) => {
            await open(page, builder, "light");

            const palette = page.locator("#quiz-builder-palette");
            const toggle = page.locator('[data-side-panel-toggle][aria-controls="quiz-builder-palette"]');
            await expect(qbar(page)).toBeHidden();
            await expect(palette).toBeHidden();
            await expect(async () => {
                await toggle.tap({ timeout: 2_000 });
                await expect(palette).toBeVisible({ timeout: 2_000 });
            }).toPass({ timeout: 20_000 });
            await expect(palette.locator("[data-palette-item]")).toHaveCount(4);
            await expectTouchTargets(page, "#quiz-builder-palette");
            await page.keyboard.press("Escape");
            await expect(palette).toBeHidden();
            await expect(toggle).toBeFocused();
        });

        test("AI generate from the side panel closes the panel, so Escape closes only the dialog", async ({ page }) => {
            await open(page, builder, "light");

            const palette = page.locator("#quiz-builder-palette");
            const toggle = page.locator('[data-side-panel-toggle][aria-controls="quiz-builder-palette"]');
            await expect(async () => {
                await toggle.tap({ timeout: 2_000 });
                await expect(palette).toBeVisible({ timeout: 2_000 });
            }).toPass({ timeout: 20_000 });

            const dialog = page.locator("#ai-generate-modal");
            await palette.locator("[data-open-ai-generate]").tap();
            await expect(dialog).toBeVisible();
            await expect(palette).toBeHidden();

            await page.keyboard.press("Escape");
            await expect(dialog).toBeHidden();
            await expect(palette).toBeHidden();
        });
    });

    test.describe("1366 quiz builder", () => {
        test.use({ viewport: { width: 1366, height: 768 } });

        test("the palette is the pane beside the questions, with no phone bar, toggle or arrows", async ({ page }, testInfo) => {
            test.skip(testInfo.project.name === "webkit", "WebKit emulates an iPhone: its pointer is coarse, so the arrows show");
            await open(page, builder, "light");

            const palette = page.locator("#quiz-builder-palette");
            await expect(palette).toBeVisible();
            expect((await box(palette)).width).toBeCloseTo(290, 0);
            await expect(qbar(page)).toBeHidden();
            await expect(page.locator('[data-side-panel-toggle][aria-controls="quiz-builder-palette"]')).toBeHidden();
            await expect(page.locator("[data-question-move]").first()).toBeHidden();
            await expect(page.locator("[data-drag-handle]").first()).toBeVisible();
        });
    });

    // The assessment editor: from lg up the settings sit beside the content; at md–lg they are a side panel opened
    // from the top bar's "Settings"; below md they stack under the content as a "Settings" section. Below lg the
    // Save as Draft / Publish footer is pinned to the bottom edge.
    const editor = SCREENS.find((s) => s.name === "instructor-assessment-editor");
    const settings = (page) => page.locator("#editor-inspector-panel");
    const settingsToggle = (page) => page.locator('[data-side-panel-toggle][aria-controls="editor-inspector-panel"]');
    const footer = (page) => page.locator("footer:has([data-publish])");

    test.describe("375 assessment editor", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("Settings stack under the content, the footer is pinned with Publish in view, and a focused field clears it", async ({ page }) => {
            await open(page, editor, "light");

            // Stacked: in the flow under the content, not inert, no top-bar toggle; the summary folds and unfolds it.
            await expect(settings(page)).toBeVisible();
            await expect(settingsToggle(page)).toBeHidden();
            expect(await settings(page).evaluate((el) => el.inert)).toBe(false);
            const header = await box(page.locator("main header").first());
            expect((await box(settings(page))).top).toBeGreaterThan(header.bottom);
            const summary = settings(page).locator("[data-inspector-settings] > summary");
            const percent = settings(page).locator('[data-inspector-field="passing_percentage"]');
            expect((await box(summary)).height).toBeGreaterThanOrEqual(44);
            await summary.tap();
            await expect(percent).toBeHidden();
            await summary.tap();
            await expect(percent).toBeVisible();

            // The footer rests on the bottom edge; Save as Draft and Publish share its row, inside the window.
            const bottom = await box(footer(page));
            expect(Math.abs(bottom.bottom - 812), "the footer rests on the bottom edge").toBeLessThanOrEqual(1);
            await expectInViewport(page, "footer [data-publish]");
            const draft = await box(footer(page).locator("[data-save-draft]"));
            const publish = await box(footer(page).locator("[data-publish]"));
            expect(Math.abs(draft.top - publish.top)).toBeLessThanOrEqual(1);
            expect(publish.height).toBeGreaterThanOrEqual(44);
            await expect(footer(page).getByRole("button", { name: "Delete assessment" })).toBeVisible();
            await expectTouchTargets(page, "footer:has([data-publish])");

            // A field scrolled until its lower part is under the footer, then tapped, ends above the footer.
            await expect(async () => {
                await percent.evaluate((el) => {
                    const bar = globalThis.document.querySelector("footer:has([data-publish])").getBoundingClientRect();
                    globalThis.scrollBy({ top: el.getBoundingClientRect().bottom - bar.top - 12, behavior: "instant" });
                });
                const field = await box(percent);
                const barTop = (await box(footer(page))).top;
                expect(field.bottom, "the field starts partly under the footer").toBeGreaterThan(barTop);
                expect(field.top + field.height / 2, "but its middle, where the tap lands, is above it").toBeLessThan(barTop);
            }).toPass({ timeout: 10_000 });
            await percent.tap();
            await expect(percent).toBeFocused();
            await expect.poll(async () => (await box(percent)).bottom, { message: "the focused field clears the footer" })
                .toBeLessThanOrEqual((await box(footer(page))).top);
            expect(await percent.getAttribute("inputmode")).toBe("numeric");
        });

        test("a 40-character title does not widen the editor or the list at 320", async ({ page }) => {
            // Review Focus 4: an unbroken 40-character title on the assignment, put back by id after.
            const long = "Gradecalculatorassignmentwithalongtitle1";
            expect(long).toHaveLength(40);
            await open(page, editor, "light");
            const id = Number(new URL(page.url()).searchParams.get("assessment"));
            expect(id).toBeGreaterThan(0);
            const title = (await page.locator("main header > span").first().innerText()).trim();
            expect(title).toBe("Assignment 1: Grade Calculator");

            runSql(`UPDATE assessments SET title = '${long}' WHERE id = ${id}`);
            try {
                await page.setViewportSize({ width: 320, height: 640 });
                await page.reload();
                await expect(page.locator(editor.scope)).toBeVisible();
                await expect(page.locator("main header > span").first()).toHaveText(long);
                // The top bar's trail truncates the title; the page does not scroll sideways.
                const crumb = page.locator('.sticky-bar-top [aria-current="page"]');
                expect(await crumb.evaluate((el) => el.scrollWidth > el.clientWidth)).toBe(true);
                await expectNoPageOverflow(page);
                await expectTouchTargets(page, editor.scope);
                await expectInViewport(page, "footer [data-publish]");

                await page.goto(await instructorAssessmentsPath(page));
                await expect(page.locator("[data-assessment-card]").filter({ hasText: long })).toBeVisible();
                await expectNoPageOverflow(page);
                await expectTouchTargets(page, "[data-assessments-overview]");
            } finally {
                runSql(`UPDATE assessments SET title = '${title.replace(/'/g, "''")}' WHERE id = ${id}`);
            }
        });

        test("a tap into long instructions keeps the tapped line in view, clear of the footer", async ({ page }) => {
            test.slow();
            await open(page, editor, "light");

            // Long instructions are typed into the editor and never saved (Cancel at the end), so nothing is stored.
            const edit = page.locator("[data-edit-instructions]");
            const text = page.locator("#instructions-editor [contenteditable=\"true\"]");
            await expect(async () => {
                await edit.tap({ timeout: 2_000 });
                await expect(text).toBeVisible({ timeout: 2_000 });
            }).toPass({ timeout: 20_000 });
            await text.tap();
            // Pasted as one block (typing 60 lines drops keys on WebKit), through the editor's own paste handling.
            const html = Array.from({ length: 60 }, (_, i) => `<p>Line ${i + 1} of the instructions.</p>`).join("");
            await text.evaluate((el, value) => {
                const data = new globalThis.DataTransfer();
                data.setData("text/html", value);
                data.setData("text/plain", value.replace(/<[^>]+>/g, " "));
                el.dispatchEvent(new globalThis.ClipboardEvent("paste", { clipboardData: data, bubbles: true, cancelable: true }));
            }, html);
            await page.evaluate(() => globalThis.document.activeElement.blur());

            // Line 15 in the middle of the window, while the instructions run on far below the footer (far enough that
            // scrolling their end clear of the footer would throw line 15 off the top).
            const target = text.locator("p").nth(14);
            expect(await text.locator("p").count()).toBeGreaterThanOrEqual(60);
            await target.evaluate((el) => globalThis.scrollBy({ top: el.getBoundingClientRect().top - globalThis.innerHeight / 2, behavior: "instant" }));
            const footerTop = (await box(footer(page))).top;
            expect((await box(text)).bottom, "the instructions run on under the footer").toBeGreaterThan(footerTop + 406);

            await target.tap();
            await expect(text).toBeFocused();
            // Two frames: the clearance check runs one frame after the tap.
            await page.evaluate(() => new Promise((resolve) => globalThis.requestAnimationFrame(() => globalThis.requestAnimationFrame(resolve))));
            const header = (await box(page.locator("[data-app-header]"))).bottom;
            const caret = await page.evaluate(() => globalThis.getSelection().getRangeAt(0).getBoundingClientRect().toJSON());
            const line = await box(target);
            for (const [name, r] of [["the caret", caret], ["the tapped line", line]]) {
                expect(r.top, `${name} is not under the header`).toBeGreaterThanOrEqual(header - 1);
                expect(r.bottom, `${name} is above the footer`).toBeLessThanOrEqual(footerTop + 1);
            }
            expect(caret.height).toBeGreaterThan(0);

            await page.locator("[data-cancel-instructions]").tap();
            await expect(text).toBeHidden();
        });
    });

    test.describe("834 assessment editor", () => {
        test.use({ viewport: { width: 834, height: 1112 }, hasTouch: true, isMobile: true });

        test("Settings is a side panel opened from the top bar, and the footer is pinned", async ({ page }) => {
            await open(page, editor, "light");

            await expect(settings(page)).toBeHidden();
            await expect(async () => {
                await settingsToggle(page).tap({ timeout: 2_000 });
                await expect(settings(page)).toBeVisible({ timeout: 2_000 });
            }).toPass({ timeout: 20_000 });
            await expect(settings(page).locator("[data-inspector-settings] > summary")).toBeHidden();
            await expect(settings(page).locator('[data-inspector-field="due_date"]')).toBeVisible();
            await expectTouchTargets(page, "#editor-inspector-panel");
            await page.keyboard.press("Escape");
            await expect(settings(page)).toBeHidden();
            await expect(settingsToggle(page)).toBeFocused();

            expect(Math.abs((await box(footer(page))).bottom - 1112), "the footer rests on the bottom edge").toBeLessThanOrEqual(1);
            await expectInViewport(page, "footer [data-publish]");
        });

        test("Escape closes the Choose students dialog opened from the panel, and the panel stays open", async ({ page }) => {
            await open(page, editor, "light");
            await expect(async () => {
                await settingsToggle(page).tap({ timeout: 2_000 });
                await expect(settings(page)).toBeVisible({ timeout: 2_000 });
            }).toPass({ timeout: 20_000 });

            // Switched on in the page only (not saved), so the Choose students button shows.
            await settings(page).locator('label:has([data-specific-toggle])').tap();
            const dialog = page.locator("#assignees-modal");
            await settings(page).locator("[data-specific-open]").tap();
            await expect(dialog).toBeVisible();

            await page.keyboard.press("Escape");
            await expect(dialog).toBeHidden();
            await expect(settings(page)).toBeVisible();
            expect(await settings(page).evaluate((el) => el.inert)).toBe(false);

            await page.keyboard.press("Escape");
            await expect(settings(page)).toBeHidden();
        });
    });

    test.describe("1366 assessment editor", () => {
        test.use({ viewport: { width: 1366, height: 768 } });

        test("the settings are the pane beside the content, with no toggle or phone summary", async ({ page }) => {
            await open(page, editor, "light");

            const pane = await box(settings(page));
            await expect(settings(page)).toBeVisible();
            expect(pane.width).toBeGreaterThanOrEqual(256);
            expect(pane.width).toBeLessThanOrEqual(352);
            expect(pane.left).toBeGreaterThan((await box(page.locator("main header").first())).right);
            await expect(settingsToggle(page)).toBeHidden();
            await expect(settings(page).locator("[data-inspector-settings] > summary")).toBeHidden();
            expect(await settings(page).evaluate((el) => el.inert)).toBe(false);
        });
    });

    // The gradebook: a grid at every width that scrolls inside its own box, with the header row and the name column
    // pinned; on a phone the toolbar's menus are bottom sheets. IT101-B (instructor1's, no students) is the empty
    // section, and with one throwaway student and 30 throwaway manual items, the wide one (Review Focus 5).
    const gradebook = SCREENS.find((s) => s.name === "instructor-gradebook");
    const gradesTab = { ...gradebook, path: (page) => instructorGradesPath(page, "A"), scope: "main:has([data-gradebook-toolbar])" };
    const sectionB = { ...gradebook, path: (page) => instructorGradesPath(page, "B", "?roster=1"), scope: "main:has([data-gradebook-roster])" };
    const grid = (page) => page.locator("[data-gradebook-roster] [data-scroll-x]");
    // IT101-B and its grading scheme, by name rather than id, for the throwaway rows.
    const offeringB = "(SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id WHERE c.course_code = 'IT101' AND o.section_code = 'B')";
    const schemeB = `(SELECT id FROM grading_schemes WHERE course_offering_id = ${offeringB})`;
    const student4 = "(SELECT user_id FROM auth_identities WHERE secret = 'student4@mentora.test')";

    test.describe("375 gradebook", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("the roster scrolls inside its box with the header row and the name column pinned", async ({ page }) => {
            await open(page, gradebook, "light");

            const roster = grid(page);
            await expect(roster).toHaveAttribute("role", "region");
            await expect(roster).toHaveAttribute("aria-label", "Class roster");
            await expect(roster).toHaveAttribute("tabindex", "0");
            await expect(roster).toHaveAttribute("data-sticky-first", "");
            const width = await roster.evaluate((el) => el.scrollWidth - el.clientWidth);
            expect(width, "the grid is wider than its box").toBeGreaterThanOrEqual(200);
            await expect(roster).toHaveAttribute("data-fade-end", "");
            await expect(roster).not.toHaveAttribute("data-fade-start", "");

            const lastBefore = (await box(roster.locator("thead th").last())).right;
            await roster.evaluate((el) => (el.scrollLeft = 200));
            await expect(roster).toHaveAttribute("data-fade-start", "");
            const edge = await roster.evaluate((el) => el.getBoundingClientRect().left + el.clientLeft);
            for (const cell of [roster.locator("thead th").first(), roster.locator("[data-roster-row] > td").first()]) {
                expect(Math.abs((await box(cell)).left - edge), "the name column stays at the box's left edge").toBeLessThanOrEqual(1);
            }
            // The other columns moved under it.
            expect(lastBefore - (await box(roster.locator("thead th").last())).right).toBeGreaterThanOrEqual(199);

            // The score input: decimal keyboard, finger-sized, 16px (no zoom on focus).
            const score = roster.locator("[data-manual-score-input]").first();
            await expect(score).toHaveAttribute("inputmode", "decimal");
            expect((await box(score)).height).toBeGreaterThanOrEqual(44);
            expect(await score.evaluate((el) => globalThis.getComputedStyle(el).fontSize)).toBe("16px");
            // The header row's labels stay on one line.
            for (const th of await roster.locator("thead th").all()) {
                expect((await box(th)).height, await th.innerText()).toBeLessThan(60);
            }

            await expectNoPageOverflow(page);
        });

        test("a section with no students shows the empty state and no grid", async ({ page }) => {
            await open(page, sectionB, "light");

            await expect(page.locator("[data-gradebook-roster]").getByText("No students yet")).toBeVisible();
            await expect(page.locator("[data-gradebook-roster] table")).toHaveCount(0);
            await expect(page.locator("[data-gradebook-roster] [data-scroll-x]")).toHaveCount(0);
            await expect(page.locator("[data-roster-controls]")).toHaveCount(0);
            await expectNoPageOverflow(page);
            await expectTouchTargets(page, "[data-gradebook-roster]");
            await expectAccessible(page, "[data-gradebook-roster]");
        });

        test("30 manual items and one student scroll inside the grid, with no page overflow (Review Focus 5)", async ({ page }) => {
            const marker = "RF5 item";
            const numbers = Array.from({ length: 30 }, (_, i) => `SELECT ${i + 1} AS n`).join(" UNION ALL ");
            // Throwaway rows only: the enrolment is added only where there is none, and removed only if it was added.
            const enrolled = runSql(
                `INSERT INTO enrollments (offering_id, student_user_id, status, enrolled_at, updated_at) SELECT ${offeringB}, ${student4}, 'enrolled', NOW(), NOW() FROM DUAL `
                + `WHERE NOT EXISTS (SELECT 1 FROM enrollments e WHERE e.offering_id = ${offeringB} AND e.student_user_id = ${student4})`,
            ).includes("(1 row(s))");
            try {
                runSql(
                    "INSERT INTO grade_items (grading_scheme_id, category_id, name, max_points, sort_order, status, created_at, updated_at) "
                    + `SELECT ${schemeB}, NULL, CONCAT('${marker} ', n.n), 10, 100 + n.n, 'Active', NOW(), NOW() FROM (${numbers}) n`,
                );

                await open(page, { ...sectionB, scope: "main:has([data-gradebook-roster] [data-roster-row])" }, "light");
                const roster = grid(page);
                await expect(page.locator("[data-roster-row]")).toHaveCount(1);
                await expect(roster.locator("[data-manual-score-input]")).toHaveCount(30);

                await expectNoPageOverflow(page);
                await expectTouchTargets(page, "[data-gradebook-roster]");

                // To the far end: the name column is still at the left edge, and the end fade is gone.
                await roster.evaluate((el) => (el.scrollLeft = el.scrollWidth));
                await expect(roster).not.toHaveAttribute("data-fade-end", "");
                await expect(roster).toHaveAttribute("data-fade-start", "");
                const edge = await roster.evaluate((el) => el.getBoundingClientRect().left + el.clientLeft);
                expect(Math.abs((await box(roster.locator("[data-roster-row] > td").first())).left - edge)).toBeLessThanOrEqual(1);
                await expectNoPageOverflow(page);
            } finally {
                runSql(`DELETE FROM grade_items WHERE grading_scheme_id = ${schemeB} AND name LIKE '${marker} %'`);
                if (enrolled) {
                    runSql(`DELETE FROM enrollments WHERE offering_id = ${offeringB} AND student_user_id = ${student4}`);
                }
            }
        });

        test("the toolbar's Reports and grade sheet menus open as bottom sheets, and their items still work", async ({ page }) => {
            await open(page, gradesTab, "light");

            await expect(page.locator("[data-toggle-report-menu]")).toBeHidden();
            await expect(page.locator("[data-toggle-grade-sheet-menu]")).toBeHidden();

            const menus = [
                ["report-menu-sheet", '[data-report-path="summary"][data-report-mode="print"]'],
                ["grade-sheet-menu-sheet", "[data-grade-sheet-export]"],
            ];
            for (const [id, item] of menus) {
                const opener = page.locator(`[data-row-actions-open][aria-controls="${id}"]`);
                const sheet = page.locator(`#${id}`);
                await openDialog(opener, sheet);

                await expect(sheet.locator(item)).toBeVisible();
                expect(Math.abs((await box(sheet.locator("[data-dialog-panel]"))).bottom - 812), "a sheet rests on the bottom edge").toBeLessThanOrEqual(1);
                await expectTouchTargets(page, `#${id}`);
                await expectNoPanelOverflow(sheet);
                await expectAccessible(page, `#${id}`);

                await page.keyboard.press("Escape");
                await expect(sheet).toBeHidden();
                await expect(opener).toBeFocused();
                // Its items went back to the dropdown.
                await expect(page.locator(`[data-gradebook-toolbar] ${item}`)).toHaveCount(1);
            }

            // An item in the sheet runs its own handler: Print view opens the class summary in a tab, and the sheet closes.
            const sheet = page.locator("#report-menu-sheet");
            await openDialog(page.locator('[data-row-actions-open][aria-controls="report-menu-sheet"]'), sheet);
            const popup = page.waitForEvent("popup");
            await sheet.locator('[data-report-mode="print"]').tap();
            const view = await popup;
            await expect(sheet).toBeHidden();
            await expect(view.getByRole("heading", { name: "Class summary" })).toBeVisible();
            // The print view on a phone screen: its tables scroll in their own box.
            await expectNoPageOverflow(view);
            await view.close();

            await expectNoPageOverflow(page);
            await expectTouchTargets(page, "[data-gradebook-toolbar]");
        });

        test("the scheme editor's rows wrap: the name on its own row, then a decimal weight", async ({ page }) => {
            await open(page, SCREENS.find((s) => s.name === "instructor-scheme-editor"), "light");

            const category = page.locator("[data-grade-category]").first();
            const name = category.locator("[data-grade-category-name]");
            const weight = category.locator("[data-grade-category-weight]");
            expect((await box(weight)).top, "the weight wraps under the name").toBeGreaterThanOrEqual((await box(name)).bottom);
            await expect(weight).toHaveAttribute("inputmode", "decimal");
            expect((await box(weight)).height).toBeGreaterThanOrEqual(44);
            await expectNoPageOverflow(page);
        });

        test("the Grades page: section cards scroll sideways, the To grade filters stack", async ({ page }) => {
            await open(page, SCREENS.find((s) => s.name === "instructor-grades-page"), "light");

            const cards = page.locator("[data-section-cards]");
            expect(await cards.evaluate((el) => globalThis.getComputedStyle(el).scrollSnapType)).toContain("x");
            expect(await cards.evaluate((el) => globalThis.getComputedStyle(el).overflowX)).toBe("auto");

            await page.goto(`${new URL(page.url()).pathname}?tab=to-grade`);
            const section = page.locator("[data-queue-section]");
            const type = page.locator("[data-queue-type]");
            await expect(section).toBeVisible();
            expect((await box(type)).top, "the filters stack").toBeGreaterThanOrEqual((await box(section)).bottom);
            await expectNoPageOverflow(page);
            await expectTouchTargets(page, "main");
        });
    });

    test.describe("1366 gradebook", () => {
        test.use({ viewport: { width: 1366, height: 768 } });

        test("the toolbar's menus are the dropdowns, with no phone opener", async ({ page }) => {
            await open(page, gradesTab, "light");

            await expect(page.locator('[data-row-actions-open][aria-controls="report-menu-sheet"]')).toBeHidden();
            await page.locator("[data-toggle-report-menu]").click();
            const menu = page.locator("[data-report-menu]");
            await expect(menu).toBeVisible();
            expect(await menu.evaluate((el) => globalThis.getComputedStyle(el).position)).toBe("absolute");
            await expect(page.locator("#report-menu-sheet")).toBeHidden();
        });
    });

    // Module management: below md the module, unit and item ⋯ menus open as a bottom sheet (shared/row-actions.js
    // moves the entries in); under a coarse pointer ▲ ▼ buttons reorder through the drag's save path.
    const modules = SCREENS.find((s) => s.name === "instructor-modules");
    const modulePage = SCREENS.find((s) => s.name === "instructor-module");
    const sheet = (page) => page.locator("#content-actions-sheet");
    const moduleIds = (page) => page.locator("#modules-container > [data-module-wrapper]").evaluateAll((rows) => rows.map((r) => r.dataset.moduleId));
    const unitTitles = (page) => page.locator("[data-learning-unit-list] > [data-learning-unit-row] [data-learning-unit] span.text-lg").allInnerTexts();
    const offeringA = "(SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id WHERE c.course_code = 'IT101' AND o.section_code = 'A')";
    const gettingStarted = `(SELECT id FROM course_modules WHERE offering_id = ${offeringA} AND title = '${MODULE_TITLE}')`;
    const orientation = `(SELECT id FROM learning_units WHERE module_id = ${gettingStarted} AND title = 'Course Orientation')`;
    const byTitle = (titles) => `CASE title ${titles.map((t, i) => `WHEN '${t}' THEN ${i + 1}`).join(" ")} END WHERE title IN (${titles.map((t) => `'${t}'`).join(", ")})`;
    const SEED_MODULES = ["Getting Started", "Programming Fundamentals", "Working with Data"];
    const SEED_UNITS = ["Course Orientation", "Computer Basics"];
    const SEED_ITEMS = ["Welcome to Introduction to Computing", "Course Orientation Video", "Course Syllabus and Class Policies", "Course Pre-Test"];
    // The seed's order, by title (runSql returns no ids). Run before a case too, so a killed run cannot leave the next one a moved list.
    const restoreModuleOrder = () => runSql(`UPDATE course_modules SET order_index = ${byTitle(SEED_MODULES)} AND offering_id = ${offeringA}`);
    const restoreUnitOrder = () => {
        runSql(`UPDATE learning_units SET order_index = ${byTitle(SEED_UNITS)} AND module_id = ${gettingStarted}`);
        runSql(`UPDATE learning_items SET order_index = ${byTitle(SEED_ITEMS)} AND learning_unit_id = ${orientation}`);
    };
    const LONG_TITLE = `RF4-${"Abcdefghij".repeat(3)}Abcdef`; // 40 characters, one unbroken word
    const restoreTitle = () => runSql(`UPDATE course_modules SET title = 'Working with Data' WHERE offering_id = ${offeringA} AND title LIKE 'RF4-%'`);

    test.describe("375 modules", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("a module's ⋯ opens a bottom sheet whose Edit opens the editor, and focus comes back to the ⋯", async ({ page }) => {
            await open(page, modules, "light");

            const row = page.locator("[data-module-wrapper]").filter({ hasText: MODULE_TITLE });
            await expect(row.locator("[data-module-actions]")).toBeHidden();
            await expect(row.locator("[data-module-card-drag-handle]")).toBeHidden();
            const opener = row.locator("[data-row-actions-open]");
            await opener.tap();

            await expect(sheet(page)).toBeVisible();
            await expect(sheet(page).locator("[data-dialog-title]")).toHaveText(MODULE_TITLE);
            const slot = sheet(page).locator("[data-row-actions-slot]");
            await expect(slot.locator("button")).toHaveText([/Edit/, /Dependencies/, /Publish|Unpublish/, /Copy to/, /Move to/, /Delete/]);
            // The tap opened the sheet only: the card did not toggle open under it.
            await expect(row.locator("[data-module-content]")).not.toHaveClass(/max-h-\[2000px\]/);
            const panel = await box(sheet(page).locator("[data-dialog-panel]"));
            expect(Math.round(panel.bottom)).toBe(812);
            // Delete is red, under a divider.
            const del = slot.locator("[data-delete-module]");
            expect(await del.evaluate((el) => globalThis.getComputedStyle(el).borderTopWidth)).toBe("1px");
            expect(await del.evaluate((el) => globalThis.getComputedStyle(el).color)).toMatch(/rgb\(220, 38, 38\)|oklch\(0\.577 0\.245 27\.325/); // red-600
            await expectTouchTargets(page, "#content-actions-sheet");
            await expectNoPanelOverflow(sheet(page));
            await expectAccessible(page, "#content-actions-sheet");

            await slot.locator("[data-edit-module]").tap();
            await expect(sheet(page)).toBeHidden();
            const editor = page.locator("#module-editor-modal");
            await expect(editor).toBeVisible();
            await expect(editor.locator('input[name="title"]')).toHaveValue(MODULE_TITLE);
            // The entries went back to the row's menu, hooks and all.
            await expect(row.locator("[data-module-actions-panel] [data-edit-module]")).toHaveCount(1);

            await page.keyboard.press("Escape");
            await expect(editor).toBeHidden();
            await expect(opener).toBeFocused();
        });

        test("moving the second module up swaps it and the order holds after a reload (Review Focus 3)", async ({ page }) => {
            restoreModuleOrder();
            try {
                await open(page, modules, "light");

                const [first, second, third] = await moduleIds(page);
                const up = (id) => page.locator(`[data-module-wrapper][data-module-id="${id}"] [data-module-move="up"]`);
                const down = (id) => page.locator(`[data-module-wrapper][data-module-id="${id}"] [data-module-move="down"]`);
                await expect(up(first)).toBeDisabled();
                await expect(down(third)).toBeDisabled();

                const saved = page.waitForResponse((r) => r.url().includes("/modules/reorder") && r.request().method() === "PATCH");
                await up(second).tap();
                expect((await saved).ok()).toBe(true);

                expect(await moduleIds(page)).toEqual([second, first, third]);
                // At the top ▲ is disabled, so focus is on the moved module's ▼.
                await expect(up(second)).toBeDisabled();
                await expect(down(second)).toBeFocused();
                await expect(page.locator("[data-module-move-status]")).toHaveText("Module moved to position 1 of 3.");

                // ▼ keeps focus on itself while it can still move.
                const again = page.waitForResponse((r) => r.url().includes("/modules/reorder"));
                await down(second).tap();
                expect((await again).ok()).toBe(true);
                await expect(down(second)).toBeFocused();
                // Listening starts before the tap: a quick save can answer before a listener started after it.
                const last = page.waitForResponse((r) => r.url().includes("/modules/reorder"));
                await up(second).tap();
                await expect(page.locator("[data-module-move-status]")).toHaveText("Module moved to position 1 of 3.");
                await last;

                await page.reload();
                await expect(page.locator(modules.scope)).toBeVisible();
                expect(await moduleIds(page)).toEqual([second, first, third]);
            } finally {
                restoreModuleOrder();
            }
        });

        test("units and items move with ▲ ▼ too, and the order holds after a reload", async ({ page }) => {
            restoreUnitOrder();
            try {
                await open(page, modulePage, "light");

                const units = page.locator("[data-learning-unit-list] > [data-learning-unit-row]");
                const items = page.locator('[data-learning-unit-wrapper]:has-text("Course Orientation Video") [data-learning-unit-items] > [data-learning-item-row]');
                await expect(page.locator("[data-learning-unit-drag-handle]").first()).toBeHidden();
                await expect(page.locator("[data-learning-item-drag-handle]").first()).toBeHidden();
                expect(await unitTitles(page)).toEqual(SEED_UNITS);

                const itemSaved = page.waitForResponse((r) => r.url().includes("/modules/items/reorder") && r.request().method() === "PATCH");
                const video = items.filter({ has: page.getByText("Course Orientation Video", { exact: true }) }).locator('[data-learning-item-move="up"]');
                await video.tap();
                expect((await itemSaved).ok()).toBe(true);
                await expect(page.locator("[data-module-move-status]")).toHaveText("Learning item moved to position 1 of 4.");
                // Moving an item opened nothing.
                await expect(page.locator("[data-learning-unit-list]")).toBeVisible();

                const unitSaved = page.waitForResponse((r) => r.url().includes("/learning-units/reorder") && r.request().method() === "PATCH");
                await units.filter({ hasText: "Computer Basics" }).locator(':scope > div > button[data-learning-unit-move="up"]').tap();
                expect((await unitSaved).ok()).toBe(true);
                await expect(page.locator("[data-module-move-status]")).toHaveText("Learning unit moved to position 1 of 2.");

                await page.reload();
                await expect(page.locator(modulePage.scope)).toBeVisible();
                expect(await unitTitles(page)).toEqual(["Computer Basics", "Course Orientation"]);
                await expect(items.first()).toContainText("Course Orientation Video");
            } finally {
                restoreUnitOrder();
            }
        });

        test("a unit's ⋯ opens the sheet and Dependencies opens its dialog", async ({ page }) => {
            await open(page, modulePage, "light");

            const unit = page.locator("[data-learning-unit-wrapper]").filter({ hasText: "Computer Basics" });
            const opener = unit.locator("[data-learning-unit] [data-row-actions-open]").first();
            await opener.tap();
            await expect(sheet(page)).toBeVisible();
            await expect(sheet(page).locator("[data-dialog-title]")).toHaveText("Computer Basics");
            // The tap did not fold the unit under it.
            await expect(unit.locator("[data-learning-unit-content]")).toHaveClass(/max-h-\[3000px\]/);

            await sheet(page).locator("[data-learning-unit-dependencies]").tap();
            await expect(sheet(page)).toBeHidden();
            await expect(page.locator("#learning-unit-dependencies-modal")).toBeVisible();
            await page.keyboard.press("Escape");
            await expect(page.locator("#learning-unit-dependencies-modal")).toBeHidden();

            // An item's ⋯ opens the same sheet with the item's entries.
            const item = page.locator("[data-learning-item-wrapper]").filter({ has: page.getByText("Hardware and Software", { exact: true }) });
            await item.locator("[data-row-actions-open]").tap();
            await expect(sheet(page).locator("[data-dialog-title]")).toHaveText("Hardware and Software");
            await expect(sheet(page).locator("[data-row-actions-slot] button")).toHaveText([/Edit/, /Dependencies/, /Copy to/, /Move to/, /Publish|Unpublish/, /Delete/]);
            await expectTouchTargets(page, "#content-actions-sheet");
            await page.keyboard.press("Escape");
            await expect(sheet(page)).toBeHidden();
            // The item did not open under the sheet.
            await expect(page).not.toHaveURL(/[?&]item=/);
        });
    });

    test.describe("320 modules", () => {
        test.use({ viewport: { width: 320, height: 640 }, hasTouch: true, isMobile: true });

        test("a 40-character module title does not overflow its card or the page (Review Focus 4)", async ({ page }) => {
            restoreTitle();
            runSql(`UPDATE course_modules SET title = '${LONG_TITLE}' WHERE offering_id = ${offeringA} AND title = 'Working with Data'`);
            try {
                await open(page, modules, "light");
                const card = page.locator("[data-module-card]").filter({ hasText: LONG_TITLE });
                await expect(card).toBeVisible();
                await expectNoPageOverflow(page);
                const inside = await card.evaluate((el) => el.scrollWidth <= el.clientWidth);
                expect(inside, "the card holds its title").toBe(true);
                const title = await box(card.getByText(LONG_TITLE));
                const outer = await box(card);
                expect(title.right).toBeLessThanOrEqual(outer.right);
                await expectTouchTargets(page, "#modules-container");

                // The module page's top bar wraps its actions under the title.
                const id = await page.locator("[data-module-wrapper]").filter({ hasText: LONG_TITLE }).getAttribute("data-module-id");
                await page.goto(`${await instructorModulesPath(page)}?module=${id}&view=1`);
                await expect(page.locator(".sticky-bar-top").getByText(LONG_TITLE)).toBeVisible();
                await expectNoPageOverflow(page);
                const bar = page.locator(".sticky-bar-top");
                expect(await bar.evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true);
                await expectTouchTargets(page, ".sticky-bar-top");
            } finally {
                restoreTitle();
            }
        });
    });

    test.describe("1366 modules", () => {
        test.use({ viewport: { width: 1366, height: 768 } });

        test("the ⋯ menus are the dropdowns, with drag handles and no arrows", async ({ page }, testInfo) => {
            test.skip(testInfo.project.name === "webkit", "WebKit emulates an iPhone (a coarse pointer)");
            await open(page, modules, "light");

            const row = page.locator("[data-module-wrapper]").filter({ hasText: MODULE_TITLE });
            await expect(row.locator("[data-row-actions-open]")).toBeHidden();
            await expect(row.locator("[data-module-move]").first()).toBeHidden();
            await expect(row.locator("[data-module-card-drag-handle]")).toBeVisible();

            await row.locator("[data-module-actions]").click();
            const menu = page.locator("[data-module-actions-panel]:visible");
            await expect(menu).toHaveCount(1);
            expect(await menu.evaluate((el) => globalThis.getComputedStyle(el).position)).toBe("fixed");
            await expect(menu.locator("[data-edit-module]")).toBeVisible();
            await expect(sheet(page)).toBeHidden();
        });
    });

    test.describe("1180 item editor", () => {
        test.use({ viewport: { width: 1180, height: 800 } });

        test("the contents column sticks under the app header, its heading in view, while the window scrolls", async ({ page }, testInfo) => {
            test.skip(testInfo.project.name === "webkit", "WebKit runs the phone only");
            await open(page, modulePage, "light");
            const id = await page.locator("[data-learning-item-wrapper]").filter({ has: page.getByText("Course Pre-Test", { exact: true }) }).getAttribute("data-learning-item");
            await page.goto(`${await instructorModulesPath(page)}?item=${id}&editor=1`);
            const panel = page.locator("[data-learning-item-panel] [data-contents-panel]");
            await expect(panel.locator("[data-panel-item]").first()).toBeVisible();

            await page.mouse.wheel(0, 250);
            await expect.poll(() => page.evaluate(() => globalThis.scrollY)).toBeGreaterThan(100);
            const header = await box(page.locator("[data-app-header]"));
            const column = await box(panel);
            expect(Math.round(column.top), "the column starts under the header").toBe(Math.round(header.bottom));
            expect(Math.round(column.bottom)).toBeLessThanOrEqual(800);
            await expect(panel.getByText("Module 1", { exact: true })).toBeInViewport();
        });
    });
});

// The learning item editors: the Save as Draft / Publish footer pinned below lg (the assessment editor's), the reading
// toolbar wrapping with finger-sized buttons, a long title at 320, and the item dialogs on a phone.
test.describe("instructor item editor", () => {
    const reading = SCREENS.find((s) => s.name === "instructor-item-editor-reading");
    const modulePage = SCREENS.find((s) => s.name === "instructor-module");
    const footer = (page) => page.locator("[data-learning-item-editor] footer:has([data-publish])");
    const box = (locator) => locator.evaluate((el) => el.getBoundingClientRect().toJSON());
    const frames = (page) => page.evaluate(() => new Promise((resolve) => globalThis.requestAnimationFrame(() => globalThis.requestAnimationFrame(resolve))));
    const offeringA = "(SELECT o.id FROM course_offerings o JOIN courses c ON c.id = o.course_id WHERE c.course_code = 'IT101' AND o.section_code = 'A')";
    const unitIds = `(SELECT u.id FROM learning_units u JOIN course_modules m ON m.id = u.module_id WHERE m.offering_id = ${offeringA})`;
    const LONG_TITLE = `RF4I-${"Abcdefghij".repeat(3)}Abcde`; // 40 characters, one unbroken word
    // By the marker, so a killed run cannot leave the next one the long title (runSql returns no ids). No helper table.
    const restoreTitle = () => runSql(`UPDATE learning_items SET title = '${READING_TITLE}' WHERE learning_unit_id IN ${unitIds} AND title LIKE 'RF4I-%'`);

    test.describe("375 item editor", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("the save bar is pinned to the bottom edge and the reading toolbar wraps with 44px buttons", async ({ page }) => {
            await open(page, reading, "light");

            const bar = footer(page);
            expect(Math.round((await box(bar)).bottom)).toBe(812);
            await expectInViewport(page, "[data-learning-item-editor] footer [data-publish]");
            const draft = await box(bar.locator("[data-save-draft]"));
            const publish = await box(bar.locator("[data-publish]"));
            expect(Math.round(draft.top), "Save as Draft and Publish share a row").toBe(Math.round(publish.top));
            await expectTouchTargets(page, "[data-learning-item-editor] footer");

            // Still on the bottom edge half way down the reading.
            await page.evaluate(() => globalThis.scrollBy({ top: 1500, behavior: "instant" }));
            expect(Math.round((await box(bar)).bottom)).toBe(812);

            const buttons = page.locator("[data-reading-editor] > div").first().locator("button");
            const tops = await buttons.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));
            expect(new Set(tops).size, "the toolbar wraps onto more than one row").toBeGreaterThan(1);
            await expectTouchTargets(page, "[data-reading-editor] > div:first-child");
            await expectNoPageOverflow(page);
        });

        test("a field focused under the pinned footer scrolls clear of it", async ({ page }) => {
            await open(page, reading, "light");

            const line = page.locator("#reading-editor [contenteditable] p").nth(6);
            const footerTop = (await box(footer(page))).top;
            // The paragraph just under the footer, then focused without the browser's own scroll (as WebKit leaves a tap).
            // Repeated until it holds: on WebKit the reading can still settle (and move) just after it has loaded.
            await expect(async () => {
                await line.evaluate((el, top) => globalThis.scrollBy({ top: el.getBoundingClientRect().top - top - 10, behavior: "instant" }), footerTop);
                expect((await box(line)).top, "the paragraph starts under the footer").toBeGreaterThan(footerTop);
            }).toPass({ timeout: 10_000 });
            await line.evaluate((el) => {
                globalThis.getSelection().collapse(el.firstChild ?? el, 0);
                el.closest("[contenteditable]").focus({ preventScroll: true });
            });
            await frames(page);
            await frames(page);

            const header = (await box(page.locator("[data-app-header]"))).bottom;
            const after = await box(line);
            expect(after.top, "the paragraph is above the footer").toBeLessThan(footerTop);
            expect(after.top, "and not under the header").toBeGreaterThanOrEqual(header - 1);
        });

        test("Add learning item opens full-screen with its Create button on the bottom bar", async ({ page }) => {
            await open(page, modulePage, "light");

            const dialog = page.locator("#learning-item-create-modal");
            const add = page.locator("[data-create-learning-item-button]").first();
            await add.scrollIntoViewIfNeeded();
            await openDialog(add, dialog);

            const panel = await box(dialog.locator("[data-dialog-panel]"));
            expect([panel.left, panel.top, panel.width, panel.height].map(Math.round)).toEqual([0, 0, 375, 812]);
            await expect(dialog.locator("[data-dialog-title]")).toHaveText("Add Learning item");
            await expectInViewport(page, "#learning-item-create-modal [data-learning-item-create-submit]");
            await expectTouchTargets(page, "#learning-item-create-modal");
            await expectNoPanelOverflow(dialog);
            await expectAccessible(page, "#learning-item-create-modal");

            // A type tile is a radio a tap reaches.
            await dialog.getByText("Video", { exact: true }).tap();
            await expect(dialog.getByRole("radio", { name: "Video" })).toBeChecked();

            await page.keyboard.press("Escape");
            await expect(dialog).toBeHidden();
        });

        test("in dark, a first item's Dependencies dialog shows its disabled Previous option readably", async ({ page }) => {
            await open(page, modulePage, "dark");

            // The unit's first item: there is no previous item, so that option is disabled. Nothing is saved.
            const item = page.locator("[data-learning-item-wrapper]").filter({ has: page.getByText(READING_TITLE, { exact: true }) });
            await item.locator("[data-row-actions-open]").tap();
            await page.locator("#content-actions-sheet [data-learning-item-dependencies]").tap();

            const dialog = page.locator("#learning-item-dependencies-modal");
            await expect(dialog).toBeVisible();
            await expect(dialog.locator("[data-dependencies-save]")).toBeEnabled();
            const options = dialog.locator("[data-dependencies-options]");
            await expect(async () => {
                if (!(await options.isVisible())) {
                    await dialog.locator("label:has([data-dependencies-enabled])").tap({ timeout: 2_000 });
                }
                await expect(options).toBeVisible({ timeout: 2_000 });
            }).toPass({ timeout: 15_000 });
            await expect(dialog.locator('[data-dependencies-mode][value="previous"]')).toBeDisabled();
            await expectAccessible(page, "#learning-item-dependencies-modal");

            await page.keyboard.press("Escape");
            await expect(dialog).toBeHidden();
        });

        test("an item's Dependencies dialog fits the phone with 44px controls", async ({ page }) => {
            await open(page, modulePage, "light");

            // The third item of "Computer Basics", so the custom list has earlier items. Nothing is saved (Escape at the end).
            const item = page.locator("[data-learning-item-wrapper]").filter({ has: page.getByText("How Computers Represent Data", { exact: true }) });
            await item.locator("[data-row-actions-open]").tap();
            await page.locator("#content-actions-sheet [data-learning-item-dependencies]").tap();

            const dialog = page.locator("#learning-item-dependencies-modal");
            await expect(dialog).toBeVisible();
            await expectTouchTargets(page, "#learning-item-dependencies-modal");

            // Custom dependency: its candidate rows, drawn by learning-item-dependencies.js.
            // The dialog fills in once its data has loaded (the previous-item hint), which would undo an earlier tap.
            await expect(dialog.locator("[data-dependencies-previous-hint]")).not.toBeEmpty();
            const custom = dialog.locator('label:has([data-dependencies-mode][value="custom"])');
            await expect(async () => {
                if (!(await custom.isVisible())) {
                    await dialog.locator("label:has([data-dependencies-enabled])").tap({ timeout: 2_000 });
                }
                await expect(custom).toBeVisible({ timeout: 2_000 });
            }).toPass({ timeout: 15_000 });
            await custom.tap();
            const candidates = dialog.locator("[data-dependencies-candidates] label");
            await expect(candidates.first()).toBeVisible();
            expect(await candidates.count()).toBeGreaterThanOrEqual(2);
            await expectTouchTargets(page, "#learning-item-dependencies-modal");
            // An older modal (modals/index.js), not a components/dialog: its panel is the first child.
            const panel = dialog.locator("> div");
            expect(await panel.evaluate((el) => el.scrollWidth <= el.clientWidth && el.getBoundingClientRect().right <= globalThis.innerWidth)).toBe(true);
            await expectAccessible(page, "#learning-item-dependencies-modal");
            await page.keyboard.press("Escape");
            await expect(dialog).toBeHidden();
        });
    });

    test.describe("320 item editor", () => {
        test.use({ viewport: { width: 320, height: 640 }, hasTouch: true, isMobile: true });

        test("a 40-character item title does not overflow the editor (Review Focus 4)", async ({ page }) => {
            restoreTitle();
            // The editor's address is looked up by the seed title, so before the title changes.
            await open(page, reading, "light");
            runSql(`UPDATE learning_items SET title = '${LONG_TITLE}' WHERE learning_unit_id IN ${unitIds} AND title = '${READING_TITLE}'`);
            try {
                await page.reload();
                await expect(page.locator(reading.scope)).toBeVisible();

                const title = page.locator("[data-item-header-title]");
                await expect(title).toHaveText(LONG_TITLE);
                await expectNoPageOverflow(page);
                const editor = await box(page.locator("[data-learning-item-editor]"));
                expect((await box(title)).right).toBeLessThanOrEqual(editor.right);
                const bar = page.locator("[data-learning-item-editor] .sticky-bar-top");
                expect(await bar.evaluate((el) => el.scrollWidth <= el.clientWidth), "the top bar holds the trail").toBe(true);
                await expect(bar.locator('[aria-current="page"]')).toHaveText(LONG_TITLE);
                await expectTouchTargets(page, "[data-learning-item-editor]");
                await expectInViewport(page, "[data-learning-item-editor] footer [data-publish]");
            } finally {
                restoreTitle();
            }
        });
    });

    test.describe("1366 item editor", () => {
        test.use({ viewport: { width: 1366, height: 768 } });

        test("the footer is the desktop one: its buttons keep their width", async ({ page }, testInfo) => {
            test.skip(testInfo.project.name === "webkit", "WebKit runs the phone only");
            await open(page, reading, "light");

            const draft = await box(footer(page).locator("[data-save-draft]"));
            expect(draft.width, "Save as Draft is not stretched").toBeLessThan(200);
            await expect(page.locator("[data-learning-item-editor] [data-save-message]")).toBeVisible();
        });
    });
});

// The Course Workspace's instructor tabs: Overview cards sized by their container, the announcement composer and
// in-place editor wrapping with 44px buttons, discussion moderation controls shown and 44px on touch, and a
// resource's ⋯ menu as a bottom sheet below md (shared/row-actions.js moves its entries in).
test.describe("instructor course workspace", () => {
    const overview = SCREENS.find((s) => s.name === "instructor-course-overview");
    const announcements = SCREENS.find((s) => s.name === "instructor-announcements");
    const resources = SCREENS.find((s) => s.name === "instructor-resources");
    const discussions = SCREENS.find((s) => s.name === "instructor-discussions");
    const box = (locator) => locator.evaluate((el) => el.getBoundingClientRect().toJSON());
    const tops = (locator) => locator.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));

    /**
     * Each Overview card's padding follows its column (the query container): 24px once the column is 20rem wide,
     * else 16px. Returns the widths and paddings seen, so a size can also pin the expected value.
     */
    async function expectCardPadding(page) {
        const cards = await page.locator("[data-overview-page] > div:nth-child(3) > div > div").evaluateAll((els) => els
            .filter((el) => el.classList.contains("p-4"))
            .map((el) => ({ column: el.parentElement.getBoundingClientRect().width, padding: globalThis.getComputedStyle(el).paddingLeft })));
        expect(cards.length, "the Overview's cards").toBeGreaterThanOrEqual(4);
        for (const card of cards) {
            expect(card.padding, `a card in a ${Math.round(card.column)}px column`).toBe(card.column >= 320 ? "24px" : "16px");
        }

        return cards;
    }

    /** The seeded thread's own page, opened from its row (the thread is made fresh by discussionsPath). */
    async function openThread(page) {
        await open(page, discussions, "light");
        const row = page.locator("[data-thread-open]").filter({ hasText: THREAD_TITLE });
        await expect(async () => {
            await row.click({ timeout: 2_000 });
            await expect(page.locator("[data-thread-body] [data-reply]")).toBeVisible({ timeout: 3_000 });
        }).toPass({ timeout: 30_000 });
    }

    test.describe("375", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("the Overview's metric cards sit two to a row", async ({ page }) => {
            await open(page, overview, "light");

            const cards = page.locator("[data-overview-page] > div:nth-child(2) [class*='rounded-2xl']");
            const [first, second] = await tops(cards);
            expect(first, "the first two metric cards share a row").toBe(second);
            await expectNoPageOverflow(page);
        });

        test("the Overview cards take their padding from the column they sit in", async ({ page }) => {
            await open(page, overview, "light");
            await expectCardPadding(page);
        });

        test("the announcement composer and the in-place editor wrap with 44px buttons", async ({ page }) => {
            await open(page, announcements, "light");

            await page.locator("[data-composer-toggle]").tap();
            const composer = page.locator("[data-announcement-composer]");
            await expect(composer).toBeVisible();
            await expectTouchTargets(page, "[data-announcement-composer]");
            await expectNoPageOverflow(page);
            await expectAccessible(page, "[data-announcement-composer]");
            await composer.locator("[data-composer-dismiss]").tap();

            // The card's ⋯ is always shown on a touch screen; its entries and the editor it opens are 44px too.
            const card = page.locator("[data-announcement-card]").filter({ has: page.locator("[data-more-options-toggle]") }).first();
            await card.locator("[data-more-options-toggle]").tap();
            await expect(card.locator("[data-more-options-panel]")).toBeVisible();
            await expectTouchTargets(page, `[data-announcement-id="${await card.getAttribute("data-announcement-id")}"] [data-more-options-panel]`);
            await card.locator("[data-edit-announcement]").tap();
            await expect(card.locator("[data-card-editor]")).toBeVisible();
            await expectTouchTargets(page, `[data-announcement-id="${await card.getAttribute("data-announcement-id")}"] [data-card-editor]`);
            await expectNoPageOverflow(page);
            await card.locator("[data-card-editor-cancel]").tap();
            await expect(card.locator("[data-card-editor]")).toBeHidden();
        });

        test("a discussion's moderation controls show without hover and are 44px", async ({ page }) => {
            await openThread(page);

            const post = page.locator("[data-thread-body] > article");
            const menu = post.locator("[data-more-options-toggle]").locator("xpath=../..");
            expect(await menu.evaluate((el) => globalThis.getComputedStyle(el).opacity)).toBe("1");
            await expectTouchTargets(page, "[data-thread-body]");

            await post.locator("[data-more-options-toggle]").tap();
            await expect(post.locator("[data-more-options-panel]")).toBeVisible();
            await expect(post.locator("[data-more-options-panel] button")).toHaveText([/Edit/, /Pin/, /Lock/, /Delete/]);
            await expectTouchTargets(page, "[data-thread-body] > article [data-more-options-panel]");
            await expectInViewport(page, "[data-thread-body] > article [data-more-options-panel]");
            await expectNoPageOverflow(page);
            await expectAccessible(page, "[data-thread-body]");

            // The reply's Mark as answer sits behind its own ⋯.
            const reply = page.locator("[data-thread-body] [data-reply]").first();
            await reply.locator("[data-more-options-toggle]").tap();
            await expect(reply.locator("[data-mark-answer]")).toBeVisible();
            await expectTouchTargets(page, "[data-thread-body] [data-reply] [data-more-options-panel]");
        });

        test("a resource's ⋯ opens its menu as a bottom sheet", async ({ page }) => {
            await open(page, resources, "light");

            const row = page.locator("[data-resource-row]").first();
            const title = (await row.locator(".truncate").first().innerText()).trim();
            await expect(row.locator("[data-resource-menu-toggle]")).toBeHidden();
            const opener = row.locator("[data-row-actions-open]");
            const sheet = page.locator("#resource-actions-sheet");
            await openDialog(opener, sheet);

            await expect(sheet.locator("[data-dialog-title]")).toHaveText(title);
            const slot = sheet.locator("[data-row-actions-slot]");
            await expect(slot.locator("a, button")).toHaveText([/View/, /Download/, /Share link/]);
            expect(Math.round((await box(sheet.locator("[data-dialog-panel]"))).bottom)).toBe(812);
            await expectTouchTargets(page, "#resource-actions-sheet");
            await expectNoPanelOverflow(sheet);
            await expectAccessible(page, "#resource-actions-sheet");

            await page.keyboard.press("Escape");
            await expect(sheet).toBeHidden();
            await expect(opener).toBeFocused();
            // The entries went back to the row's menu, hooks and all.
            await expect(row.locator("[data-resource-menu-panel] [data-resource-share]")).toHaveCount(1);
        });
    });

    test.describe("768", () => {
        test.use({ viewport: { width: 768, height: 1024 }, hasTouch: true, isMobile: true });
        test.skip(({ browserName }) => browserName === "webkit", "WebKit runs the 375px phone only");

        test("the Overview cards get the full 24px padding in a tablet's column", async ({ page }) => {
            await open(page, overview, "light");
            const cards = await expectCardPadding(page);
            expect(cards.every((card) => card.padding === "24px")).toBe(true);
        });
    });
    test.describe("1366", () => {
        test.use({ viewport: { width: 1366, height: 768 } });
        test.skip(({ browserName }) => browserName === "webkit", "WebKit runs the 375px phone only");

        test("hover-only controls stay hidden until hover and the resource menu is the dropdown", async ({ page }) => {
            await openThread(page);
            const post = page.locator("[data-thread-body] > article");
            const menu = post.locator("[data-more-options-toggle]").locator("xpath=../..");
            expect(await menu.evaluate((el) => globalThis.getComputedStyle(el).position)).toBe("absolute");
            expect(await menu.evaluate((el) => globalThis.getComputedStyle(el).opacity)).toBe("0");

            await page.goto(await resourcesUnitPath(page));
            const row = page.locator("[data-resource-row]").first();
            await expect(row.locator("[data-row-actions-open]")).toBeHidden();
            await row.locator("[data-resource-menu-toggle]").click();
            await expect(row.locator("[data-resource-menu-panel]")).toBeVisible();
            await expect(page.locator("#resource-actions-sheet")).toBeHidden();
        });

        // The container rule is guarded below lg: unguarded it came after lg:grid-cols-* in the built CSS and won.
        for (const screen of [overview, SCREENS.find((s) => s.name === "student-course-overview")]) {
            test(`${screen.name}: every metric card sits on one row`, async ({ page }) => {
                await open(page, screen, "light");
                await expect(page.locator("[data-overview-page]")).toBeVisible();
                const cards = page.locator("[data-overview-page] > div:nth-child(2) [class*='rounded-2xl']");
                expect(await cards.count()).toBeGreaterThanOrEqual(3);
                expect(new Set(await tops(cards)).size, "one row").toBe(1);
            });
        }

        test("in dark, a row title still turns brand on hover", async ({ page }) => {
            await open(page, overview, "dark");
            const row = page.locator("[data-overview-page] [data-overview-open]").first();
            const title = row.locator(".truncate").first();
            const brand = await page.locator("[data-overview-page] .text-brand").first().evaluate((el) => globalThis.getComputedStyle(el).color);
            const before = await title.evaluate((el) => globalThis.getComputedStyle(el).color);
            expect(before, "the title starts grey, not brand").not.toBe(brand);
            await row.hover();
            await expect.poll(() => title.evaluate((el) => globalThis.getComputedStyle(el).color)).toBe(brand);
        });

        test("the composer toolbar sits on one line", async ({ page }) => {
            await open(page, announcements, "light");
            await page.locator("[data-composer-toggle]").click();
            const buttons = page.locator("[data-composer-toolbar] button");
            expect(new Set(await tops(buttons)).size).toBe(1);
        });
    });
});

// Carry-over: below the lock (narrower than 1280 or shorter than 760) the window scrolls, not the page card, so a
// bar that pins to the window must pin below the sticky header, never under it. A bar whose scroll container is an
// element (a card that scrolls) is that element's business. Checked on every page with a lg:overflow-y-auto page card
// (and the course tabs of this task) at a 1100 laptop and a short 1366×657 desktop, half way down the page.
test.describe("sticky bars below the lock", () => {
    const PAGES = [
        { name: "instructor courses", as: "instructor", path: "/instructor/courses", scope: 'main:has(ul[aria-label="Your courses"])' },
        { name: "instructor dashboard", as: "instructor", path: "/instructor/dashboard", scope: "main:has([data-phone-tabs])" },
        // Server-rendered (no script loads it): scoped to the week grid so an empty or wrong page cannot pass.
        { name: "instructor schedule", as: "instructor", path: "/instructor/schedule", scope: "main:has([data-scroll-x])" },
        { name: "instructor calendar", as: "instructor", path: "/instructor/calendar", scope: "main:has([data-calendar-cell])" },
        // The learning item panel's Contents column (Task 7's fix): a student on an item, and an instructor's item editor.
        { name: "student module item", as: "student", path: moduleItemPath, scope: "main:has([data-contents])" },
        { name: "instructor item editor", as: "instructor", path: (page) => itemEditorPath(page, READING_TITLE), scope: "main:has([data-learning-item-editor] #reading-editor [contenteditable])" },
        ...["instructor-course-overview", "instructor-announcements", "instructor-resources", "instructor-discussions"].map((name) => SCREENS.find((s) => s.name === name)),
        // Phase 6: the chairperson's and the dean's lg:overflow-y-auto page cards.
        ...["chairperson-courses", "chairperson-monitor-program", "dean-courses"].map((name) => SCREENS.find((s) => s.name === name)),
    ];

    for (const size of [{ w: 1100, h: 768 }, { w: 1366, h: 657 }]) {
        test.describe(`${size.w}x${size.h}`, () => {
            test.use({ viewport: { width: size.w, height: size.h } });
            test.skip(({ browserName }) => browserName === "webkit", "WebKit runs the 375px phone only");

            for (const screen of PAGES) {
                test(`${screen.name}: no window-pinned bar sits under the header`, async ({ page }) => {
                    await open(page, screen, "light");
                    await page.evaluate(() => globalThis.scrollTo({ top: globalThis.document.documentElement.scrollHeight / 2, behavior: "instant" }));

                    const offenders = await page.evaluate(() => {
                        const doc = globalThis.document;
                        const header = doc.querySelector("[data-app-header]");
                        const headerHeight = header.getBoundingClientRect().height;
                        // Its nearest scroll container is the window when no ancestor clips with a scrollbar.
                        const pinsToWindow = (el) => {
                            for (let node = el.parentElement; node && node !== doc.body; node = node.parentElement) {
                                const { overflowX, overflowY } = globalThis.getComputedStyle(node);
                                if ([overflowX, overflowY].some((o) => o === "auto" || o === "scroll" || o === "hidden")) {
                                    return false;
                                }
                            }
                            return true;
                        };

                        return [...doc.querySelectorAll("body *")]
                            .filter((el) => !header.contains(el) && el.checkVisibility())
                            .filter((el) => globalThis.getComputedStyle(el).position === "sticky" && pinsToWindow(el))
                            .filter((el) => {
                                const top = parseFloat(globalThis.getComputedStyle(el).top);
                                return !Number.isNaN(top) && top < headerHeight - 1;
                            })
                            .map((el) => `top ${globalThis.getComputedStyle(el).top} under a ${Math.round(headerHeight)}px header: ${el.outerHTML.slice(0, 160)}`);
                    });

                    expect(offenders).toEqual([]);
                });
            }
        });
    }
});

// The student dashboard's phone tabs (components/phone-tabs): Today, Courses, Updates below lg; the whole grid from lg up.
test.describe("student", () => {
    const dashboard = SCREENS.find((s) => s.name === "student-dashboard");
    // A card by its heading (key-metrics also has a "Pending Tasks" label, in smaller type).
    const card = (page, title) => page.locator("main span.text-xl").filter({ hasText: new RegExp(`^\\s*${title}\\s*$`) });

    test.describe("375 phone", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("only Today shows, and Courses switches to the course list", async ({ page }) => {
            await open(page, dashboard, "light");

            const tabs = page.getByRole("tablist", { name: "Dashboard sections" });
            await expect(tabs.getByRole("tab")).toHaveText(["Today", "Courses", "Updates"].map((t) => new RegExp(`^\\s*${t}`)));
            await expect(card(page, "Pending Tasks")).toBeVisible();
            await expect(card(page, "Reminders")).toBeVisible();
            await expect(card(page, "My Courses")).toBeHidden();
            await expect(card(page, "Announcements")).toBeHidden();

            // A tap that lands before the script has attached does nothing, so it is repeated until it takes.
            const courses = tabs.getByRole("tab", { name: /^Courses/ });
            await expect(async () => {
                await courses.tap({ timeout: 2_000 });
                await expect(courses).toHaveAttribute("aria-selected", "true", { timeout: 2_000 });
            }).toPass({ timeout: 20_000 });

            await expect(card(page, "My Courses")).toBeVisible();
            await expect(card(page, "Quick Access")).toBeVisible();
            await expect(card(page, "Pending Tasks")).toBeHidden();
        });

        test("the task list shows at most five rows, with View all past that", async ({ page }) => {
            await open(page, dashboard, "light");

            const rows = page.locator("[data-task-row]");
            const total = await rows.count();
            // The seed decides how many tasks there are; with more than five, View all leads to the rest.
            await expect(rows.filter({ visible: true })).toHaveCount(Math.min(total, 5));
            const viewAll = page.locator("main [data-view-all]").filter({ hasText: "View all" }).first();
            if (total > 5) {
                await expect(viewAll).toBeVisible();
                await expect(viewAll).toHaveAttribute("href", /\/student\/calendar$/);
            }
        });
    });

    test.describe("1366 laptop", () => {
        test.use({ viewport: { width: 1366, height: 768 } });

        test("no tabs, every widget shows", async ({ page }) => {
            await open(page, dashboard, "light");

            await expect(page.locator("[data-phone-tabs]")).toBeHidden();
            await expect(page.locator("main").getByText("Courses Enrolled", { exact: true })).toBeVisible();
            for (const title of ["Pending Tasks", "Reminders", "My Courses", "Quick Access", "Announcements"]) {
                await expect(card(page, title), title).toBeVisible();
            }
            for (const link of await page.locator("main [data-view-all]").all()) {
                await expect(link).toBeHidden();
            }
        });
    });

    test.describe("parent 375 phone", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("Courses tab shows the cards and the page does not overflow", async ({ page }) => {
            await open(page, SCREENS.find((s) => s.name === "parent-dashboard"), "light");

            const tabs = page.getByRole("tablist", { name: "Dashboard sections" });
            await expect(tabs.getByRole("tab")).toHaveText(["Attention", "Courses", "Deadlines"].map((t) => new RegExp(`^\\s*${t}`)));
            await expect(page.locator("[data-parent-course]").first()).toBeHidden();

            const courses = tabs.getByRole("tab", { name: /^Courses/ });
            await expect(async () => {
                await courses.tap({ timeout: 2_000 });
                await expect(courses).toHaveAttribute("aria-selected", "true", { timeout: 2_000 });
            }).toPass({ timeout: 20_000 });

            await expect(page.locator("[data-parent-course]").first()).toBeVisible();
            await expect(page.locator("[data-parent-deadlines]")).toBeHidden();
            await expectNoPageOverflow(page);
        });
    });

    // The Course Workspace frame (pages/courses/view.php): the feature tab strip scrolls and keeps its tab in view.
    test.describe("375 course workspace", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });
        const overview = SCREENS.find((s) => s.name === "student-course-overview");
        const box = (locator) => locator.evaluate((el) => el.getBoundingClientRect().toJSON());

        test("the tab strip scrolls and the selected tab stays in view", async ({ page }) => {
            await open(page, SCREENS.find((s) => s.name === "student-course-announcements"), "light");

            const strip = page.getByRole("tablist", { name: "Course sections" });
            expect(await strip.evaluate((el) => el.scrollWidth > el.clientWidth)).toBe(true);

            // Clicked from script, not tapped: a tap would scroll the tab into view itself and prove nothing.
            const last = strip.getByRole("tab").last();
            await strip.evaluate((el) => { el.scrollLeft = 0; });
            expect((await box(last)).right).toBeGreaterThan(375);
            await expect(async () => {
                await last.evaluate((el) => el.click());
                await expect(last).toHaveAttribute("aria-selected", "true", { timeout: 2_000 });
            }).toPass({ timeout: 20_000 });

            await expect.poll(async () => {
                const r = await box(last);

                return r.left >= 0 && r.right <= 376;
            }).toBe(true);
        });

        test("the banner folds on scroll without covering the tab strip", async ({ page }) => {
            await open(page, overview, "light");

            const sticky = page.locator("[data-course-sticky]");
            // A scroll before the page script has attached its listener does nothing (a slow runner), so scroll
            // up and down again until the banner folds.
            await expect(async () => {
                await page.evaluate(() => globalThis.scrollTo(0, 0));
                await page.evaluate(() => globalThis.scrollTo(0, 600));
                await expect(sticky).toHaveAttribute("data-collapsed", "true", { timeout: 2_000 });
            }).toPass({ timeout: 20_000 });

            const strip = page.getByRole("tablist", { name: "Course sections" });
            const header = await box(page.locator("[data-app-header]").first());
            const banner = await box(sticky.locator(":scope > div").first());
            const tabs = await box(strip);
            expect(tabs.top).toBeGreaterThanOrEqual(header.bottom - 1);
            expect(tabs.top).toBeGreaterThanOrEqual(banner.bottom - 1);
            expect(tabs.bottom).toBeLessThanOrEqual(812);
        });
    });

    // Messages below md (pages/messages/phone-nav.js): the thread is pushed over the list with a history entry.
    test.describe("375 messages", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });
        const messages = SCREENS.find((s) => s.name === "student-messages");
        const app = (page) => page.locator("#messages-app");

        /** Taps the first conversation until its thread has loaded (a tap before the script attaches does nothing). */
        async function openFirst(page) {
            const row = page.locator("[data-role=rows] button[data-id]").first();
            await expect(async () => {
                if ((await app(page).getAttribute("data-view")) !== "thread") {
                    await row.tap({ timeout: 2_000 });
                }
                await expect(app(page)).toHaveAttribute("data-view", "thread", { timeout: 2_000 });
            }).toPass({ timeout: 20_000 });
            await expect(page.locator("[data-state=thread-loading]")).toBeHidden();
        }

        test("a conversation pushes the thread over an inert list, and the browser's Back returns", async ({ page }) => {
            await open(page, messages, "light");
            const list = page.locator("[data-pane=list]");
            const pane = page.locator("[data-pane=thread]");
            const address = page.url();

            const id = await page.locator("[data-role=rows] button[data-id]").first().getAttribute("data-id");
            await openFirst(page);
            await expect(pane).toBeVisible();
            // The tapped row is inert now, so focus moves into the thread, not to <body>.
            await expect(page.locator("[data-role=back]")).toBeFocused();
            await expect(list).toHaveAttribute("inert", "");
            await expect(list).toHaveAttribute("aria-hidden", "true");
            await expect(list).toBeHidden();

            await page.goBack();
            await expect(app(page)).toHaveAttribute("data-view", "list");
            expect(page.url()).toBe(address);
            await expect(list).toBeVisible();
            await expect(list).not.toHaveAttribute("inert", "");
            await expect(pane).toBeHidden();
            await expect(pane).toHaveAttribute("inert", "");
            await expect(page.locator(`[data-role=rows] button[data-id="${id}"]`)).toBeFocused();
            await expectNoPageOverflow(page);
        });

        test("Back from a deep-linked thread lands on the list, not the page before", async ({ page }) => {
            await open(page, messages, "light");
            const id = await page.locator("[data-role=rows] button[data-id]").first().getAttribute("data-id");

            // The browser's Back.
            await page.goto("/student/dashboard");
            await page.goto(`/messages/${id}`);
            await expect(app(page)).toHaveAttribute("data-view", "thread");
            await expect(page.locator("[data-role=back]")).toBeFocused();
            await page.goBack();
            await expect(page).toHaveURL(/\/messages$/);
            await expect(app(page)).toHaveAttribute("data-view", "list");
            await expect(page.locator("[data-pane=list]")).toBeVisible();

            // A reload of the deep link still opens the thread.
            await page.goto(`/messages/${id}`);
            await page.reload();
            await expect(app(page)).toHaveAttribute("data-view", "thread");

            // The thread's own Back.
            const back = page.locator("[data-role=back]");
            await expect(async () => {
                await back.tap({ timeout: 2_000 });
                await expect(app(page)).toHaveAttribute("data-view", "list", { timeout: 2_000 });
            }).toPass({ timeout: 20_000 });
            await expect(page).toHaveURL(/\/messages$/);
            await expect(page.locator("[data-pane=list]")).toBeVisible();
        });

        test("the composer stays inside the window when focused, and the page itself does not scroll", async ({ page }) => {
            await open(page, messages, "light");
            await openFirst(page);

            await page.locator("[data-role=body]").focus();
            await expectInViewport(page, "[data-role=composer]");
            expect(await page.evaluate(() => globalThis.document.documentElement.scrollHeight - globalThis.innerHeight)).toBeLessThanOrEqual(1);
        });
    });
});

// The learning item view (modules/learning-item-view.php): below lg the module outline is a "Contents" dropdown
// above the item, so the item gets the width; from lg up it is the sidebar.
test.describe("student module item", () => {
    const item = SCREENS.find((s) => s.name === "student-module-item");
    const contents = (page) => page.locator("details[data-contents]");
    const column = (page) => page.locator("[data-learning-item-panel] + div");

    test.describe("375 phone", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("the item has the width, and Contents lists the units and loads the chosen item", async ({ page }) => {
            await open(page, item, "light");

            // The 320px outline used to stay beside the item, leaving it ~80px and hiding the breadcrumb.
            expect(await column(page).evaluate((el) => el.getBoundingClientRect().width)).toBeGreaterThan(300);
            const trail = page.getByRole("navigation", { name: "Breadcrumb" });
            await expect(trail.locator('[aria-current="page"]')).toBeVisible();
            expect(await trail.evaluate((el) => el.getBoundingClientRect().width)).toBeGreaterThan(250);

            const summary = contents(page).locator("summary");
            await expect(summary).toContainText("Contents");
            const panel = page.locator("[data-contents-panel]");
            await expect(panel).toBeHidden();

            await summary.tap();
            await expect(contents(page)).toHaveAttribute("open", "");
            await expect(panel).toBeVisible();
            expect(await panel.locator("[data-learning-unit]").count()).toBeGreaterThan(0);
            await expectNoPageOverflow(page);

            // Another item the student can open (not the current one, not locked).
            const current = new URL(page.url()).searchParams.get("item");
            const other = panel.locator(`[data-panel-item]:not([data-locked]):not([data-panel-item="${current}"])`).first();
            await expect(other).toBeVisible();
            const id = await other.getAttribute("data-panel-item");
            const title = (await other.locator("[data-item-title]").innerText()).trim();

            await other.tap();
            await expect(page).toHaveURL(new RegExp(`[?&]item=${id}(&|$)`));
            await expect(page.locator("[data-item-header-title]")).toHaveText(title);
            await expect(contents(page)).not.toHaveAttribute("open", "");
            await expect(page.locator("[data-contents-panel]")).toBeHidden();
        });

        test("Escape closes Contents", async ({ page }) => {
            await open(page, item, "light");

            await contents(page).locator("summary").tap();
            await expect(page.locator("[data-contents-panel]")).toBeVisible();
            await page.keyboard.press("Escape");
            await expect(page.locator("[data-contents-panel]")).toBeHidden();
        });

        test("Previous and Continue stay at the bottom of the window", async ({ page }) => {
            await open(page, item, "light");

            const footer = page.locator("[data-learning-item-footer]");
            await expect(footer).toBeVisible();
            const box = await footer.evaluate((el) => el.getBoundingClientRect().toJSON());
            // Pinned to the bottom edge (or resting above it when the item is shorter than the window).
            expect(box.bottom).toBeLessThanOrEqual(812 + 1);
            expect(await footer.evaluate((el) => globalThis.getComputedStyle(el).position)).toBe("sticky");
        });
    });

    // Review Focus 5: a reading with one very long unbroken word (and a code-like token) must not widen the page.
    for (const width of [320, 375]) {
        test.describe(`${width} reading with unbroken text`, () => {
            test.use({ viewport: { width, height: 812 }, hasTouch: true, isMobile: true });

            test("only the text wraps; the page does not widen", async ({ page }) => {
                const reading = SCREENS.find((s) => s.name === "student-item-reading");
                const where = `WHERE title = '${READING_TITLE}' AND item_type = 'reading'`;
                const blocks = [
                    { type: "paragraph", content: [{ text: "Unbroken".repeat(80) }] },
                    { type: "paragraph", content: [{ text: "const_value=object.method(argument_one,argument_two);".repeat(12) }] },
                ];
                const content = JSON.stringify({ blocks });

                runSql("DROP TABLE IF EXISTS t6_reading_backup");
                runSql(`CREATE TABLE t6_reading_backup AS SELECT id, content FROM learning_items ${where}`);
                try {
                    runSql(`UPDATE learning_items SET content = '${content}' ${where}`);
                    await open(page, reading, "light");

                    await expect(page.locator(".reading-content")).toContainText("UnbrokenUnbroken");
                    await expectNoPageOverflow(page);
                    // The text wrapped inside the column instead of spilling out of it.
                    expect(await page.locator(".reading-content").evaluate((el) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
                } finally {
                    runSql("UPDATE learning_items li JOIN t6_reading_backup b ON b.id = li.id SET li.content = b.content");
                    runSql("DROP TABLE IF EXISTS t6_reading_backup");
                }
            });
        });
    }

    test.describe("1366 laptop", () => {
        test.use({ viewport: { width: 1366, height: 768 } });

        test("the outline is the sidebar, with no Contents control", async ({ page }) => {
            await open(page, item, "light");

            await expect(contents(page).locator("summary")).toBeHidden();
            await expect(page.locator("[data-contents-panel]")).toBeVisible();
            await expect(page.locator("[data-contents-panel] [data-panel-item]").first()).toBeVisible();
            const aside = await page.locator("[data-learning-item-panel]").evaluate((el) => el.getBoundingClientRect().toJSON());
            expect(Math.round(aside.width)).toBe(320);
            expect((await column(page).evaluate((el) => el.getBoundingClientRect().toJSON())).left).toBeGreaterThanOrEqual(aside.right - 1);
        });
    });
});

// The courses grid follows its container width, not the viewport.
test.describe("student courses", () => {
    const courses = SCREENS.find((s) => s.name === "student-courses");
    /**
     * How many columns the courses grid lays out. Read from the grid, not by counting cards in the first row: the
     * seeded student has only two current courses, so a row of cards can never show three or four columns.
     */
    const gridColumns = (page) =>
        page.locator('ul[aria-label="Your courses"]').evaluate((ul) => globalThis.getComputedStyle(ul).gridTemplateColumns.split(" ").length);

    test.describe("375 phone", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("one column", async ({ page }) => {
            await open(page, courses, "light");

            expect(await gridColumns(page)).toBe(1);
        });

        test("a very long title wraps instead of widening the page at 320", async ({ page }) => {
            await open(page, courses, "light");
            const original = (await page.locator('ul[aria-label="Your courses"] a [id^="course-title-"]').first().innerText()).trim();
            const long = "Supercalifragilisticexpialidocious".repeat(4);

            runSql(`UPDATE courses SET course_title = '${long}' WHERE course_title = '${original.replaceAll("'", "''")}'`);
            try {
                await page.setViewportSize({ width: 320, height: 640 });
                await page.reload();
                await expect(page.getByText(long).first()).toBeVisible();
                await expectNoPageOverflow(page);
            } finally {
                runSql(`UPDATE courses SET course_title = '${original.replaceAll("'", "''")}' WHERE course_title = '${long}'`);
            }
        });
    });

    // The container is the viewport less CourseController's gutter (sm:px-8, xl:px-20, 2xl:px-40 each side) and
    // the panel's p-8: 768 -> 640px (@md, 2 columns); 1024 -> 896px (@3xl, 3 columns); 1366 -> 1142px and
    // 1600 -> 1216px (@5xl, 4 columns).
    for (const [width, height, columns] of [[768, 1024, 2], [1024, 768, 3], [1366, 768, 4], [1600, 900, 4]]) {
        test.describe(`${width} wide`, () => {
            test.use({ viewport: { width, height } });

            test(`${columns} columns`, async ({ page }) => {
                await open(page, courses, "light");

                expect(await gridColumns(page)).toBe(columns);
            });
        });
    }
});

// Quiz taking on a phone (assessments/quiz-answer-form.php). Data: IT101-A "Quiz 1: Variables and Data Types" (30 minutes,
// two attempts, an essay last). Its item waits for the two before it (TC-QUIZ-01 works through that); the lock is not what
// this is about, so it is lifted for the test and put back. Afterwards student5's unfinished attempts at it are deleted
// (an attempt left over from a failed run would otherwise be resumed and change the next one), as quiz-security.spec.js
// cleans up its own (the test's own data, not something the app offers).
test.describe("student quiz", () => {
    const QUIZ = "Quiz 1: Variables and Data Types";
    const box = (locator) => locator.evaluate((el) => el.getBoundingClientRect().toJSON());

    test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

    test("375 the focused last answer stays clear of the sticky submit bar", async ({ page }) => {
        runSql(`UPDATE learning_items SET prerequisite_mode = 'none' WHERE title = '${QUIZ}'`);
        try {
            await signInAs(page, accounts.student, "student");
            await openCourse(page, "student", "IT101", "A");
            await openTab(page, "Assessments");
            await page.locator("[data-assessment-card]").filter({ hasText: QUIZ }).first().click();

            // The assessment's own page first: the score panel sits under the quiz, not beside it.
            const take = page.locator("[data-take-quiz]");
            await expect(take).toBeVisible();
            await expectNoPageOverflow(page);
            await take.click();

            const form = page.locator("#quiz-answer-form");
            await expect(form).toBeVisible();
            await expect(page.locator("[data-quiz-timer] [data-quiz-timer-value]")).not.toHaveText("--:--");
            await expectNoPageOverflow(page);
            await expectTouchTargets(page, "#quiz-answer-form");
            await page.evaluate(() => globalThis.scrollTo(0, 0));
            await expectInViewport(page, "[data-submit-quiz]");

            // An open soft keyboard (interactive-widget=resizes-content) leaves the top ~500px. The worst case is the
            // focused field resting on the bottom edge of what is left; the browser then brings it into view, which
            // scrollIntoView "nearest" stands in for. It honours the field's scroll margin; without one, nothing moves
            // and the pinned bar covers the field.
            await page.setViewportSize({ width: 375, height: 500 });
            const last = form.locator("[data-quiz-text-input]").last();
            await last.focus();
            await last.evaluate((el) => globalThis.scrollBy(0, el.getBoundingClientRect().bottom - globalThis.innerHeight));
            await last.evaluate((el) => el.scrollIntoView({ block: "nearest" }));

            const field = await box(last);
            const bar = await box(form.locator("[data-submit-quiz]").locator("xpath=.."));
            expect(field.bottom <= bar.top || field.top >= bar.bottom, `the field (${Math.round(field.top)}-${Math.round(field.bottom)}) against the bar (${Math.round(bar.top)}-${Math.round(bar.bottom)})`).toBe(true);
            expect(bar.bottom, "the bar is pinned in the window").toBeLessThanOrEqual(501);
            await expectInViewport(page, "[data-submit-quiz]");
            await expectNoPageOverflow(page);

            // Down at the last question the top bar's timer has scrolled away; the pinned bar's copy shows the time
            // left, and it counts down.
            const copy = form.locator("[data-quiz-timer-copy] [data-quiz-timer-value]");
            await expectInViewport(page, "[data-quiz-timer-copy]");
            const shown = await copy.innerText();
            expect(shown).toMatch(/^\d+:\d{2}$/);
            await expect(copy).not.toHaveText(shown, { timeout: 3_000 });
        } finally {
            runSql(
                "DELETE s FROM assessment_submissions s JOIN assessments a ON a.id = s.assessment_id " +
                    "JOIN auth_identities ai ON ai.user_id = s.student_id AND ai.type = 'email_password' " +
                    `WHERE a.title = '${QUIZ}' AND ai.secret = '${accounts.student.email}' AND s.status = 'In Progress'`
            );
            runSql(`UPDATE learning_items SET prerequisite_mode = 'previous' WHERE title = '${QUIZ}'`);
        }
    });
});

// The calendar and the schedule on a phone (pages/calendar, pages/schedule).
test.describe("calendar and schedule", () => {
    const calendar = SCREENS.find((s) => s.name === "student-calendar");
    const schedule = SCREENS.find((s) => s.name === "student-schedule");
    const box = (locator) => locator.evaluate((el) => el.getBoundingClientRect().toJSON());

    test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

    test("375 calendar cells show dots, today opens the day sheet and a past day does nothing", async ({ page }) => {
        await open(page, calendar, "light");

        // The seed puts events in the current month: their cells show dots, never the title chips.
        const withEvents = page.locator("[data-calendar-cell]:has([data-calendar-dot])").first();
        await expect(withEvents.locator("[data-calendar-dots]")).toBeVisible();
        await expect(withEvents.locator("[data-calendar-chips]")).toBeHidden();

        const today = await page.locator("#calendar-app").getAttribute("data-today");
        const modal = page.locator("#calendar-modal");

        await page.locator(`[data-calendar-cell="${today}"]`).tap();
        await expect(modal).toBeVisible();
        await expect(modal.locator("[data-calendar-action]").first()).toBeVisible();
        await expectInViewport(page, "[data-calendar-modal-panel]");
        // A bottom sheet: full width, resting on the bottom edge.
        const panel = await box(modal.locator("[data-calendar-modal-panel]"));
        expect(Math.round(panel.width)).toBe(375);
        expect(Math.round(panel.bottom)).toBe(812);
        await expectTouchTargets(page, "#calendar-modal");

        // Escape closes the sheet and gives focus back to the tapped day.
        await expect(modal.locator("[data-calendar-modal-close]")).toBeFocused();
        await page.keyboard.press("Escape");
        await expect(modal).toBeHidden();
        await expect(page.locator(`[data-calendar-cell="${today}"]`)).toBeFocused();

        await expectNoPageOverflow(page);
    });

    // CalendarDemoSeeder dates its announcements and deadlines from today, so the days just before today carry events.
    test("375 a past day with events opens its Day view listing them, with nothing to add", async ({ page }) => {
        await open(page, calendar, "light");

        const past = page.locator("[data-calendar-cell][data-calendar-past]:has([data-calendar-dot])").last();
        await expect(past).toHaveCount(1);
        const date = await past.getAttribute("data-calendar-cell");
        const shown = await past.locator("[data-calendar-dot]").count();

        await past.tap();

        await expect(page.locator('[data-calendar-view="day"]')).toHaveClass(/bg-brand/);
        await expect(page.locator("[data-calendar-event]").first()).toBeVisible();
        expect(await page.locator("[data-calendar-event]").count()).toBeGreaterThanOrEqual(shown);
        await expect(page.locator("[data-calendar-add]")).toHaveCount(0);
        await expect(page.locator("#calendar-modal")).toBeHidden();
        const [y, , d] = date.split("-").map(Number);
        await expect(page.locator("[data-calendar-label]")).toContainText(`${d}, ${y}`);
    });

    test("375 schedule grid scrolls in its box with the hours pinned", async ({ page }) => {
        await open(page, schedule, "light");

        const scroller = page.locator("[data-scroll-x][data-sticky-first]");
        await expect(scroller).toBeVisible();

        // It opens on today's column (the week runs Monday to Sunday, so today is always in it).
        const todayColumn = page.locator('[data-scroll-x] [aria-current="date"]');
        await expect(todayColumn).toHaveCount(1);
        const column = await box(todayColumn);
        const frame = await box(scroller);
        expect(column.left >= frame.left + 79 && column.right <= frame.right + 1, "today's column is in view").toBe(true);

        await scroller.evaluate((el) => {
            el.scrollLeft = 0;
            el.dispatchEvent(new Event("scroll"));
        });
        await expect(scroller).toHaveAttribute("data-fade-end", "");

        const rail = scroller.locator(".grid [data-sticky-col]").last();
        const before = await box(rail);
        await scroller.evaluate((el) => {
            el.scrollLeft = 200;
            el.dispatchEvent(new Event("scroll"));
        });
        await expect(scroller).toHaveAttribute("data-fade-start", "");
        expect(await scroller.evaluate((el) => el.scrollLeft)).toBeGreaterThan(0);
        expect(Math.round((await box(rail)).left)).toBe(Math.round(before.left));

        await expectNoPageOverflow(page);
    });
});

// The student's course rows on a phone: the opened breakdown scrolls inside its own box, never the page.
test.describe("grades breakdown", () => {
    test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

    test("the breakdown grid scrolls inside its box at 375", async ({ page }) => {
        await open(page, SCREENS.find((s) => s.name === "student-grades"), "light");

        const toggle = page.locator("[data-grades-row] [data-disclosure]").first();
        const grid = page.locator("[data-grades-row] [data-scroll-x]").first();
        // A tap before the page script attaches does nothing, so it repeats until the breakdown has loaded.
        await expect(async () => {
            if (!(await grid.isVisible())) {
                await toggle.click({ timeout: 2_000 });
            }
            await expect(grid).toBeVisible({ timeout: 3_000 });
        }).toPass({ timeout: 20_000 });

        await expect(grid).toHaveAttribute("data-sticky-first", "");
        await expectNoPageOverflow(page);
    });
});

// The chairperson dashboard's phone tabs: Attention, Program, Updates below lg; strip, rail and main column from lg up.
test.describe("chairperson", () => {
    const dashboard = SCREENS.find((s) => s.name === "chairperson-dashboard");
    const TABS = {
        Attention: ["Needs Attention"],
        Program: ["Program snapshot", "Instructor workload"],
        Updates: ["Announcements", "Your Recent Actions"],
    };
    // A card by its heading (a span; the quick-access strip has an Announcements link too).
    const card = (page, title) => page.locator("main span").getByText(title, { exact: true });

    test.describe("375 phone", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("each tab shows its own cards and hides the others", async ({ page }) => {
            await open(page, dashboard, "light");

            const tabs = page.getByRole("tablist", { name: "Dashboard sections" });
            await expect(tabs.getByRole("tab")).toHaveText(Object.keys(TABS).map((t) => new RegExp(`^\\s*${t}`)));

            for (const name of Object.keys(TABS)) {
                const tab = tabs.getByRole("tab", { name: new RegExp(`^${name}`) });
                // A tap that lands before the script has attached does nothing, so it is repeated until it takes.
                await expect(async () => {
                    await tab.tap({ timeout: 2_000 });
                    await expect(tab).toHaveAttribute("aria-selected", "true", { timeout: 2_000 });
                }).toPass({ timeout: 20_000 });

                for (const [other, others] of Object.entries(TABS)) {
                    for (const title of others) {
                        await expect(card(page, title), `${title} on ${name}`)[other === name ? "toBeVisible" : "toBeHidden"]();
                    }
                }
                await expect(page.locator('main [data-phone-panel="program"]').first(), `quick access on ${name}`)[name === "Program" ? "toBeVisible" : "toBeHidden"]();
                await expectNoPageOverflow(page);
                // The sweep only sees the first tab, so the switched-to panels are checked here.
                await expectTouchTargets(page, "main [data-phone-panel]");
            }
        });
    });

    test.describe("1366 laptop", () => {
        test.use({ viewport: { width: 1366, height: 768 } });

        test("no tabs, every card shows and the page does not scroll", async ({ page }) => {
            await open(page, dashboard, "light");

            await expect(page.locator("[data-phone-tabs]")).toBeHidden();
            for (const title of Object.values(TABS).flat()) {
                await expect(card(page, title), title).toBeVisible();
            }
            expect(await page.evaluate(() => globalThis.document.documentElement.scrollHeight - globalThis.innerHeight)).toBeLessThanOrEqual(1);
        });
    });
});

// The dean dashboard's phone tabs: Attention, College, Updates below lg; strip, rail and main column from lg up.
test.describe("dean", () => {
    const dashboard = SCREENS.find((s) => s.name === "dean-dashboard");
    const TABS = {
        Attention: ["Needs Attention"],
        College: ["Your college", "Courses running"],
        Updates: ["Chairperson activity", "College announcements"],
    };
    const card = (page, title) => page.locator("main").getByText(title, { exact: true });

    test.describe("375 phone", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("each tab shows its own cards and hides the others", async ({ page }) => {
            await open(page, dashboard, "light");

            const tabs = page.getByRole("tablist", { name: "Dashboard sections" });
            await expect(tabs.getByRole("tab")).toHaveText(Object.keys(TABS).map((t) => new RegExp(`^\\s*${t}`)));

            for (const name of Object.keys(TABS)) {
                const tab = tabs.getByRole("tab", { name: new RegExp(`^${name}`) });
                // A tap that lands before the script has attached does nothing, so it is repeated until it takes.
                await expect(async () => {
                    await tab.tap({ timeout: 2_000 });
                    await expect(tab).toHaveAttribute("aria-selected", "true", { timeout: 2_000 });
                }).toPass({ timeout: 20_000 });

                for (const [other, others] of Object.entries(TABS)) {
                    for (const title of others) {
                        await expect(card(page, title), `${title} on ${name}`)[other === name ? "toBeVisible" : "toBeHidden"]();
                    }
                }
                await expect(page.locator('main [data-phone-panel="college"]').first(), `quick access on ${name}`)[name === "College" ? "toBeVisible" : "toBeHidden"]();
                await expectNoPageOverflow(page);
                // The sweep only sees the first tab, so the switched-to panels are checked here.
                await expectTouchTargets(page, "main [data-phone-panel]");
            }
        });
    });

    test.describe("1366 laptop", () => {
        test.use({ viewport: { width: 1366, height: 768 } });

        test("no tabs, every card shows and the page does not scroll", async ({ page }) => {
            await open(page, dashboard, "light");

            await expect(page.locator("[data-phone-tabs]")).toBeHidden();
            for (const title of Object.values(TABS).flat()) {
                await expect(card(page, title), title).toBeVisible();
            }
            expect(await page.evaluate(() => globalThis.document.documentElement.scrollHeight - globalThis.innerHeight)).toBeLessThanOrEqual(1);
        });
    });
});

// Global grades (the chairperson's Program table, the dean's College table): the table scrolls sideways inside its
// card with the Section column pinned, Nudge and Download sit in the section's action bar below lg, and the nudge
// dialog is a full-screen form on a phone.
test.describe("global grades", () => {
    const rows = SCREENS.filter((s) => s.name === "chairperson-grades" || s.name === "dean-grades");
    const scroller = (page) => page.locator("[data-scroll-x]:has(> [data-program-table])");

    for (const size of [{ w: 320, h: 640 }, { w: 375, h: 812 }, { w: 768, h: 1024 }]) {
        test.describe(`${size.w}`, () => {
            test.use({ viewport: { width: size.w, height: size.h }, hasTouch: true, isMobile: true });

            for (const screen of rows) {
                test(`${screen.name}: no page overflow, and the Section column stays on screen when the table scrolls`, async ({ page }) => {
                    await open(page, screen, "light");
                    await expectNoPageOverflow(page);

                    const box = scroller(page);
                    await box.evaluate((el) => el.scrollTo({ left: el.scrollWidth, behavior: "instant" }));
                    expect(await box.evaluate((el) => el.scrollLeft), "the table scrolls sideways").toBeGreaterThan(0);

                    const frame = await box.boundingBox();
                    for (const cell of [page.locator("[data-program-table] thead th").first(), page.locator("[data-program-table] tbody th").first()]) {
                        const at = await cell.boundingBox();
                        expect(at.x, "the pinned cell's left edge").toBeGreaterThanOrEqual(frame.x - 1);
                        expect(at.x + at.width, "the pinned cell's right edge").toBeLessThanOrEqual(frame.x + frame.width + 1);
                    }
                    // The pinned column leaves room for the columns scrolling past it.
                    expect((await page.locator("[data-program-table] tbody th").first().boundingBox()).width).toBeLessThan(frame.width * 0.7);
                    await expectNoPageOverflow(page);
                });
            }
        });
    }

    test.describe("375 phone", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        for (const screen of rows) {
            test(`${screen.name}: Nudge opens from the action bar; with no recipients Send stays disabled and 44px`, async ({ page }) => {
                await open(page, screen, "light");

                const toggle = page.locator("[data-program-actions] [data-action-bar-toggle]");
                const nudge = page.locator("[data-nudge]");
                const dialog = page.locator("#nudge-dialog");

                // A tap that lands before the script has attached does nothing, so it is repeated until it takes.
                await expect(async () => {
                    if ((await toggle.getAttribute("aria-expanded")) !== "true") {
                        await toggle.tap({ timeout: 2_000 });
                    }
                    await expect(toggle).toHaveAttribute("aria-expanded", "true", { timeout: 2_000 });
                }).toPass({ timeout: 20_000 });
                await expect(page.locator("[data-program-actions] [data-action-bar-panel] [data-nudge]")).toBeVisible();
                await expect(page.locator("[data-program-actions] [data-action-bar-panel] :is([data-download-program], [data-download-college])")).toBeVisible();
                await expectTouchTargets(page, "[data-program-actions]");

                await nudge.tap();
                await expect(dialog).toBeVisible();
                await expectTouchTargets(page, "#nudge-dialog");
                await expectNoPanelOverflow(dialog);
                await expectDialogFits(dialog);
                await expectAccessible(page, "#nudge-dialog");
                await page.keyboard.press("Escape");
                await expect(dialog).toBeHidden();

                // A section nobody but the sender teaches: nothing to send to.
                await page.locator("[data-program-actions]").evaluate((el) => { el.dataset.instructors = "[]"; });
                await nudge.dispatchEvent("click");
                await expect(dialog).toBeVisible();
                const send = dialog.locator("[data-nudge-send]");
                await expect(send).toBeDisabled();
                await expect(dialog.locator("[data-nudge-recipient]")).toHaveValue("");
                expect((await send.boundingBox()).height).toBeGreaterThanOrEqual(44);
                await expectNoPanelOverflow(dialog);
                await expectAccessible(page, "#nudge-dialog");
            });
        }
    });
});

// Monitor Program and Monitor College: the comparison tables scroll sideways inside their card with the first
// column (the course, the department or program) pinned, and the page itself never scrolls sideways.
test.describe("staff monitors", () => {
    const tables = [
        { screen: SCREENS.find((s) => s.name === "chairperson-monitor-program"), table: "[data-course-comparison]" },
        { screen: SCREENS.find((s) => s.name === "dean-monitor-college"), table: "[data-college-comparison]" },
    ];

    for (const size of [{ w: 320, h: 640 }, { w: 375, h: 812 }]) {
        test.describe(`${size.w}`, () => {
            test.use({ viewport: { width: size.w, height: size.h }, hasTouch: true, isMobile: true });

            for (const { screen, table } of tables) {
                test(`${screen.name}: the comparison scrolls in its card with the first column pinned`, async ({ page }) => {
                    await open(page, screen, "light");
                    await expectNoPageOverflow(page);

                    const box = page.locator(`[data-scroll-x][data-sticky-first]:has(${table})`);
                    await box.evaluate((el) => el.scrollTo({ left: el.scrollWidth, behavior: "instant" }));
                    expect(await box.evaluate((el) => el.scrollLeft), "the table scrolls sideways").toBeGreaterThan(0);

                    const frame = await box.boundingBox();
                    for (const cell of [page.locator(`${table} thead tr > :first-child`), page.locator(`${table} tbody tr > :first-child`).first()]) {
                        const at = await cell.boundingBox();
                        expect(at.x, "the pinned cell's left edge").toBeGreaterThanOrEqual(frame.x - 1);
                        expect(at.x + at.width, "the pinned cell's right edge").toBeLessThanOrEqual(frame.x + frame.width + 1);
                    }
                    await expectTouchTargets(page, `[data-scroll-x]:has(${table})`);
                    await expectNoPageOverflow(page);
                });
            }
        });
    }

    // expectTouchTargets skips inline links, so the links inside the monitors' tables and lists are measured here.
    test.describe("375 inline links", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        for (const { screen, query, links } of [
            { screen: tables[0].screen, query: "", links: "main :is(table, li) a[href]" },
            { screen: tables[1].screen, query: "?compare=program", links: "main :is(table, li) a[href]" },
        ]) {
            test(`${screen.name}${query}: every link in a table or list is 44px tall`, async ({ page }) => {
                await open(page, screen, "light");
                if (query) {
                    await page.goto(screen.path + query);
                    await expect(page.locator("[data-program-link]").first()).toBeVisible();
                }

                const found = page.locator(links);
                expect(await found.count(), "links to measure").toBeGreaterThan(2);
                const small = await found.evaluateAll((els) => els
                    .filter((el) => el.checkVisibility())
                    .map((el) => ({ h: el.getBoundingClientRect().height, html: el.outerHTML.slice(0, 120) }))
                    .filter(({ h }) => h < 44));
                expect(small).toEqual([]);
            });
        }
    });

    // The seed has no chairperson changes in the dean's college, so one is added (and removed) for the audit log.
    test.describe("375 audit log", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });
        const removeEntry = () => runSql("DELETE FROM audit_logs WHERE actor_label = 'RF6T5 Chair'");

        test("dean-monitor-college: each change is a phone row whose ⋯ sheet shows Acting as and When", async ({ page }) => {
            removeEntry();
            runSql(`INSERT INTO audit_logs (actor_label, acting_role, action, target_type, target_id, institution_id, new_values, created_at) SELECT 'RF6T5 Chair', 'chairperson', 'course.chairperson_action', 'course_offering', ${offeringIt101A}, 1, '{"method":"update"}', NOW()`);
            try {
                await open(page, SCREENS.find((s) => s.name === "dean-monitor-college"), "light");

                const audit = page.locator('[data-section="audit"]');
                const row = audit.locator("[data-table][data-phone-rows] [data-row]").filter({ hasText: "RF6T5 Chair" });
                await expect(row).toBeVisible();
                await expect(row.locator('[data-role="secondary"] a')).toHaveText(/IT101 A/);
                // An inline link the touch check skips: measured here.
                expect((await row.locator('[data-role="secondary"] a').boundingBox()).height).toBeGreaterThanOrEqual(44);
                await expect(row.locator("[data-row-actions-open]")).toHaveAttribute("aria-label", /^Details for .+, RF6T5 Chair, \d{4}-/);
                await expectTouchTargets(page, '[data-section="audit"]');

                const sheet = page.locator("#audit-log-actions");
                await openDialog(row.locator("[data-row-actions-open]"), sheet);
                await expect(sheet.locator("[data-row-actions-fields]")).toContainText("Acting as");
                await expect(sheet.locator("[data-row-actions-fields]")).toContainText("Chairperson");
                await expectAccessible(page, "#audit-log-actions");
                await page.keyboard.press("Escape");
                await expect(sheet).toBeHidden();
                await expectNoPageOverflow(page);
            } finally {
                removeEntry();
            }
        });
    });
});

// The seed has no override requests, follow-ups, college announcements, chairperson changes or ready and signed-off
// sections, so one of each is added under the RF6T6 marker (runSql returns no ids: found by marker, removed before
// and after) and the lists and the dialogs their rows open are checked at 375 and 834. Opening a dialog writes
// nothing; the sign-off's password step is reached by answering the sign-off POST with reauth_required.
// Removes every RF6T6 row (the two open override requests on IT201-A, the follow-up and its reply, the college
// announcement, the chairperson change, IT101-B's finalization and IT102-A's sign-off) and gives dean1 back the college.
const removeStaffRows = () => {
    runSql("DELETE FROM grade_override_requests WHERE reason LIKE 'RF6T6%'");
    runSql("DELETE FROM follow_ups WHERE title LIKE 'RF6T6%'");
    runSql("DELETE FROM college_announcements WHERE title LIKE 'RF6T6%'");
    runSql("DELETE FROM audit_logs WHERE actor_label = 'RF6T6 Chair'");
    // A finalization and a sign-off have no text of their own: the marker goes in their (unused while open) reopen reason.
    runSql("DELETE FROM grade_signoffs WHERE reopen_reason = 'RF6T6'");
    runSql("DELETE FROM grade_finalizations WHERE reopen_reason = 'RF6T6'");
};
const deanOneHasCollege = (active) => runSql(`UPDATE dean_assignments da JOIN auth_identities ai ON ai.user_id = da.dean_user_id AND ai.type = 'email_password' SET ${active ? "da.status = 'active', da.active_marker = 1, da.ended_at = NULL" : "da.status = 'ended', da.active_marker = NULL, da.ended_at = NOW()"} WHERE ai.secret = 'dean1@mentora.test'`);

// The touch check skips only what a screen reader alone can reach (a box clipped to 1px, Tailwind's sr-only): a
// visible small control still fails it, and so does one that is merely tiny (1px) but not clipped.
test.describe("touch check", () => {
    test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });
    const srOnly = "position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip-path:inset(50%);white-space:nowrap;border:0";

    test("a visible 30px control fails, a 44px link in an sr-only box is skipped", async ({ page }) => {
        await page.setContent(`<main><div style="${srOnly}"><a href="#a" style="display:inline-flex;min-height:44px;min-width:44px;margin-left:600px">Sort</a></div></main>`);
        await expectTouchTargets(page, "main");

        await page.setContent(`<main><div style="${srOnly}"><a href="#a" style="display:inline-flex;min-height:44px">Sort</a></div><button style="width:30px;height:30px">x</button></main>`);
        await expect(expectTouchTargets(page, "main")).rejects.toThrow(/30×30/);

        // 1px and overflow hidden but not clipped: not the sr-only pattern, still measured.
        await page.setContent(`<main><div style="position:absolute;width:1px;height:1px;overflow:hidden"><button style="width:30px;height:30px">x</button></div></main>`);
        await expect(expectTouchTargets(page, "main")).rejects.toThrow(/30×30/);
    });
});

// Any run of this file first clears what a killed run of "staff lists" may have left, so it cannot reach other specs
// (dean.spec.js and global-grades.spec.js finalize IT101-B and run the override chain on IT201-A).
test.beforeAll(() => {
    removeStaffRows();
    deanOneHasCollege(true);
});

test.describe("staff lists", () => {
    // These checks are for chromium's touch emulation; the WebKit project was not run on them.
    test.skip(({ browserName }) => browserName === "webkit", "chromium only");

    const user = (email) => `(SELECT user_id FROM auth_identities WHERE type = 'email_password' AND secret = '${email}')`;
    const offering = (code, section) => `(SELECT co.id FROM course_offerings co JOIN courses c ON c.id = co.course_id WHERE c.course_code = '${code}' AND co.section_code = '${section}')`;
    const dean = user("dean2@mentora.test");
    const chair = user("chairperson1@mentora.test");
    const it201 = offering("IT201", "A");
    const removeRows = removeStaffRows;

    const seedRows = () => {
        const request = (student, status, reason) => runSql(`INSERT INTO grade_override_requests (offering_id, student_id, requested_percentage, calculated_percentage, reason, requested_by, requester_role, status, open_marker, endorsed_by, endorsed_at, created_at, updated_at)
            VALUES (${it201}, (SELECT ${student}(student_user_id) FROM enrollments WHERE offering_id = ${it201}), 75, 70, '${reason}', ${user("instructor2@mentora.test")}, 'instructor', '${status}', 1, ${status === "awaiting_dean" ? chair : "NULL"}, ${status === "awaiting_dean" ? "NOW()" : "NULL"}, NOW(), NOW())`);
        request("MIN", "awaiting_dean", "RF6T6 Late project excused: medical certificate on file.");
        request("MAX", "awaiting_chair", "RF6T6 Counted the wrong rubric row.");
        runSql(`INSERT INTO follow_ups (institution_id, sender_id, sender_role, recipient_id, recipient_role, offering_id, subject_type, student_id, reason, title, message, status, open_marker, created_at, updated_at)
            VALUES (1, ${dean}, 'dean', ${chair}, 'chairperson', ${offering("IT101", "A")}, 'offering', 0, 'other', 'RF6T6 Check the IT101 grades', 'Two students have no final grade yet.', 'open', 1, NOW(), NOW())`);
        runSql(`INSERT INTO follow_up_replies (follow_up_id, user_id, body, created_at) SELECT id, ${chair}, 'Looking at it today.', NOW() FROM follow_ups WHERE title LIKE 'RF6T6%'`);
        runSql(`INSERT INTO college_announcements (institution_id, college_id, created_by, scope_type, scope_id, audience, title, message, status, created_at, updated_at)
            VALUES (1, 1, ${dean}, 'college', 1, 'everyone', 'RF6T6 Exam week', 'Final exams start on Monday. Rooms are on the board.', 'active', NOW(), NOW())`);
        runSql(`INSERT INTO audit_logs (actor_user_id, actor_label, acting_role, action, target_type, target_id, institution_id, new_values, created_at)
            VALUES (${chair}, 'RF6T6 Chair', 'chairperson', 'course.chairperson_action', 'course_offering', ${offering("IT102", "A")}, 1, '{"method":"update"}', NOW())`);
        runSql(`INSERT INTO grade_finalizations (course_offering_id, finalized_by, finalized_at, students, reopen_reason, open_marker) VALUES (${offering("IT101", "B")}, ${user("instructor1@mentora.test")}, NOW(), 0, 'RF6T6', 1)`);
        runSql(`INSERT INTO grade_signoffs (offering_id, signed_by, signed_at, reopen_reason, open_marker, created_at, updated_at) VALUES (${offering("IT102", "A")}, ${dean}, NOW(), 'RF6T6', 1, NOW(), NOW())`);
    };

    /** The page and every dialog it opens: no sideways scroll, 44px controls (inline links measured too), axe. */
    async function checkPage(page, ready) {
        await expect(page.locator(ready).first()).toBeVisible();
        await expectNoPageOverflow(page);
        await expectTouchTargets(page, "main");
        // expectTouchTargets skips inline links: every visible link in a list or table is measured here.
        const small = await page.locator("main :is(table, li) a[href]").evaluateAll((els) => els
            .filter((el) => el.checkVisibility())
            .map((el) => ({ h: el.getBoundingClientRect().height, w: el.getBoundingClientRect().width, html: el.outerHTML.slice(0, 120) }))
            .filter(({ h, w }) => h < 44 || w < 44));
        expect(small).toEqual([]);
        await expectAccessible(page, "main");
    }

    /** Opens a dialog through its own control (a row's ⋯ sheet below md) and checks it the way the sweep does. */
    async function checkDialog(page, control, check) {
        const id = await control.getAttribute("data-dialog-open");
        await via(id, () => control).open(page);
        const dialog = page.locator(`#${id}`);
        await expectTouchTargets(page, `#${id}`);
        await expectNoPageOverflow(page);
        await expectNoPanelOverflow(dialog);
        await expectDialogFits(dialog);
        await expectAccessible(page, `#${id}`);
        await check?.(dialog);
        await page.keyboard.press("Escape");
        await expect(dialog).toBeHidden();
    }

    /** The row's action, from the ⋯ sheet below md (shared/row-actions.js moves it in, and the sheet is checked too), else in the row. */
    async function action(page, row, selector) {
        const entry = await rowEntry(page, row, selector, selector);
        const sheet = entry.locator("xpath=ancestor::*[@data-dialog][1]");
        if (await sheet.count()) {
            const id = await sheet.getAttribute("id");
            await expectTouchTargets(page, `#${id}`);
            await expectNoPanelOverflow(sheet);
            await expectAccessible(page, `#${id}`);
        }

        return entry;
    }

    /** A reason box on a phone: 16px (no zoom on focus) and named; the primary action (last) stays above the keyboard. */
    async function reasonField(dialog, name, primary, phone) {
        const field = dialog.locator(`textarea[name="${name}"]`);
        await expect(field).toHaveAccessibleName(/.+/);
        if (phone) {
            expect(await field.evaluate((el) => globalThis.getComputedStyle(el).fontSize)).toBe("16px");
        }
        const last = dialog.locator("button").filter({ visible: true }).last();
        await expect(last).toHaveText(primary);
        // A pinned bar: its bottom edge is the panel's, however long the body.
        if (phone) {
            const [bar, panel] = await Promise.all([last.locator("xpath=..").boundingBox(), dialogPanel(dialog).boundingBox()]);
            expect(Math.abs(bar.y + bar.height - (panel.y + panel.height)), "the action bar sits on the panel's bottom edge").toBeLessThanOrEqual(2);
        }
    }

    for (const size of [{ w: 375, h: 812 }, { w: 834, h: 1112 }]) {
        const phone = size.w < 640;

        test.describe(`${size.w}`, () => {
            test.use({ viewport: { width: size.w, height: size.h }, hasTouch: true, isMobile: true });

            for (const theme of THEMES) {
                test(`dean-approvals ${theme}: the request row and its review dialog`, async ({ page }) => {
                    removeRows();
                    seedRows();
                    try {
                        await open(page, { path: "/dean/approvals", as: "dean2" }, theme);
                        await checkPage(page, "[data-table] [data-row]");
                        const row = page.locator("[data-table] [data-row]").first();
                        await checkDialog(page, await action(page, row, '[data-dialog-open^="review-"]'), (dialog) => reasonField(dialog, "reason", phone ? /^Approve 75%$/ : "Reject", phone));
                    } finally {
                        removeRows();
                    }
                });

                test(`chairperson-override-requests ${theme}: the request row, endorse and send back`, async ({ page }) => {
                    removeRows();
                    seedRows();
                    try {
                        await open(page, { path: "/chairperson/override-requests", as: "chairperson" }, theme);
                        await checkPage(page, "[data-table] [data-row]");
                        const row = page.locator("[data-table] [data-row]").filter({ hasText: "RF6T6" });
                        await checkDialog(page, await action(page, row, '[data-dialog-open^="endorse-"]'), (dialog) => reasonField(dialog, "comment", "Endorse", phone));
                        await checkDialog(page, await action(page, row, '[data-dialog-open^="send-back-"]'), (dialog) => reasonField(dialog, "comment", "Send back", phone));
                    } finally {
                        removeRows();
                    }
                });

                test(`chairperson-follow-ups ${theme}: the thread, the course picker and the new follow-up form`, async ({ page }) => {
                    removeRows();
                    seedRows();
                    try {
                        await open(page, { path: "/chairperson/follow-ups", as: "chairperson" }, theme);
                        await checkPage(page, "[data-table] [data-row]");
                        const row = page.locator("[data-table] [data-row]").filter({ hasText: "RF6T6" });
                        await checkDialog(page, await action(page, row, '[data-dialog-open^="thread-"]'), (dialog) => reasonField(dialog, "body", phone ? "Send reply" : "Mark as done", phone));
                        // A one-question step (which course): it keeps the bottom dialog on a phone.
                        const pick = page.locator("#follow-up-pick");
                        await checkDialog(page, page.locator('main [data-dialog-open="follow-up-pick"]'));
                        await openDialog(page.locator('main [data-dialog-open="follow-up-pick"]'), pick);
                        await pick.locator("select").selectOption({ index: 1 });
                        await pick.getByRole("button", { name: "Continue" }).click();
                        const form = page.locator("#follow-up-new");
                        await expect(form).toBeVisible();
                        await expectTouchTargets(page, "#follow-up-new");
                        await expectNoPanelOverflow(form);
                        await expectDialogFits(form);
                        await expectAccessible(page, "#follow-up-new");
                        await reasonField(form, "message", "Send follow-up", phone);
                    } finally {
                        removeRows();
                    }
                });

                // The dean's page is the same file with the sent box only (no pass-on): the thread they sent, and a new one.
                test(`dean-follow-ups ${theme}: the thread, the course picker and the new follow-up form`, async ({ page }) => {
                    removeRows();
                    seedRows();
                    try {
                        await open(page, { path: "/dean/follow-ups", as: "dean2" }, theme);
                        await checkPage(page, "[data-table] [data-row]");
                        const row = page.locator("[data-table] [data-row]").filter({ hasText: "RF6T6" });
                        await checkDialog(page, await action(page, row, '[data-dialog-open^="thread-"]'), (dialog) => reasonField(dialog, "body", phone ? "Send reply" : "Mark as done", phone));
                        const pick = page.locator("#follow-up-pick");
                        await checkDialog(page, page.locator('main [data-dialog-open="follow-up-pick"]'));
                        await openDialog(page.locator('main [data-dialog-open="follow-up-pick"]'), pick);
                        await pick.locator("select").selectOption({ index: 1 });
                        await pick.getByRole("button", { name: "Continue" }).click();
                        const form = page.locator("#follow-up-new");
                        await expect(form).toBeVisible();
                        await expectTouchTargets(page, "#follow-up-new");
                        await expectNoPanelOverflow(form);
                        await expectDialogFits(form);
                        await expectAccessible(page, "#follow-up-new");
                        await reasonField(form, "message", "Send follow-up", phone);
                    } finally {
                        removeRows();
                    }
                });

                test(`dean-announcements ${theme}: the row, new, edit and end`, async ({ page }) => {
                    removeRows();
                    seedRows();
                    try {
                        await open(page, { path: "/dean/announcements", as: "dean2" }, theme);
                        await checkPage(page, "[data-table] [data-row]");
                        const row = page.locator("[data-table] [data-row]").filter({ hasText: "RF6T6" });
                        await checkDialog(page, page.locator('main [data-dialog-open="announcement-new"]'), (dialog) => reasonField(dialog, "message", "Post announcement", phone));
                        await checkDialog(page, await action(page, row, '[data-dialog-open^="edit-"]'), (dialog) => reasonField(dialog, "message", "Save changes", phone));
                        await checkDialog(page, await action(page, row, '[data-dialog-open^="end-"]'));
                    } finally {
                        removeRows();
                    }
                });

                test(`chairperson-announcements ${theme}: the college's announcement`, async ({ page }) => {
                    removeRows();
                    seedRows();
                    try {
                        await open(page, { path: "/chairperson/announcements?level=college", as: "chairperson" }, theme);
                        await expect(page.locator("[data-college-announcement]").filter({ hasText: "RF6T6" })).toBeVisible();
                        await checkPage(page, "[data-college-announcement]");
                    } finally {
                        removeRows();
                    }
                });

                test(`dean-chairperson-review ${theme}: the change row`, async ({ page }) => {
                    removeRows();
                    seedRows();
                    try {
                        await open(page, { path: "/dean/chairperson-review", as: "dean2" }, theme);
                        await checkPage(page, "[data-table] [data-row]");
                        // Mark reviewed (a write) sits in the ⋯ sheet below md: it is only looked at, not pressed.
                        await expect(await action(page, page.locator("[data-table] [data-row]").first(), "[data-mark-reviewed]")).toBeVisible();
                    } finally {
                        removeRows();
                    }
                });

                test(`dean-signoff ${theme}: sign off, sign off all, the password step and reopen`, async ({ page }) => {
                    removeRows();
                    seedRows();
                    try {
                        await open(page, { path: `/dean/signoff${SEEDED_TERM}`, as: "dean2" }, theme);
                        await checkPage(page, '[data-state="signed_off"]');
                        await checkDialog(page, page.locator('[data-state="signed_off"] [data-dialog-open^="reopen-"]'), (dialog) => reasonField(dialog, "reason", "Reopen", phone));
                        await checkDialog(page, page.locator("[data-signoff-all]").first());
                        await checkDialog(page, page.locator('[data-state="ready"] [data-dialog-open^="signoff-"]'));

                        // The password step: the sign-off is answered "confirm your password" here, so nothing is signed off.
                        await page.route(/\/signoff\/\d+$/, (route) => route.fulfill({ status: 403, contentType: "application/json", body: JSON.stringify({ error: "reauth_required" }) }));
                        const control = page.locator('[data-state="ready"] [data-dialog-open^="signoff-"]');
                        const confirm = page.locator(`#${await control.getAttribute("data-dialog-open")}`);
                        await via(await control.getAttribute("data-dialog-open"), () => control).open(page);
                        await confirm.locator("[data-confirm-submit]").click();
                        const password = page.locator("#reauth-dialog");
                        await expect(password).toBeVisible();
                        await expectTouchTargets(page, "#reauth-dialog");
                        await expectNoPanelOverflow(password);
                        await expectDialogFits(password);
                        await expectAccessible(page, "#reauth-dialog");
                        if (phone) {
                            expect(await password.locator("input[name=password]").evaluate((el) => globalThis.getComputedStyle(el).fontSize)).toBe("16px");
                        }
                    } finally {
                        removeRows();
                    }
                });
            }
        });
    }

    // Below md the sort links live in the screen-reader-only heading row; a keyboard reaching one shows the row.
    test.describe("375 sort links", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        test("dean-teaching-load: a focused sort link shows its heading row, 44px, inside the window", async ({ page }) => {
            await open(page, { path: `/dean/teaching-load${SEEDED_TERM}`, as: "dean2" }, "light");
            const link = page.locator("[data-table] thead th[aria-sort] a").first();
            await expect(link).toHaveAttribute("href", /sort=/);
            expect((await page.locator("[data-table] thead").boundingBox()).height, "hidden until focused").toBeLessThanOrEqual(1);

            await link.focus();
            const box = await link.boundingBox();
            expect(box.height).toBeGreaterThanOrEqual(44);
            expect(box.x).toBeGreaterThanOrEqual(0);
            expect(box.x + box.width).toBeLessThanOrEqual(375);
            await expectNoPageOverflow(page);
            await expectTouchTargets(page, "[data-table] thead");
        });
    });

    test.describe("375 password page", () => {
        test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });

        for (const theme of THEMES) {
            test(`reauth ${theme}: the password page fits a phone`, async ({ page }) => {
                await open(page, { path: "/reauth", as: "dean2" }, theme);
                await checkPage(page, "[data-reauth-page]");
                expect(await page.locator("[data-reauth-page] input[name=password]").evaluate((el) => globalThis.getComputedStyle(el).fontSize)).toBe("16px");
            });
        }
    });

    // A dean with no college, and dean1's college with nothing in it: the explanation and the empty states fit 280.
    test.describe("280 empty", () => {
        test.use({ viewport: { width: 280, height: 653 }, hasTouch: true, isMobile: true });
        const deanship = deanOneHasCollege;

        test("dean1's empty lists fit", async ({ page }) => {
            await open(page, { path: "/dean/dashboard", as: "dean" }, "light");
            for (const [path, text] of [
                ["/dean/approvals", "Nothing waiting for you"],
                ["/dean/signoff", "No sections to sign off"],
                ["/dean/follow-ups", "No open follow-ups"],
                ["/dean/announcements", "No announcements showing"],
                ["/dean/chairperson-review", "Nothing to review"],
                ["/dean/teaching-load", "When your college has courses running in a term"],
            ]) {
                await page.goto(path);
                await expect(page.locator("main").getByText(text).first(), path).toBeVisible();
                await expectNoPageOverflow(page);
                await expectTouchTargets(page, "main");
            }
        });

        test("no-assignment fits", async ({ page }) => {
            deanship(true);
            deanship(false);
            try {
                await open(page, { path: "/dean/dashboard", as: "dean" }, "light");
                await expect(page.getByText("You are not assigned to a college yet")).toBeVisible();
                await expectNoPageOverflow(page);
                await expectTouchTargets(page, "main");
            } finally {
                deanship(true);
            }
        });
    });
});

// The Course Workspace as the chairperson and the dean see it, at 375: what each may change decides which of Phase
// 5's phone controls are in the page at all (counted in the DOM, so a hidden one fails too). The dean's group is
// read-only: no move arrows, ⋯ menus or sheet, dependencies, or any Save. The chairperson holds courses.manage and
// grading.manage (AuthGroups), so the modules page and Grade Work keep the instructor's controls, Save included.
test.describe("staff course workspace", () => {
    test.use({ viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true });
    const screen = (name) => SCREENS.find((s) => s.name === name);
    const save = (page) => page.locator("button").filter({ hasText: /\bSave\b/ });
    const WRITE = ["[data-question-move]", "[data-module-move]", "[data-learning-item-dependencies]", "[data-module-actions]", "[data-learning-item-actions]", "[data-learning-unit-actions]", "#content-actions-sheet", "[data-dependencies-save]"];

    async function expectNone(page, selectors, where) {
        for (const selector of selectors) {
            await expect(page.locator(selector), `${selector} on ${where}`).toHaveCount(0);
        }
        await expect(save(page), `a Save button on ${where}`).toHaveCount(0);
    }

    test("the dean's modules tab and module page have no move, ⋯, dependencies or Save control", async ({ page }) => {
        const modules = screen("dean-course-modules");
        await open(page, modules, "light");
        await expectNone(page, WRITE, "the Modules tab");

        const id = await page.locator("[data-module-wrapper]").filter({ hasText: MODULE_TITLE }).first().getAttribute("data-module-id");
        await page.goto(`${await modules.path(page)}?module=${id}&view=1`);
        await expect(page.locator("main [data-learning-unit-list] [data-learning-unit-row]").first()).toBeVisible();
        await expectNone(page, WRITE, "the module page");
    });

    test("the assessment a dean opens from the Grades tab has no grading or write control", async ({ page }) => {
        await open(page, screen("dean-assessment-view"), "light");
        // The view really loaded (its own title, not the Grades list's row), so the absences below mean something.
        await expect(page).toHaveURL(/[?&]assessment=\d+/);
        await expect(page.locator("#course-content").getByText("Assignment 1: Grade Calculator").first()).toBeVisible();
        await expect(page.locator("#course-content [data-gradebook-toolbar]")).toHaveCount(0);

        const grading = ["[data-grade-work-student-list]", "[data-grade-work-submit-grade]", "[data-expandable-grade-work-actions-panel]", "[data-expand-grade-work-actions]", "[data-regrade-open]", "[data-action-bar]", "[data-edit-assessment-button]"];
        await expectNone(page, grading, "the assessment view");
        await expect(page.locator("#course-content button").filter({ hasText: /\bSubmit\b/ }), "a Submit button").toHaveCount(0);
    });

    test("the chairperson keeps the move arrows, ⋯ menus and the Grade Work score bar's Save", async ({ page }) => {
        await open(page, screen("chairperson-course-modules"), "light");
        await expect(page.locator("[data-module-move]").first()).toBeAttached();
        await expect(page.locator("[data-module-actions]").first()).toBeAttached();
        await expect(page.locator("#content-actions-sheet")).toHaveCount(1);

        // Already signed in, so the Grade Work screen is reached without open()'s sign-in.
        const gradeWork = screen("chairperson-grade-work");
        await page.goto(await gradeWork.path(page));
        await gradeWork.then(page);
        // Earlier specs grade Maria's work, which locks the bar (no Save); Angela has none, so her bar is open.
        await pickStudent(page, "Angela Cruz");
        await expect(page.locator("[data-expandable-grade-work-actions-panel] [data-grade-work-submit-grade]")).toHaveCount(1);
    });

    // The dean's one sheet on the Grades tab (no offline grade sheet: read-only): opened from the toolbar's Reports row.
    for (const theme of THEMES) {
        test(`the dean's Reports sheet fits a phone ${theme}`, async ({ page }) => {
            await open(page, screen("dean-course-grades"), theme);
            await expect(page.locator("#grade-sheet-menu-sheet")).toHaveCount(0);
            await via("report-menu-sheet", (p) => p.locator('[data-gradebook-toolbar] [data-row-actions-open][aria-controls="report-menu-sheet"]')).open(page);
            const sheet = page.locator("#report-menu-sheet");
            await expect(sheet.locator("[data-row-actions-slot] :is(a, button)").first()).toBeVisible();
            await expectTouchTargets(page, "#report-menu-sheet");
            await expectNoPanelOverflow(sheet);
            await expectDialogFits(sheet);
            await expectAccessible(page, "#report-menu-sheet");
            await page.keyboard.press("Escape");
            await expect(sheet).toBeHidden();
        });
    }
});

if (FULL) {
    for (const screen of SCREENS) {
        test(`${screen.name} @zoom200`, async ({ page }, testInfo) => {
            test.skip(testInfo.project.name === "webkit", "WebKit runs the 375px phone only");

            screen.seed?.();
            await open(page, screen, "light");
            await zoom200(page);

            await expectNoPageOverflow(page, screen.overflowScope);
        });
    }
}
