<?php

/*
|--------------------------------------------------------------------------
| Due date · points · duration · attempts chips for a quiz/assignment
| learning item. Used by the item's view and editor.
|--------------------------------------------------------------------------
*/

$summaryIsQuiz = strtolower($currentAssessment['assessment_type'] ?? '') === 'quiz';

$summaryAttemptLimit = max(1, (int) ($currentAssessment['attempt_limit'] ?? 1));

$summaryChips = [];

if (!empty($currentAssessment['due_date'])) {
    $summaryChips[] = ['icon' => 'schedule', 'text' => 'Due ' . date('M j, Y g:i A', strtotime($currentAssessment['due_date']))];
}

$summaryChips[] = ['icon' => 'points', 'text' => (int) ($currentAssessment['total_points'] ?? 0) . ' points'];

if ($summaryIsQuiz && !empty($currentAssessment['duration'])) {
    $summaryChips[] = ['icon' => 'time', 'text' => (int) $currentAssessment['duration'] . ' min'];
}

$summaryChips[] = [
    'icon' => 'start',
    'text' => $summaryAttemptLimit . ($summaryIsQuiz ? ' attempt' : ' submission') . ($summaryAttemptLimit === 1 ? '' : 's'),
];

if (!$summaryIsQuiz && !empty($currentAssessment['due_date'])) {
    $summaryChips[] = [
        'icon' => 'attention',
        'text' => !empty($currentSettings['allow_late_submission']) ? 'Late submissions accepted' : 'No late submissions',
    ];
}
?>

<div class="flex flex-wrap items-center gap-2">

    <?php foreach ($summaryChips as $chip): ?>

        <span class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-[#646464]">
            <?= icon($chip['icon'], 'h-3.5 w-3.5 opacity-60') ?>
            <?= esc($chip['text']) ?>
        </span>

    <?php endforeach; ?>

</div>
