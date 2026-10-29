<?php
/**
 * Follow-up requests. The dean sees the ones they sent; a chairperson has two boxes (the ones the dean sent
 * them, and the ones they sent to instructors); an instructor sees the ones sent to them. Each request opens a
 * dialog with the thread, a reply form, "Mark as done" and, for a chairperson holding a dean's request, "Pass
 * on to an instructor". A new request starts from a course: the button asks which, then the form opens for it,
 * as it does straight away when an attention item links here with the course (and student) already chosen.
 *
 * @var string $area          dean|chairperson|instructor
 * @var list<string> $boxes
 * @var string $box           received|sent
 * @var string $tab           open|overdue|done
 * @var array{rows: list<array<string, mixed>>, counts: array{open: int, overdue: int, done: int}} $list
 * @var list<array{id: int, label: string}> $offerings
 * @var list<array{id: int, name: string, role: string}> $recipients
 * @var array<string, mixed>|null $prefill
 * @var array<int, list<array{id: int, name: string, role: string}>> $passTo
 */
use App\Services\Courses\FollowUpRules;

$base      = site_url($area . '/follow-ups');
$sent      = $box === 'sent';
$noun      = $area === 'instructor' ? 'requests' : 'follow-ups';
$boxLabels = ['received' => $area === 'instructor' ? 'Sent to you' : 'From the dean', 'sent' => 'Sent by you'];

$link = static fn (string $toBox, string $toTab): string => $base . '?' . http_build_query(['box' => $toBox, 'tab' => $toTab]);

$when = static fn (?string $at): string => $at === null || $at === '' ? '—' : date('M j, Y', strtotime($at));

$field = static fn (array $f): string => view('components/form-field', $f, ['saveData' => false]);

$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2';
$ghost   = 'rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50';

$rows = [];

foreach ($list['rows'] as $r) {
    $id      = (int) $r['id'];
    $with    = $sent ? $r['recipient_name'] : $r['sender_name'];
    $withRole = ucfirst((string) ($sent ? $r['recipient_role'] : $r['sender_role']));

    $status = $r['status'] === 'done'
        ? view('components/status-badge', ['label' => 'Done', 'tone' => 'success', 'icon' => 'check'], ['saveData' => false])
        : ($r['overdue']
            ? view('components/status-badge', ['label' => 'Overdue', 'tone' => 'danger', 'icon' => 'attention'], ['saveData' => false])
            : view('components/status-badge', ['label' => 'Open', 'tone' => 'info'], ['saveData' => false]));

    $rows[] = [
        'id'      => $id,
        'cells'   => [
            'subject' => ['html' => '<span class="font-semibold text-[#1A1C1E]">' . esc($r['title']) . '</span><span class="block text-xs text-[#646464]">' . esc($r['label']) . ($r['student_name'] ? ' · ' . esc($r['student_name']) : '') . '</span>'],
            'with'    => ['html' => esc($with ?: '—') . '<span class="block text-xs text-[#646464]">' . esc($withRole) . '</span>'],
            'due'     => ['html' => esc($when($r['due_on'])) . ($r['due_date'] === null ? '<span class="block text-xs text-[#646464]">no date set</span>' : '')],
            'status'  => ['html' => $status . ($r['passed_to'] ? '<span class="mt-1 block text-xs text-[#646464]">Passed to ' . esc($r['passed_to']['name']) . ($r['passed_to']['status'] === 'done' ? ' (done)' : '') . '</span>' : '')],
        ],
        'actions' => '<button type="button" data-dialog-open="thread-' . $id . '" class="rounded-full bg-brand px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-dark">Open</button>',
    ];
}

$columns = [
    ['key' => 'subject', 'label' => 'Subject'],
    ['key' => 'with', 'label' => $sent ? 'Sent to' : 'From'],
    ['key' => 'due', 'label' => 'Due'],
    ['key' => 'status', 'label' => 'Status'],
];

$actions = '';

