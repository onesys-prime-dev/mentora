<?php
/**
 * The gradebook grid (instructor): every enrolled student, their manual-item
 * scores — the only ones editable here, quiz/assignment scores come from
 * grading — and their final grade, with a click-to-override.
 * Data: GradingSchemeService::roster(). Wired in
 * resources/js/pages/courses/features/grades/roster/roster.js.
 */
$points = static fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

// Where the institution has deans a change to a final grade is a request, and each student may have
// one open (GradeOverrideRequestService); elsewhere it applies at once.
$overrideMode  = $overrideMode ?? 'direct';
$overrideRoute = $overrideRoute ?? '';
$openRequests  = $openRequests ?? [];
$isRequestMode = $overrideMode === 'request';
?>

<div class="p-10 flex flex-col gap-6" data-gradebook-roster data-override-mode="<?= esc($overrideMode, 'attr') ?>" data-override-route="<?= esc($overrideRoute, 'attr') ?>">

    <div class="flex items-center gap-3 border-b border-gray-200 pb-4">

        <button type="button" data-gradebook-roster-back class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100" title="Back to Grades" aria-label="Back to Grades">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
        </button>

        <?= icon('grades', 'h-6 w-6') ?>

        <span class="text-xl font-bold text-brand">Gradebook</span>

        <span class="flex items-center gap-2 text-sm text-gray-500 ml-2" data-roster-save-status>
            <span class="h-2 w-2 rounded-full bg-emerald-500" data-roster-save-dot></span>
            <span data-roster-save-message>All changes saved</span>
        </span>

    </div>

    <p class="text-sm text-gray-500">
        Only manual items (<?= esc(implode(', ', array_map(static fn ($i) => $i['name'], $items)) ?: 'none yet') ?>) are editable here.
        Quiz and assignment scores come from Grade Work — the <?= $uses_periods ? 'period' : 'category' ?> columns below are read-only, for context. Click a final grade to <?= $isRequestMode ? 'request an override of it: the grade stays as calculated until the request is approved' : 'override it' ?>.
    </p>

    <div class="overflow-x-auto rounded-2xl border border-[#A9A9A9]/50">

        <table class="w-full text-sm">

            <thead>
                <tr class="border-b border-gray-200 bg-gray-50 text-left">
                    <th class="px-4 py-3 font-semibold text-gray-600">Student</th>
                    <?php foreach ($items as $item): ?>
                        <th class="px-4 py-3 font-semibold text-gray-600 whitespace-nowrap">
                            <?= esc($item['name']) ?>
                            <span class="font-normal text-[#767676]">/<?= $points($item['max_points']) ?></span>
                        </th>
                    <?php endforeach; ?>
                    <?php foreach ($breakdown_groups as $group): ?>
                        <th class="px-4 py-3 font-semibold text-[#767676] whitespace-nowrap" title="Read-only — from <?= $uses_periods ? 'category weights' : 'quiz/assignment scores' ?>">
                            <?= esc($group['name']) ?>
                        </th>
                    <?php endforeach; ?>
                    <th class="px-4 py-3 font-semibold text-gray-600">Final</th>
                    <th class="px-4 py-3 font-semibold text-gray-600">Remarks</th>
                </tr>
            </thead>

            <tbody>

                <?php if ($students === []): ?>
                    <tr><td colspan="<?= count($items) + count($breakdown_groups) + 3 ?>" class="px-4 py-8 text-center text-[#767676]">No students enrolled in this section.</td></tr>
                <?php endif; ?>

                <?php foreach ($students as $student): ?>

                    <tr class="border-b border-gray-100 last:border-0" data-roster-row data-student-id="<?= (int) $student['student_id'] ?>">

                        <td class="px-4 py-2 whitespace-nowrap">
                            <div class="font-semibold text-[#646464]"><?= esc($student['student_name']) ?></div>
                            <div class="text-xs text-[#767676]"><?= esc($student['student_number']) ?></div>
                        </td>

                        <?php foreach ($items as $item): ?>
                            <td class="px-4 py-2">
                                <input
                                    type="number" min="0" max="<?= $points($item['max_points']) ?>" step="0.5"
                                    value="<?= $student['scores'][$item['id']] ?? '' ?>"
                                    placeholder="—"
                                    data-manual-score-input data-item-id="<?= (int) $item['id'] ?>"
                                    class="w-20 rounded-lg border border-gray-300 px-2 py-1 text-sm outline-none transition focus:border-brand">
                            </td>
                        <?php endforeach; ?>

                        <?php foreach ($breakdown_groups as $group): ?>
                            <td class="px-4 py-2 text-gray-500">
                                <?= isset($student['breakdown'][(int) $group['id']]) && $student['breakdown'][(int) $group['id']] !== null
                                    ? $points($student['breakdown'][(int) $group['id']]) . '%'
                                    : '—' ?>
                            </td>
                        <?php endforeach; ?>

                        <?php $openRequest = $openRequests[(int) $student['student_id']] ?? null; ?>

                        <td class="px-4 py-2">
                            <button
                                type="button"
                                data-open-override
                                <?php if ($openRequest !== null): ?>
                                    data-request-id="<?= (int) $openRequest['id'] ?>"
                                    data-request-status="<?= esc($openRequest['status'], 'attr') ?>"
                                    data-request-percentage="<?= $openRequest['requested_percentage'] !== null ? $points($openRequest['requested_percentage']) : '' ?>"
                                    data-request-reason="<?= esc($openRequest['reason'], 'attr') ?>"
                                    data-request-comment="<?= esc((string) ($openRequest['return_comment'] ?? ''), 'attr') ?>"
                                    data-request-waiting="<?= esc($openRequest['waiting_for'], 'attr') ?>"
                                <?php endif; ?>
                                data-current-percentage="<?= $student['percentage'] !== null ? $points($student['percentage']) : '' ?>"
                                data-calculated-percentage="<?= $student['calculated_percentage'] !== null ? $points($student['calculated_percentage']) : '' ?>"
                                data-is-override="<?= $student['is_override'] ? '1' : '0' ?>"
                                data-override-reason="<?= esc($student['override_reason'] ?? '', 'attr') ?>"
                                data-student-name="<?= esc($student['student_name'], 'attr') ?>"
                                class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-sm font-bold transition hover:bg-gray-100 <?= $student['is_override'] ? 'text-[#946200]' : 'text-brand' ?>"
                                title="<?= $isRequestMode ? ($openRequest !== null ? 'See your override request' : 'Click to request an override') : 'Click to override' ?>">
                                <?= $student['percentage'] !== null ? $points($student['percentage']) . '%' : '—' ?>
                                <?php if ($student['is_override']): ?>
                                    <span class="rounded-full bg-[#FFF1D6] px-1.5 py-0.5 text-[10px] font-bold text-[#946200]">Override</span>
                                <?php endif; ?>
                            </button>

                            <?php if ($openRequest !== null): ?>
                                <p class="mt-1 text-[11px] font-semibold <?= $openRequest['returned'] ? 'text-[#C33939]' : 'text-[#946200]' ?>" data-request-state>
                                    <?= $openRequest['returned']
                                        ? 'Request sent back: edit or withdraw'
                                        : 'Override requested (' . ($openRequest['requested_percentage'] !== null ? $points($openRequest['requested_percentage']) . '%' : 'remove') . '): waiting for ' . esc($openRequest['waiting_for']) ?>
                                </p>
                            <?php endif; ?>
                        </td>

                        <td class="px-4 py-2">
                            <?php $isInc = $student['remarks'] === 'INC'; ?>
                            <button
                                type="button"
                                data-open-incomplete
                                data-student-id="<?= (int) $student['student_id'] ?>"
                                data-student-name="<?= esc($student['student_name'], 'attr') ?>"
                                data-is-inc="<?= $isInc ? '1' : '0' ?>"
                                data-inc-reason="<?= esc((string) ($student['inc_reason'] ?? ''), 'attr') ?>"
                                data-inc-until="<?= esc((string) ($student['inc_until'] ?? ''), 'attr') ?>"
                                title="<?= $isInc ? 'Incomplete: ' . esc((string) ($student['inc_reason'] ?? ''), 'attr') : 'Mark this grade Incomplete (INC)' ?>"
                                class="rounded-full px-2 py-0.5 text-xs font-bold transition hover:ring-2 hover:ring-brand/30 <?= match ($student['tag']) {
                                    'Passed', 'On track' => 'bg-[#E8F5EC] text-[#2E7D4F]',
                                    'Failed', 'At risk' => 'bg-[#FDEDEC] text-[#C33939]',
                                    'INC' => 'bg-[#FFF1D6] text-[#946200]',
                                    default => 'bg-gray-100 text-gray-500',
                                } ?>"><?= esc($student['tag']) ?></button>
                            <?php if ($isInc && !empty($student['inc_until'])): ?>
                                <p class="mt-1 text-[11px] text-[#946200]">Finish by <?= esc(date('M j, Y', strtotime((string) $student['inc_until']))) ?></p>
                            <?php endif; ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

<?= view('pages/courses/features/grades/partials/modals/grade-override', [], ['saveData' => false]) ?>
<?= view('pages/courses/features/grades/partials/modals/grade-incomplete', [], ['saveData' => false]) ?>
