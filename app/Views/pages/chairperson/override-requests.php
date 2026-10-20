<?php
/**
 * The chairperson's grade-override requests: what waits for them to endorse or send back, or
 * every request in the sub-departments they chair to read. A student's work and grades are not
 * shown, only the numbers asked for and the reason.
 *
 * @var string $view 'pending' or 'all'
 * @var list<array<string, mixed>> $requests
 */
use App\Services\Courses\Grades\OverrideRequestChain;

$base   = site_url('chairperson/override-requests');
$userId = (int) auth()->id();

$percent = static fn ($value): string => $value === null ? 'remove the override' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%';

$age = static function (string $at): string {
    $days = (int) floor((time() - strtotime($at)) / 86400);

    return $days < 1 ? 'today' : $days . ' d';
};

$statusBadge = static function (string $status): string {
    [$label, $tone] = match ($status) {
        OverrideRequestChain::AWAITING_CHAIR       => ['Waiting for the chairperson', 'warning'],
        OverrideRequestChain::AWAITING_DEAN        => ['Waiting for the dean', 'info'],
        OverrideRequestChain::AWAITING_INSTITUTION => ["Waiting for the institution's administrator", 'info'],
        OverrideRequestChain::RETURNED             => ['Sent back', 'warning'],
        OverrideRequestChain::APPROVED             => ['Approved', 'success'],
        OverrideRequestChain::REJECTED             => ['Rejected', 'danger'],
        default                                    => ['Withdrawn', 'neutral'],
    };

    return view('components/status-badge', ['label' => $label, 'tone' => $tone], ['saveData' => false]);
};

$waitsForMe = static fn (array $r): bool => $r['status'] === OverrideRequestChain::AWAITING_CHAIR && (int) $r['requested_by'] !== $userId;

$rows = [];

foreach ($requests as $r) {
    $actions = '';

    if ($waitsForMe($r)) {
        $actions = '<button type="button" data-dialog-open="send-back-' . (int) $r['id'] . '" class="rounded-full border border-gray-300 px-4 py-1.5 text-xs font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Send back…</button> '
            . '<button type="button" data-dialog-open="endorse-' . (int) $r['id'] . '" class="rounded-full bg-brand px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-dark">Endorse</button>';
    }

    $rows[] = [
        'id'    => (int) $r['id'],
        'cells' => [
            'student'   => trim((string) $r['student_name']) ?: 'A student',
            'course'    => $r['course_code'] . ' ' . $r['section_code'],
            'grade'     => ['html' => esc($r['calculated_percentage'] === null ? '—' : $percent($r['calculated_percentage'])) . ' → <strong>' . esc($percent($r['requested_percentage'])) . '</strong>'],
            'reason'    => ['html' => '<span class="line-clamp-2" title="' . esc((string) $r['reason'], 'attr') . '">' . esc((string) $r['reason']) . '</span>'],
            'requester' => trim((string) $r['requester_name']) ?: '—',
            'status'    => ['html' => $view === 'all' ? $statusBadge((string) $r['status']) : esc($age((string) $r['created_at']))],
        ],
        'actions' => $actions,
    ];
}
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', [
        'title'       => 'Override requests',
        'icon'        => 'tasks',
        'description' => 'Requests to change a final grade in the sub-departments you chair. Endorse one to send it on to the dean, or send it back with what needs to change.',
        'backUrl'     => site_url('chairperson/dashboard'),
        'backLabel'   => 'Back to dashboard',
    ], ['saveData' => false]) ?>

    <div class="flex items-center gap-1 text-xs" role="group" aria-label="Show">
        <?php foreach (['pending' => 'Waiting for you', 'all' => 'All requests'] as $mode => $label): ?>
            <a href="<?= esc($mode === 'pending' ? $base : $base . '?view=all') ?>"
               <?= $view === $mode ? 'aria-current="true"' : '' ?>
               class="rounded-full px-3 py-1.5 font-semibold <?= $view === $mode ? 'bg-brand text-white' : 'bg-gray-100 text-[#3D3D3D] hover:bg-gray-200' ?>">
                <?= esc($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?= view('components/data-table', [
        'caption'    => $view === 'all' ? 'Every grade-override request in your sub-departments' : 'Grade-override requests waiting for you',
        'columns'    => [
            ['key' => 'student', 'label' => 'Student'],
            ['key' => 'course', 'label' => 'Course'],
            ['key' => 'grade', 'label' => 'Calculated → requested'],
            ['key' => 'reason', 'label' => 'Reason'],
            ['key' => 'requester', 'label' => 'Requested by'],
            ['key' => 'status', 'label' => $view === 'all' ? 'Status' : 'Age'],
        ],
        'rows'       => $rows,
        'emptyTitle' => $view === 'all' ? 'No requests yet' : 'Nothing waiting for you',
        'emptyText'  => $view === 'all' ? 'Requests from instructors in your sub-departments will appear here.' : 'When an instructor asks to change a final grade, it appears here.',
        'emptyIcon'  => 'tasks',
    ], ['saveData' => false]) ?>

    <?php
    $buttons = static fn (string $label): string => '<div class="flex flex-wrap items-center justify-end gap-3">'
        . '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>'
        . '<button type="submit" data-dialog-label class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">' . esc($label) . '</button></div>';

    $field = static fn (array $f): string => view('components/form-field', $f, ['saveData' => false]);
    ?>

    <?php foreach (array_filter($requests, $waitsForMe) as $r): ?>
        <?php
        $id      = (int) $r['id'];
        $student = trim((string) $r['student_name']) ?: 'the student';
        $course  = $r['course_code'] . ' ' . $r['section_code'];
        $summary = esc($student) . ', ' . esc($course) . ': ' . esc($r['calculated_percentage'] === null ? '—' : $percent($r['calculated_percentage'])) . ' → ' . esc($percent($r['requested_percentage'])) . '.';

        $endorseBody = '<form method="post" action="' . esc(site_url("chairperson/override-requests/{$id}/endorse"), 'attr') . '" data-dialog-form class="flex flex-col gap-4">' . csrf_field()
            . '<p class="text-sm text-[#3D3D3D]">' . $summary . ' Endorsing sends it on to the dean to decide. The grade does not change yet.</p>'
            . $field(['name' => 'comment', 'label' => 'Comment', 'type' => 'textarea', 'maxlength' => 2000, 'help' => 'Optional. The requester and the dean can read it.'])
            . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>' . $buttons('Endorse') . '</form>';

        $sendBackBody = '<form method="post" action="' . esc(site_url("chairperson/override-requests/{$id}/send-back"), 'attr') . '" data-dialog-form class="flex flex-col gap-4">' . csrf_field()
            . '<p class="text-sm text-[#3D3D3D]">' . $summary . ' The requester will see your comment and can edit the request and send it again, or withdraw it.</p>'
            . $field(['name' => 'comment', 'label' => 'What needs to change', 'type' => 'textarea', 'required' => true, 'maxlength' => 2000])
            . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>' . $buttons('Send back') . '</form>';
        ?>
        <?= view('components/dialog', ['id' => "endorse-{$id}", 'title' => 'Endorse this request', 'body' => $endorseBody], ['saveData' => false]) ?>
        <?= view('components/dialog', ['id' => "send-back-{$id}", 'title' => 'Send this request back', 'body' => $sendBackBody], ['saveData' => false]) ?>
    <?php endforeach; ?>

</div>

<?= $this->endSection() ?>
