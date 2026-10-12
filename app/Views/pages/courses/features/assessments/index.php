<?php

/*
|--------------------------------------------------------------------------
| Assessments tab. Everything role-specific comes from
| AssessmentOverviewService ($overview): metrics, the list rows and the
| sidebars. Students never receive the instructor controls or modals.
|--------------------------------------------------------------------------
*/

$isInstructor = ($overview['role'] ?? '') === 'instructor';

$rows = $overview['assessments'] ?? [];

$filters = $isInstructor
    ? ['' => 'All', 'published' => 'Published', 'draft' => 'Draft', 'closed' => 'Closed', 'archived' => 'Archived']
    : ['' => 'All', 'todo' => 'To do', 'submitted' => 'Submitted', 'graded' => 'Graded', 'overdue' => 'Overdue'];

/*
|--------------------------------------------------------------------------
| Metric cards — a composition count, not Overview's urgency numbers.
|--------------------------------------------------------------------------
| Overview already answers "what needs me right now" (To Grade, Due This
| Week, Missing Work…). This list page answers a different question —
| "what's in this list" — so the cards here count what's on screen instead,
| using exactly the same tags assessment-card.php gives each card for the
| filter dropdown below. That's deliberate: the numbers on these cards
| always match what clicking the matching filter shows, because they're
| built from the same rule, not a second copy of it.
*/

$total = count($rows);

if ($isInstructor) {

    $byStatus = ['Published' => 0, 'Draft' => 0, 'Closed' => 0, 'Archived' => 0];

    foreach ($rows as $row) {
        $byStatus[$row['status']] = ($byStatus[$row['status']] ?? 0) + 1;
    }

    $listMetrics = [
        [
            'label' => 'Total Assessments', 'filter' => '', 'icon' => 'assessments', 'tone' => 'neutral',
            'value' => (string) $total, 'detail' => 'Across every module in this section',
        ],
        [
            'label' => 'Published', 'filter' => 'published', 'icon' => 'check', 'tone' => 'neutral',
            'value' => (string) $byStatus['Published'], 'detail' => 'Visible to students now',
        ],
        [
            'label' => 'Draft', 'filter' => 'draft', 'icon' => 'drafts', 'tone' => $byStatus['Draft'] > 0 ? 'warning' : 'neutral',
            'value' => (string) $byStatus['Draft'],
            'detail' => $byStatus['Draft'] > 0 ? 'Not visible to students yet' : 'Everything is published',
        ],
        [
            'label' => 'Archived', 'filter' => 'archived', 'icon' => 'lock', 'tone' => 'neutral',
            'value' => (string) $byStatus['Archived'], 'detail' => 'Retired from grading and the list',
        ],
    ];

} else {

    $todo = 0;
    $submitted = 0;
    $graded = 0;

    foreach ($rows as $row) {

        $student = $row['student'];

        if ($student['actionable']) {
            $todo++;
        }

        if (in_array($student['key'], ['submitted', 'grading', 'resubmitted', 'partially_graded'], true)) {
            $submitted++;
        }

        if ($student['key'] === 'graded') {
            $graded++;
        }

    }

    $listMetrics = [
        [
            'label' => 'Total Assessments', 'filter' => '', 'icon' => 'assessments', 'tone' => 'neutral',
            'value' => (string) $total, 'detail' => 'Visible to you in this course',
        ],
        [
            'label' => 'To Do', 'filter' => 'todo', 'icon' => 'tasks', 'tone' => $todo > 0 ? 'warning' : 'neutral',
            'value' => (string) $todo,
            'detail' => $todo > 0 ? 'Still need your attention' : "You're all caught up",
        ],
        [
            'label' => 'Submitted', 'filter' => 'submitted', 'icon' => 'in-progress', 'tone' => 'neutral',
            'value' => (string) $submitted, 'detail' => 'Waiting on a grade',
        ],
        [
            'label' => 'Graded', 'filter' => 'graded', 'icon' => 'score', 'tone' => 'neutral',
            'value' => (string) $graded, 'detail' => 'Already scored',
        ],
    ];

}
?>

