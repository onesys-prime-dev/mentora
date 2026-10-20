<?php
/**
 * Instructor Grades page, top: the grading scheme's state and the
 * gradebook actions — edit the scheme, recalculate, and the offline grade
 * sheet (export XLSX/CSV, import). Wired in resources/js/pages/courses/
 * features/grades/index.js.
 */
$scheme = $gradebook['scheme'] ?? [];
$isPublished = ($scheme['status'] ?? '') === 'Published';
$needsRecalculation = !empty($scheme['needs_recalculation']);
$outOfSync = (int) ($gradebook['out_of_sync_count'] ?? 0);
$uncategorized = count($gradebook['uncategorized'] ?? []);
$totalWeight = (float) ($gradebook['total_weight'] ?? 0);
$finalization = $gradebook['finalization'] ?? null;
?>

<div class="flex flex-col gap-3" data-gradebook-toolbar>

    <div class="flex flex-wrap items-center justify-between gap-3">

        <div class="flex items-center gap-3">

            <?= view('components/status-badge', [
                'tone' => $isPublished ? 'success' : 'neutral',
                'icon' => $isPublished ? 'check' : 'lock',
                'label' => $isPublished ? 'Grading published' : "Grading in draft — students don't see final grades",
            ], ['saveData' => false]) ?>

            <span class="text-sm text-[#767676]">
                <?= esc($scheme['name'] ?? 'Grading Scheme') ?>
                · Passing <?= esc(rtrim(rtrim(number_format((float) ($scheme['passing_percentage'] ?? \App\Services\Institutions\AcademicDefaults::PLATFORM_PASSING), 2), '0'), '.')) ?>%
                <?php if (!empty($scheme['last_recalculated_at'])): ?>
                    · Last calculated <?= esc(date('M j, g:i A', strtotime($scheme['last_recalculated_at']))) ?>
                <?php endif; ?>
            </span>

        </div>

        <div class="flex items-center gap-2">

            <?php if ($finalization === null): ?>
                <button
                    type="button"
                    data-open-curve
                    class="inline-flex items-center gap-2 rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-brand transition hover:bg-brand/5">
                    <?= icon('score', 'h-4 w-4 text-current') ?>
                    Curve
                </button>
            <?php endif; ?>

            <?php if ($finalization !== null): ?>
                <button
                    type="button"
                    data-open-reopen
                    class="inline-flex items-center gap-2 rounded-xl border border-[#946200]/40 px-4 py-2 text-sm font-semibold text-[#946200] transition hover:bg-[#FFF8E6]">
                    <?= icon('lock', 'h-4 w-4 text-current') ?>
                    Reopen grades
                </button>
            <?php else: ?>
                <button
                    type="button"
                    data-open-finalize
                    class="inline-flex items-center gap-2 rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">
                    <?= icon('check', 'h-4 w-4 text-current') ?>
                    Finalize grades
                </button>
            <?php endif; ?>

            <button
                type="button"
                data-open-gradebook-roster
                class="inline-flex items-center gap-2 rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-brand transition hover:bg-brand/5">
                <?= icon('grades', 'h-4 w-4 text-current') ?>
                Gradebook
            </button>

            <button
                type="button"
                data-open-grading-scheme
                class="inline-flex items-center gap-2 rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-brand transition hover:bg-brand/5">
                <?= icon('settings', 'h-4 w-4 text-current') ?>
                Grading Scheme
            </button>

            <div class="relative">

                <button
                    type="button"
                    data-toggle-report-menu
                    class="inline-flex items-center gap-2 rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-brand transition hover:bg-brand/5">
                    Reports
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
                </button>

                <div data-report-menu class="absolute right-0 z-20 mt-2 hidden w-64 flex-col rounded-xl border border-gray-200 bg-white p-1 shadow-lg">
                    <p class="px-3 pt-2 pb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Class gradebook</p>
                    <button type="button" data-report-path="class" data-report-format="xlsx" class="rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100">Excel (.xlsx)</button>
                    <button type="button" data-report-path="class" data-report-format="csv" class="rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100">CSV (.csv)</button>
                    <p class="px-3 pt-2 pb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Class summary</p>
                    <button type="button" data-report-path="summary" data-report-format="pdf" class="rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100">PDF</button>
                    <button type="button" data-report-path="summary" data-report-format="html" data-report-mode="print" class="rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100">Print view</button>
                    <p class="px-3 py-2 text-xs text-gray-500">A student's own report is on their row in the Gradebook.</p>
                </div>

            </div>

            <div class="relative">

                <button
                    type="button"
                    data-toggle-grade-sheet-menu
                    class="inline-flex items-center gap-2 rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-brand transition hover:bg-brand/5">
                    Offline Grade Sheet
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
                </button>

                <div data-grade-sheet-menu class="absolute right-0 z-20 mt-2 hidden w-56 flex-col rounded-xl border border-gray-200 bg-white p-1 shadow-lg">
                    <a data-grade-sheet-export="xlsx" class="cursor-pointer rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-100">Export Excel (.xlsx)</a>
                    <a data-grade-sheet-export="csv" class="cursor-pointer rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-100">Export CSV (.csv)</a>
                    <button type="button" data-open-grade-sheet-import class="rounded-lg px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100">Import grade sheet…</button>
                </div>

            </div>

        </div>

    </div>

    <?php if ($finalization !== null): ?>
        <div class="flex items-center gap-3 rounded-2xl border border-[#BFD3F2] bg-brand-tint px-5 py-3 text-sm text-brand-dark" data-finalized-banner>
            <?= icon('lock', 'h-4 w-4 text-current') ?>
            <span>
                <strong>Grades are final.</strong>
                Finalized by <?= esc(trim((string) ($finalization['finalized_by_name'] ?? '')) !== '' ? $finalization['finalized_by_name'] : 'an instructor') ?>
                on <?= esc(date('M j, Y, g:i A', strtotime((string) $finalization['finalized_at']))) ?>.
                Scores, the grading scheme and imports are locked until you reopen them.
                <?php $handoff = $gradebook['handoff'] ?? null; ?>
                <?php if ($handoff !== null): ?>
                    <span data-sis-handoff>Sent to the student information system on <?= esc(date('M j, Y', strtotime($handoff['last_sent_at']))) ?> (<?= (int) $handoff['students'] ?> <?= $handoff['students'] === 1 ? 'student' : 'students' ?>).</span>
                <?php else: ?>
                    <span data-sis-handoff class="opacity-80">Not sent to the student information system yet.</span>
                <?php endif; ?>
            </span>
        </div>
    <?php endif; ?>

    <?php if ($needsRecalculation && $finalization === null): ?>

        <!-- Config changed since the last calculation: final grades wait for an explicit Recalculate. -->
        <div class="flex items-center justify-between gap-4 rounded-2xl border border-[#FFE3A3] bg-[#FFF8E6] px-5 py-3 text-sm text-[#946200]" data-recalculate-banner>
            <span>
                <strong>Grades may be out of date.</strong>
                The grading scheme changed since final grades were last calculated.
                <?php if (abs($totalWeight - 100) > 0.001): ?>
                    Category weights add up to <?= esc($totalWeight) ?>% — fix them to 100% first.
                <?php endif; ?>
            </span>
            <button
                type="button"
                data-recalculate-grades
                <?= abs($totalWeight - 100) > 0.001 ? 'disabled' : '' ?>
                class="shrink-0 rounded-lg bg-[#946200] px-4 py-2 text-xs font-semibold text-white transition hover:bg-[#7a5100] disabled:cursor-not-allowed disabled:opacity-50">
                Recalculate
            </button>
        </div>

    <?php endif; ?>

    <?php if ($outOfSync > 0 || $uncategorized > 0): ?>
        <div class="flex flex-wrap gap-2 text-xs">
            <?php if ($outOfSync > 0): ?>
                <span class="rounded-full bg-[#FFF1D6] px-3 py-1 font-semibold text-[#946200]">
                    <?= esc($outOfSync) ?> <?= $outOfSync === 1 ? 'item' : 'items' ?> out of sync with <?= $outOfSync === 1 ? 'its' : 'their' ?> quiz/assignment points
                </span>
            <?php endif; ?>
            <?php if ($uncategorized > 0): ?>
                <span class="rounded-full bg-gray-100 px-3 py-1 font-semibold text-gray-600">
                    <?= esc($uncategorized) ?> uncategorized <?= $uncategorized === 1 ? 'item doesn\'t' : 'items don\'t' ?> count yet
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<?= view('pages/courses/features/grades/partials/modals/grade-finalize', [], ['saveData' => false]) ?>
<?= view('pages/courses/features/grades/partials/modals/grade-curve', [], ['saveData' => false]) ?>
