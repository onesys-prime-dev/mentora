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
?>

<div class="flex flex-col gap-10 p-10" data-assessments-overview>

    <!-- Key metrics -->
    <?= view('pages/courses/features/assessments/partials/overview/metrics', ['metrics' => $overview['metrics'] ?? []]) ?>

    <div class="flex flex-1 gap-10">

        <!-- Assessment list -->
        <div class="flex w-full min-w-0 flex-col gap-5">

            <div class="flex flex-wrap items-center justify-between gap-4">

                <div class="flex items-center gap-4">

                    <?= icon('assessments', 'h-6 w-6') ?>

                    <span class="text-2xl font-bold text-[#0040A6]">
                        Assessments
                    </span>

                </div>

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
                            class="inline-flex items-center gap-2 rounded-4xl bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white shadow transition-colors duration-300 hover:bg-[#002068]"
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
                        <span class="text-sm font-semibold text-[#5B5B5B]">No assessments yet</span>
                        <span class="text-xs text-[#939393]">
                            <?= $isInstructor
                                ? 'Create one with “Add Assessment”, or add a quiz or assignment to a learning unit in Modules.'
                                : 'Your instructor hasn’t published any assessments yet.' ?>
                        </span>
                    </div>

                <?php endif; ?>

                <div
                    data-assessments-no-match
                    class="hidden flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-gray-200 px-8 py-14 text-center">
                    <span class="text-sm font-semibold text-[#5B5B5B]">Nothing matches</span>
                    <span class="text-xs text-[#939393]">Try another filter or search term.</span>
                </div>

            </div>

        </div>

        <!-- Sidebar -->
        <div class="flex h-full w-2/5 shrink-0 flex-col gap-10">

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
