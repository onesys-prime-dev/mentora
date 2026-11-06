# Dependencies: items, learning units, modules, and a minimum score

A student's way through a course can be gated at three levels, all set from a **Dependencies** action in the
three-dot menu (or the lock button on the module page):

| What waits | Waits on | Completed means |
|---|---|---|
| Learning item (as before) | earlier items in the same unit | the item is completed |
| **Learning unit** | earlier published units of the same module | every published item in the unit is completed |
| **Module** | earlier published modules of the course | every published item in the module is completed |

Each has the same three choices: none, **the previous one**, or a **custom list** of earlier ones (all must be completed).

- A module or unit with nothing published in it counts as completed, so it never holds anything back.
- Only published, earlier siblings count, so a lock cannot become impossible. Reordering or deleting drops a
  custom dependency that is no longer on something earlier (and one left with none becomes "none").
- A locked module or unit **locks the items inside it**, and the server refuses to open them (the same `lockFor` check
  every item screen, quiz, assignment and file download already uses). The lock message names what to complete.
- Instructors are never locked. They see "After …" on the module and unit instead.
- The student sees "Locked · after …" on the module card and the unit header.

Code: `ContainerDependencyLogic` (the module and unit rules, used by `LearningProgressService`);
`modals/container-dependencies.js` and `partials/modals/container-dependencies.php`;
routes `GET/PATCH …/modules/{id}/dependencies` and `…/learning-units/{id}/dependencies`.
Migration `2026-11-26-000001` adds `prerequisite_mode` and the two link tables.

## Require a minimum score (items waiting on a quiz or an assignment)

In an item's Dependencies modal, when something it waits on is a quiz or an assignment, a **Require a minimum score**
switch appears: **the assessment's own passing score**, or **a custom percentage** (1 to 100).

- One setting per waiting item, applied to every quiz and assignment among what it waits on (the previous item, or the
  ticked ones). Readings, videos and the rest are held to completion only.
- The grade that counts is the one every screen uses (`AssessmentResultService`): the best graded quiz attempt, the latest
  graded assignment. Work not graded yet does not count: the item stays locked until it is.
- Equal to the minimum is enough. The assessment's passing score is its percentage, or its passing points as a
  percentage of its total; one with neither asks for nothing more.
- The lock message says what is needed: Complete “Quiz 1” (at least 70%) first.
- Switching dependencies off switches the score off. Migration columns: `dependency_min_score_mode` and
  `dependency_min_score_percent` on `learning_items`.

Tests: `tests/database/ContainerDependenciesTest.php`, `DependencyMinScoreTest.php`,
`tests/feature/ContainerDependenciesRouteTest.php`, `tests/js/pages/container-dependencies.test.js`,
`learning-item-dependencies.test.js`, `tests/e2e/module-dependencies.spec.js` (TC-MOD-13 to 15).
