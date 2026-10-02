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
                · Passing <?= esc(rtrim(rtrim(number_format((float) ($scheme['passing_percentage'] ?? 75), 2), '0'), '.')) ?>%
                <?php if (!empty($scheme['last_recalculated_at'])): ?>
                    · Last calculated <?= esc(date('M j, g:i A', strtotime($scheme['last_recalculated_at']))) ?>
                <?php endif; ?>
            </span>

        </div>

        <div class="flex items-center gap-2">

            <button
                type="button"
                data-open-gradebook-roster
                class="inline-flex items-center gap-2 rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-[#0040A6] transition hover:bg-[#0040A6]/5">
                <?= icon('grades', 'h-4 w-4 text-current') ?>
                Gradebook
            </button>

            <button
                type="button"
                data-open-grading-scheme
                class="inline-flex items-center gap-2 rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-[#0040A6] transition hover:bg-[#0040A6]/5">
                <?= icon('settings', 'h-4 w-4 text-current') ?>
                Grading Scheme
            </button>

            <div class="relative">

                <button
                    type="button"
                    data-toggle-grade-sheet-menu
                    class="inline-flex items-center gap-2 rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-[#0040A6] transition hover:bg-[#0040A6]/5">
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

    <?php if ($needsRecalculation): ?>

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
