<?php
/**
 * Courses that are over for the child (finished, dropped or withdrawn),
 * folded away until opened. Only drawn when there are some.
 *
 * @var list<array<string, mixed>> $past    cards of ParentDashboardService
 * @var int                        $childId the child the dashboard is showing
 */
?>

<details class="rounded-2xl bg-white p-4 shadow-sm sm:p-6" data-parent-past-terms>
    <summary class="flex cursor-pointer items-center pointer-coarse:min-h-11 text-sm font-bold text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">Past terms (<?= count($past) ?>)</summary>

    <div class="@container mt-4 w-full min-w-0"><div class="grid grid-cols-1 gap-4 @md:grid-cols-2">
        <?php foreach ($past as $course): ?>
            <?= view('pages/parent/partials/course-card', ['course' => $course, 'past' => true, 'childId' => (int) $childId], ['saveData' => false]) ?>
        <?php endforeach; ?>
    </div></div>
</details>
