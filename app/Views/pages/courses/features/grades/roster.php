<?php
/**
 * The gradebook grid (instructor): every enrolled student, their manual-item
 * scores — the only ones editable here, quiz/assignment scores come from
 * grading — and their final grade, with a click-to-override.
 * Data: GradingSchemeService::roster(). Wired in
 * resources/js/pages/courses/features/grades/roster/roster.js.
 */
$points = static fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
?>

<div class="p-10 flex flex-col gap-6" data-gradebook-roster>

    <div class="flex items-center gap-3 border-b border-gray-200 pb-4">

        <button type="button" data-gradebook-roster-back class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100" title="Back to Grades">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
        </button>

        <?= icon('grades', 'h-6 w-6') ?>

        <span class="text-xl font-bold text-[#0040A6]">Gradebook</span>

        <span class="flex items-center gap-2 text-sm text-gray-500 ml-2" data-roster-save-status>
            <span class="h-2 w-2 rounded-full bg-emerald-500" data-roster-save-dot></span>
            <span data-roster-save-message>All changes saved</span>
        </span>

    </div>

    <p class="text-sm text-gray-500">
        Only manual items (<?= esc(implode(', ', array_map(static fn ($i) => $i['name'], $items)) ?: 'none yet') ?>) are editable here.
        Quiz and assignment scores come from Grade Work. Click a final grade to override it.
    </p>

    <div class="overflow-x-auto rounded-2xl border border-[#A9A9A9]/50">

        <table class="w-full text-sm">

            <thead>
                <tr class="border-b border-gray-200 bg-gray-50 text-left">
                    <th class="px-4 py-3 font-semibold text-gray-600">Student</th>
                    <?php foreach ($items as $item): ?>
                        <th class="px-4 py-3 font-semibold text-gray-600 whitespace-nowrap">
                            <?= esc($item['name']) ?>
                            <span class="font-normal text-gray-400">/<?= $points($item['max_points']) ?></span>
                        </th>
                    <?php endforeach; ?>
                    <th class="px-4 py-3 font-semibold text-gray-600">Final</th>
                    <th class="px-4 py-3 font-semibold text-gray-600">Remarks</th>
                </tr>
            </thead>

            <tbody>

                <?php if ($students === []): ?>
                    <tr><td colspan="<?= count($items) + 3 ?>" class="px-4 py-8 text-center text-gray-400">No students enrolled in this section.</td></tr>
                <?php endif; ?>

                <?php foreach ($students as $student): ?>

                    <tr class="border-b border-gray-100 last:border-0" data-roster-row data-student-id="<?= (int) $student['student_id'] ?>">

                        <td class="px-4 py-2 whitespace-nowrap">
                            <div class="font-semibold text-[#646464]"><?= esc($student['student_name']) ?></div>
                            <div class="text-xs text-gray-400"><?= esc($student['student_number']) ?></div>
                        </td>

                        <?php foreach ($items as $item): ?>
                            <td class="px-4 py-2">
                                <input
                                    type="number" min="0" max="<?= $points($item['max_points']) ?>" step="0.5"
                                    value="<?= $student['scores'][$item['id']] ?? '' ?>"
                                    placeholder="—"
                                    data-manual-score-input data-item-id="<?= (int) $item['id'] ?>"
                                    class="w-20 rounded-lg border border-gray-300 px-2 py-1 text-sm outline-none transition focus:border-[#0040A6]">
                            </td>
                        <?php endforeach; ?>

                        <td class="px-4 py-2">
                            <button
                                type="button"
                                data-open-override
                                data-current-percentage="<?= $student['percentage'] !== null ? $points($student['percentage']) : '' ?>"
                                data-calculated-percentage="<?= $student['calculated_percentage'] !== null ? $points($student['calculated_percentage']) : '' ?>"
                                data-is-override="<?= $student['is_override'] ? '1' : '0' ?>"
                                data-override-reason="<?= esc($student['override_reason'] ?? '', 'attr') ?>"
                                data-student-name="<?= esc($student['student_name'], 'attr') ?>"
                                class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-sm font-bold transition hover:bg-gray-100 <?= $student['is_override'] ? 'text-[#946200]' : 'text-[#0040A6]' ?>"
                                title="Click to override">
                                <?= $student['percentage'] !== null ? $points($student['percentage']) . '%' : '—' ?>
                                <?php if ($student['is_override']): ?>
                                    <span class="rounded-full bg-[#FFF1D6] px-1.5 py-0.5 text-[10px] font-bold text-[#946200]">Override</span>
                                <?php endif; ?>
                            </button>
                        </td>

                        <td class="px-4 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-bold <?= match ($student['remarks']) {
                                'Passed' => 'bg-emerald-100 text-emerald-700',
                                'Failed' => 'bg-[#FFDAD6] text-[#B3261E]',
                                default => 'bg-gray-100 text-gray-500',
                            } ?>"><?= esc($student['remarks']) ?></span>
                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

<?= view('pages/courses/features/grades/partials/modals/grade-override', [], ['saveData' => false]) ?>
