<?php
/**
 * The student's "Group activity" card on an assignment (group activities, screens 3A, 3C, 3E). Always drawn
 * hidden on a student's assignment page; resources/js/pages/courses/groups/student-card.js shows it while it
 * loads, fills it from the my-group JSON, and removes it when the assignment is not a group activity.
 * The alert templates are the shared alert component, cloned by the script with its own text.
 *
 * @var array<string, mixed> $currentAssessment
 */
$alert = static fn (string $tone): string => '<template data-sg-alert="' . $tone . '">' . view('components/alert', ['tone' => $tone, 'text' => ''], ['saveData' => false]) . '</template>';
?>

<div class="flex flex-col gap-3" data-student-group data-assessment-id="<?= esc((string) $currentAssessment['id']) ?>" hidden>

    <div class="flex items-center gap-3">
        <?= icon('users', 'h-5 w-5') ?>
        <span class="font-bold text-lg text-brand">Group activity</span>
    </div>

    <div class="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-5" data-sg-body></div>

    <p class="sr-only" aria-live="polite" data-sg-live></p>

    <?= $alert('warning') ?>
    <?= $alert('info') ?>

    <?= view('components/confirm', [
        'id'           => 'sg-leave-confirm',
        'title'        => 'Leave this group?',
        'text'         => 'You will leave your group.',
        'action'       => '#',
        'confirmLabel' => 'Leave group',
        'tone'         => 'danger',
        'ajax'         => true,
    ], ['saveData' => false]) ?>

</div>