<div class="flex flex-col gap-10 p-10" data-assessments-overview>

    <?= view('components/page-header', ['icon' => 'assessments', 'title' => 'Assessments'], ['saveData' => false]) ?>

    <!-- Key metrics -->
    <?= view('pages/courses/features/assessments/partials/overview/metrics', ['metrics' => $listMetrics]) ?>

    <div class="flex flex-1 flex-col gap-10 lg:flex-row">

        <!-- Assessment list -->
        <div class="flex w-full min-w-0 flex-col gap-5">

            <div class="flex flex-wrap items-center justify-end gap-4">

                <div class="flex flex-wrap gap-4">

                    <select
                        data-assessments-filter
                        aria-label="Filter assessments"
                        class="h-10 w-44 appearance-none rounded-full border border-gray-300 bg-white px-4 pr-10 text-sm shadow outline-none focus:border-blue-500">

                        <?php foreach ($filters as $value => $label): ?>
                            <option value="<?= esc($value) ?>"><?= esc($label) ?></option>
                        <?php endforeach; ?>

                    </select>

                    <!-- Client-side search (no form submit, so no page reload) -->
                    <label class="flex h-10 w-64 items-center gap-2 rounded-4xl border border-gray-300 bg-white px-4 shadow">

                        <?= icon('search', 'h-4 w-4 opacity-60') ?>

                        <input
                            type="search"
                            data-assessments-search
                            placeholder="Search assessments"
                            aria-label="Search assessments"
                            class="flex-1 bg-transparent text-sm outline-none placeholder:text-gray-300">

                    </label>

                    <?php if ($isInstructor): ?>

                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white shadow transition-colors duration-300 hover:bg-brand-dark"
                            data-create-assessment-button>

                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
                            </svg>

                            <span>Add Assessment</span>

                        </button>

                    <?php endif; ?>

                </div>

            </div>

            <div class="flex flex-col gap-3" data-assessments-list>

                <?php foreach ($rows as $row): ?>
                    <?= view('pages/courses/features/assessments/partials/overview/assessment-card', ['assessment' => $row, 'isInstructor' => $isInstructor]) ?>
                <?php endforeach; ?>

                <?php if (!$rows): ?>

                    <div class="flex flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-gray-200 px-8 py-14 text-center">
                        <span class="text-sm font-semibold text-[#646464]">No assessments yet</span>
                        <span class="text-xs text-[#767676]">
                            <?= $isInstructor
                                ? 'Create one with “Add Assessment”, or add a quiz or assignment to a learning unit in Modules.'
                                : 'Your instructor hasn’t published any assessments yet.' ?>
                        </span>
                    </div>

                <?php endif; ?>

                <div
                    data-assessments-no-match
                    class="hidden flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-gray-200 px-8 py-14 text-center">
                    <span class="text-sm font-semibold text-[#646464]">Nothing matches</span>
                    <span class="text-xs text-[#767676]">Try another filter or search term.</span>
                </div>

            </div>

        </div>

        <!-- Sidebar -->
        <div class="flex h-full w-full shrink-0 flex-col gap-10 lg:w-2/5">

            <?= view(
                $isInstructor
                    ? 'pages/courses/features/assessments/partials/overview/sidebar-instructor'
                    : 'pages/courses/features/assessments/partials/overview/sidebar-student',
                ['overview' => $overview]
            ) ?>

        </div>

    </div>

</div>

<?php if ($isInstructor): ?>

    <!-- modals -->
    <?= $this->include('pages/courses/features/assessments/partials/modals/assessment-create') ?>
    <?= $this->include('pages/courses/features/assessments/partials/modals/assessment-editor') ?>
    <?= $this->include('pages/courses/features/assessments/partials/modals/assessment-delete') ?>

<?php endif; ?>