if ($canSend && ! $loadError) {
    $actions = '<button type="button" data-dialog-open="follow-up-pick" class="' . esc($primary, 'attr') . '">New follow-up</button>';
}

$tabs = [];

foreach ([['open', 'Open'], ['overdue', 'Overdue'], ['done', 'Done']] as [$key, $label]) {
    $tabs[] = ['label' => $label, 'href' => $link($box, $key), 'active' => $tab === $key, 'count' => $list['counts'][$key]];
}
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url($area . '/dashboard'), 'backLabel' => 'Back to dashboard'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => $area === 'instructor' ? 'Requests' : 'Follow-ups',
        'icon'        => 'inbox',
        'description' => match ($area) {
            'dean'        => 'Ask the chairperson or instructor responsible to act on something that needs attention in your department.',
            'chairperson' => 'Requests from the dean to you, and the follow-ups you send to instructors in your sub-departments.',
            default       => 'Things your dean or chairperson asked you to look at. Reply, and mark each one done when it is.',
        },
        'actions'     => $actions,
    ], ['saveData' => false]) ?>

    <?php if (count($boxes) > 1): ?>
        <div class="flex items-center gap-1 text-xs" role="group" aria-label="Show">
            <?php foreach ($boxes as $name): ?>
                <a href="<?= esc($link($name, 'open')) ?>" <?= $box === $name ? 'aria-current="true"' : '' ?> class="rounded-full px-3 py-1.5 font-semibold <?= $box === $name ? 'bg-brand text-white' : 'bg-gray-100 text-[#3D3D3D] hover:bg-gray-200' ?>">
                    <?= esc($boxLabels[$name]) ?><?= $name === 'received' ? ' (' . (int) $received . ' open)' : '' ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?= view('components/tabs', ['tabs' => $tabs, 'ariaLabel' => 'Status'], ['saveData' => false]) ?>

    <?php if ($loadError): ?>

        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert" data-load-error>
            <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
            <span class="text-sm font-semibold text-[#1A1C1E]">We could not load the <?= esc($noun) ?></span>
            <span class="text-xs text-[#646464]">Something went wrong on our side. Nothing has been lost.</span>
            <a href="<?= esc($base) ?>" class="<?= esc($primary, 'attr') ?>">Try again</a>
        </div>

    <?php else: ?>

        <?= view('components/data-table', [
            'caption'    => $sent ? 'Follow-ups you sent' : 'Follow-ups sent to you',
            'columns'    => $columns,
            'rows'       => $rows,
            'emptyTitle' => match ($tab) {
                'overdue' => 'Nothing is overdue',
                'done'    => 'Nothing is done yet',
                default   => $sent ? 'No open follow-ups' : 'Nothing is waiting for you',
            },
            'emptyText'  => $sent
                ? 'Use “New follow-up” here, or the Follow-up button next to a blocked sign-off or an at-risk student.'
                : 'When someone asks you to look at something, it appears here.',
            'emptyIcon'  => 'inbox',
        ], ['saveData' => false]) ?>

        <?php foreach ($list['rows'] as $r): ?>
            <?php
            $id     = (int) $r['id'];
            $isOpen = $r['status'] === 'open';

            $thread = '';

            foreach ($r['replies'] as $reply) {
                $thread .= '<li class="rounded-xl bg-gray-50 px-3 py-2 text-sm"><span class="font-semibold text-[#1A1C1E]">' . esc($reply['name'] ?: 'Someone') . '</span> <span class="text-xs text-[#646464]">' . esc(date('M j, g:i a', strtotime($reply['created_at']))) . '</span><p class="mt-0.5 whitespace-pre-line text-[#3D3D3D]">' . esc($reply['body']) . '</p></li>';
            }

            $body = '<div class="flex flex-col gap-4">'
                . '<dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">'
                . '<dt class="text-[#646464]">From</dt><dd>' . esc($r['sender_name'] ?: '—') . ' (' . esc((string) $r['sender_role']) . ')</dd>'
                . '<dt class="text-[#646464]">To</dt><dd>' . esc($r['recipient_name'] ?: '—') . ' (' . esc((string) $r['recipient_role']) . ')</dd>'
                . '<dt class="text-[#646464]">About</dt><dd><a class="font-semibold text-brand hover:underline" href="' . esc(site_url($area === 'instructor' ? "instructor/courses/{$r['offering_id']}/{$r['slug']}" : "{$area}/courses/{$r['offering_id']}/{$r['slug']}")) . '">' . esc($r['label']) . '</a>' . ($r['student_name'] ? ' · ' . esc($r['student_name']) : '') . '</dd>'
                . '<dt class="text-[#646464]">Kind</dt><dd>' . esc(FollowUpRules::REASONS[$r['reason']] ?? 'Something else') . '</dd>'
                . '<dt class="text-[#646464]">Due</dt><dd>' . esc($when($r['due_on'])) . ($r['overdue'] ? ' <strong class="text-[#C33939]">(overdue)</strong>' : '') . '</dd>'
                . '</dl>'
                . '<p class="whitespace-pre-line rounded-xl bg-gray-50 px-3 py-2 text-sm text-[#3D3D3D]">' . esc($r['message']) . '</p>'
                . ($r['passed_to'] ? '<p class="rounded-xl bg-[#E0E5F2] px-3 py-2 text-xs font-medium text-brand-dark">Passed on to ' . esc($r['passed_to']['name']) . ($r['passed_to']['status'] === 'done' ? ', who has finished it.' : '. It closes when they are done.') . '</p>' : '')
                . ($thread !== '' ? '<ul class="flex flex-col gap-2" aria-label="Replies">' . $thread . '</ul>' : '');

            if ($isOpen) {
                $body .= '<form method="post" action="' . esc(site_url("{$area}/follow-ups/{$id}/reply"), 'attr') . '" data-dialog-form class="flex flex-col gap-3 border-t border-gray-100 pt-4">' . csrf_field()
                    . $field(['name' => 'body', 'label' => 'Reply', 'type' => 'textarea', 'maxlength' => 2000])
                    . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>'
                    . '<div class="flex justify-end"><button type="submit" data-dialog-label class="' . esc($primary, 'attr') . '">Send reply</button></div></form>';

                if (! empty($passTo[$id])) {
                    $choices = ['' => 'Choose an instructor'];

                    foreach ($passTo[$id] as $person) {
                        $choices[(string) $person['id']] = $person['name'];
                    }

                    $body .= '<form method="post" action="' . esc(site_url("chairperson/follow-ups/{$id}/pass-down"), 'attr') . '" data-dialog-form class="flex flex-col gap-3 border-t border-gray-100 pt-4">' . csrf_field()
                        . $field(['name' => 'instructor_id', 'label' => 'Pass on to an instructor', 'type' => 'select', 'options' => $choices, 'help' => 'The dean still sees it, and it closes when the instructor is done.'])
                        . $field(['name' => 'note', 'label' => 'Add a note (optional)', 'type' => 'textarea', 'maxlength' => 1000])
                        . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>'
                        . '<div class="flex justify-end"><button type="submit" data-dialog-label class="' . esc($ghost, 'attr') . '">Pass on</button></div></form>';
                }

                $body .= '<form method="post" action="' . esc(site_url("{$area}/follow-ups/{$id}/done"), 'attr') . '" data-dialog-form class="flex flex-col gap-2 border-t border-gray-100 pt-4">' . csrf_field()
                    . '<p class="text-sm text-[#3D3D3D]">' . esc($sent ? 'Mark this done if you no longer need an answer. ' : 'Mark this done when you have dealt with it. ') . esc($r['sender_name'] ?: 'The sender') . ' is told.</p>'
                    . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>'
                    . '<div class="flex justify-end"><button type="submit" data-dialog-label class="' . esc($ghost, 'attr') . '">Mark as done</button></div></form>';
            }

            $body .= '</div>';
            ?>
            <?= view('components/dialog', ['id' => "thread-{$id}", 'title' => $r['title'], 'body' => $body, 'size' => 'lg'], ['saveData' => false]) ?>
        <?php endforeach; ?>

        <?php if ($canSend): ?>
            <?php
            $courseChoices = ['' => 'Choose a course'];

            foreach ($offerings as $offering) {
                $courseChoices[(string) $offering['id']] = $offering['label'];
            }

            // Step one: which course. A plain GET, so the next step opens for exactly that course.
            $pick = '<form method="get" action="' . esc($base, 'attr') . '" class="flex flex-col gap-3">'
                . '<input type="hidden" name="box" value="' . esc($box, 'attr') . '">'
                . $field(['name' => 'offering', 'label' => 'Course', 'type' => 'select', 'options' => $courseChoices, 'required' => true, 'help' => 'Then you choose whom to ask: the instructors of the course' . ($area === 'dean' ? ' and the chairperson of its sub-department.' : '.')])
                . '<input type="hidden" name="reason" value="other">'
                . '<div class="flex justify-end gap-3"><button type="button" data-dialog-close class="' . esc($ghost, 'attr') . '">Cancel</button><button type="submit" class="' . esc($primary, 'attr') . '">Continue</button></div></form>';
            ?>
            <?= view('components/dialog', ['id' => 'follow-up-pick', 'title' => 'New follow-up', 'body' => $pick, 'size' => 'sm'], ['saveData' => false]) ?>

            <?php if ($prefill !== null): ?>
                <?php
                $people = ['' => $recipients === [] ? 'Nobody to ask about this course' : 'Choose who to ask'];

                foreach ($recipients as $person) {
                    $people[(string) $person['id']] = $person['name'] . ' (' . $person['role'] . ')';
                }

                $reasons = FollowUpRules::REASONS;

                $new = '<form method="post" action="' . esc(site_url($area . '/follow-ups'), 'attr') . '" data-dialog-form class="flex flex-col gap-3">' . csrf_field()
                    . '<input type="hidden" name="offering_id" value="' . (int) $prefill['offering'] . '">'
                    . '<input type="hidden" name="student_id" value="' . (int) $prefill['student'] . '">'
                    . '<p class="text-sm text-[#3D3D3D]">About <strong>' . esc($prefill['label']) . '</strong>' . ($prefill['student_name'] !== '' ? ' and ' . esc($prefill['student_name']) : '') . '.</p>'
                    . $field(['name' => 'recipient_id', 'label' => 'Ask', 'type' => 'select', 'options' => $people, 'required' => true])
                    . $field(['name' => 'reason', 'label' => 'What is it about', 'type' => 'select', 'options' => $reasons, 'value' => $prefill['reason']])
                    . $field(['name' => 'title', 'label' => 'Subject', 'value' => $prefill['title'], 'maxlength' => 200, 'required' => true])
                    . $field(['name' => 'message', 'label' => 'What are you asking', 'type' => 'textarea', 'maxlength' => 2000, 'required' => true])
                    . $field(['name' => 'due_date', 'label' => 'Due date (optional)', 'type' => 'date', 'help' => 'Without one it counts as overdue after ' . FollowUpRules::DEFAULT_DUE_DAYS . ' days.'])
                    . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>'
                    . '<div class="flex justify-end gap-3"><button type="button" data-dialog-close class="' . esc($ghost, 'attr') . '">Cancel</button><button type="submit" data-dialog-label class="' . esc($primary, 'attr') . '">Send follow-up</button></div></form>';
                ?>
                <?= view('components/dialog', ['id' => 'follow-up-new', 'title' => 'New follow-up', 'body' => $new, 'size' => 'lg', 'autoOpen' => true], ['saveData' => false]) ?>
            <?php endif; ?>
        <?php endif; ?>

    <?php endif; ?>

</div>

</div>

<?= $this->endSection() ?>
