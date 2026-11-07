# Copy and move content between courses

A module, a learning unit or a learning item can be copied to, or moved to, a course (offering) of the instructor's
own. The entries are in each row's three-dot menu ("Copy to…", "Move to…"), and as two buttons on a module's page.
One dialog walks through the steps:

| What | Copy | Move |
|---|---|---|
| Module | course > choose the units and items (all ticked) | another course > confirm |
| Learning unit | course > module > choose the items | another course > module > confirm |
| Learning item | course > module > unit | another course > module > unit > confirm |

"Course" is **this course** or one of the other courses the person teaches in the same institution (not archived).
A move is only to another course.

## Copy

- The copy is a **draft**, at the end of where it goes, and a scheduled publish is not copied.
- A quiz or assignment comes with its settings, questions and choices, rubrics and criteria; its dates move by the
  difference between the two terms' start dates (as copying a whole offering does). Files are referenced, not duplicated
  (a file is only removed when nothing points at it any more). A discussion item gets its own empty thread with the same prompt.
- **Dependencies** are kept between what was copied together (an item that waited on the item before it still does, when
  that one came too; custom lists are remapped to the copies; a minimum score goes with the dependency) and dropped
  otherwise. The module's own dependencies, and the ones tying a unit or item to what stays behind, are not copied.
- Never copied: progress, attempts, grades, replies. "Specific students" is switched off on a copied assessment (its
  list of students would be empty, hiding it from everyone).
- Copied into the course it came from, the title gets " (copy)".

## Move

- Refused while **any student has worked on any of it**: progress, a quiz attempt or submission, or a discussion reply.
  The message says how many items, and to copy it instead: that work would otherwise stay behind or attach to the wrong course.
- What moves becomes a draft (its parts keep their own state, hidden under it). Its assessments and discussions move with
  it; their gradebook items stay in the old course, archived.
- Its dependencies are cut on both sides: it stops waiting, and what waited on it stops too.

Both need the person to teach the course they take from and the one they put it into, are all or nothing (one transaction),
and say why when refused. A student, and an instructor of another course, get nothing from the addresses.

## Code

`ContentTransferService` (rules, copy, move), `ContentTransferController` and the routes `…/content-transfer/*`;
`modals/content-transfer.js` (the steps), `partials/modals/content-transfer.php`, `partials/transfer-buttons.php`.
Copying a whole offering into a new term (`OfferingCopyService`) now also copies the dependencies of modules and units.

Tests: `tests/database/ContentTransferServiceTest.php`, `tests/feature/ContentTransferRouteTest.php`,
`tests/js/pages/content-transfer.test.js`, `content-transfer-api.test.js`, `tests/e2e/content-transfer.spec.js` (TC-MOD-16 to 18).
