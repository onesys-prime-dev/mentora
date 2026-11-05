# Publishing modules, learning units and learning items

The Add (and Edit) modals of a module, a learning unit and a learning item share three controls
(`partials/modals/publish-fields.php`, `modals/publish-fields.js`):

| Control | What it does |
|---|---|
| Draft / Publish | A new row is a draft by default (as before). Publish makes it visible when its parents are. |
| Schedule publish (draft only) | Switch on to show a date and time picker. A scheduled draft stays a draft, and hidden, until then, and then publishes itself. |
| Hidden badge | Publishing under a draft parent shows "Hidden — module is a draft" (or "unit"), the same rule as the Hidden chip in the lists: a student sees a row only when it and its unit and module are all published. |

Rules (`ScheduledPublishing::fields`, applied by `ModuleService::store*` and, from the modal, `updateModule`):
- Publish drops any schedule; a draft with the switch off has none.
- A schedule needs a time, in the future, and a real date (31 February is refused, not rolled over).
- A **quiz** cannot be added published (it has no question yet): the modal keeps it a draft and says why; the server refuses too.
- An **assignment** added published is published with its assessment, like publishing it from Modules later.
- Publishing or drafting by hand (the Publish button, the item editor) clears a waiting schedule.
- The lists show a "Scheduled · Nov 7, 9:30 AM" chip for a scheduled draft.

## The sweep

`ScheduledPublishing::publishDue()` publishes whatever is due. It runs from the every-minute job (`php spark exports:run`,
the same job that sends the mail outbox), so there is no new cron line. A failure is logged and tried again next minute.
A quiz item with no question yet is skipped and stays scheduled: it goes out on the first sweep after it has one.
Times are as typed, on the same clock the assessments' opening and closing dates use.

Migration `2026-11-25-000001` adds `scheduled_publish_at` to `course_modules`, `learning_units` and `learning_items`.

Tests: `tests/database/ScheduledPublishingTest.php`, `tests/js/pages/publish-fields.test.js`,
`tests/e2e/module-publish.spec.js` (TC-MOD-10 to 12).
