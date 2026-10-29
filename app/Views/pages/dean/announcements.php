<?php
/**
 * The dean's announcements to the department. "Showing" are the ones people can read now; "Ended" are the
 * ones the dean ended or whose last day has passed, kept as history. A new one is aimed at the whole
 * department, a sub-department or a program, and at everyone, staff or students; editing changes the
 * wording and the last day but never who it is for.
 *
 * @var string $tab
 * @var array{rows: list<array<string, mixed>>, counts: array{active: int, ended: int}} $list
 * @var array<string, string> $scopes
 */
use App\Services\Courses\DepartmentAnnouncementService;

$base = site_url('dean/announcements');

$field = static fn (array $f): string => view('components/form-field', $f, ['saveData' => false]);

$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2';
$ghost   = 'rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50';
$small   = 'rounded-full border border-gray-300 px-4 py-1.5 text-xs font-semibold text-[#3D3D3D] transition hover:bg-gray-50';

$when = static fn (?string $at): string => $at === null || $at === '' ? '' : date('M j, Y', strtotime($at));

$tabs = [
    ['label' => 'Showing', 'href' => $base, 'active' => $tab === 'active', 'count' => $list['counts']['active']],
    ['label' => 'Ended', 'href' => $base . '?tab=ended', 'active' => $tab === 'ended', 'count' => $list['counts']['ended']],
];

$rows = [];

foreach ($list['rows'] as $a) {
    $id = (int) $a['id'];

    $until = $a['status'] === 'ended'
        ? 'Ended ' . $when($a['ended_at'])
        : ($a['ends_on'] ? 'Until ' . $when($a['ends_on']) : 'No end date');

    $actions = '';

    if ($a['status'] === 'active' && $a['showing']) {
        $actions = '<div class="flex items-center gap-2"><button type="button" data-dialog-open="edit-' . $id . '" class="' . esc($small, 'attr') . '">Edit</button>'
            . '<button type="button" data-dialog-open="end-' . $id . '" class="' . esc($small, 'attr') . '">End</button></div>';
    }

    $rows[] = [
        'id'      => $id,
        'cells'   => [
            'title' => ['html' => '<span class="font-semibold text-[#1A1C1E]">' . esc($a['title']) . '</span><span class="mt-0.5 line-clamp-2 block text-xs text-[#646464]">' . esc($a['message']) . '</span>'],
            'for'   => ['html' => esc($a['scope_label']) . '<span class="block text-xs text-[#646464]">' . esc($a['audience_label']) . '</span>'],
            'reach' => $a['reach'] === null ? '—' : number_format((int) $a['reach']) . ($a['reach'] === 1 ? ' person' : ' people'),
            'until' => $until,
        ],
        'actions' => $actions,
    ];
}

$columns = [
    ['key' => 'title', 'label' => 'Announcement'],
    ['key' => 'for', 'label' => 'For'],
    ['key' => 'reach', 'label' => 'Reaches'],
    ['key' => 'until', 'label' => 'Shown'],
];

$audiences = DepartmentAnnouncementService::AUDIENCES;
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('dean/dashboard'), 'backLabel' => 'Back to dashboard'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Announcements',
        'icon'        => 'announcement',
        'description' => 'Tell your department, a sub-department or a program something. The people it is for see it in their Announcements and are notified.',
        'actions'     => $loadError ? '' : '<button type="button" data-dialog-open="announcement-new" class="' . esc($primary, 'attr') . '">New announcement</button>',
    ], ['saveData' => false]) ?>

    <?= view('components/tabs', ['tabs' => $tabs, 'ariaLabel' => 'Showing or ended'], ['saveData' => false]) ?>

    <?php if ($loadError): ?>

        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert" data-load-error>
            <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
            <span class="text-sm font-semibold text-[#1A1C1E]">We could not load the announcements</span>
            <span class="text-xs text-[#646464]">Something went wrong on our side. Nothing has been lost.</span>
            <a href="<?= esc($base) ?>" class="<?= esc($primary, 'attr') ?>">Try again</a>
        </div>

    <?php else: ?>

        <?= view('components/data-table', [
            'caption'    => $tab === 'ended' ? 'Announcements that ended' : 'Announcements people can read now',
            'columns'    => $columns,
            'rows'       => $rows,
            'emptyTitle' => $tab === 'ended' ? 'Nothing has ended yet' : 'No announcements showing',
            'emptyText'  => $tab === 'ended' ? 'Announcements you end, or whose last day has passed, are kept here.' : 'Use “New announcement” to tell your department something.',
            'emptyIcon'  => 'announcement',
        ], ['saveData' => false]) ?>

        <?php
        $new = '<form method="post" action="' . esc($base, 'attr') . '" data-dialog-form class="flex flex-col gap-3">' . csrf_field()
            . $field(['name' => 'scope', 'label' => 'Who is it for', 'type' => 'select', 'options' => $scopes, 'required' => true, 'help' => 'The whole department, one sub-department or one program.'])
            . $field(['name' => 'audience', 'label' => 'Who should read it', 'type' => 'select', 'options' => $audiences, 'value' => 'everyone'])
            . $field(['name' => 'title', 'label' => 'Title', 'maxlength' => DepartmentAnnouncementService::MAX_TITLE, 'required' => true])
            . $field(['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'maxlength' => DepartmentAnnouncementService::MAX_MESSAGE, 'required' => true, 'help' => 'Plain text.'])
            . $field(['name' => 'ends_on', 'label' => 'Last day it is shown (optional)', 'type' => 'date', 'help' => 'Without one it stays until you end it.'])
            . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>'
            . '<div class="flex justify-end gap-3"><button type="button" data-dialog-close class="' . esc($ghost, 'attr') . '">Cancel</button><button type="submit" data-dialog-label class="' . esc($primary, 'attr') . '">Post announcement</button></div></form>';
        ?>
        <?= view('components/dialog', ['id' => 'announcement-new', 'title' => 'New announcement', 'body' => $new, 'size' => 'lg'], ['saveData' => false]) ?>

        <?php foreach ($list['rows'] as $a): ?>
            <?php if ($a['status'] !== 'active' || ! $a['showing']) { continue; } ?>
            <?php
            $id = (int) $a['id'];

            $edit = '<form method="post" action="' . esc("{$base}/{$id}", 'attr') . '" data-dialog-form class="flex flex-col gap-3">' . csrf_field()
                . '<p class="rounded-xl bg-gray-50 px-3 py-2 text-xs text-[#646464]">For ' . esc($a['scope_label']) . ' · ' . esc($a['audience_label']) . '. Who it is for cannot change; end it and post a new one for someone else.</p>'
                . $field(['name' => 'title', 'label' => 'Title', 'value' => $a['title'], 'maxlength' => DepartmentAnnouncementService::MAX_TITLE, 'required' => true])
                . $field(['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'value' => $a['message'], 'maxlength' => DepartmentAnnouncementService::MAX_MESSAGE, 'required' => true])
                . $field(['name' => 'ends_on', 'label' => 'Last day it is shown (optional)', 'type' => 'date', 'value' => (string) ($a['ends_on'] ?? '')])
                . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>'
                . '<div class="flex justify-end gap-3"><button type="button" data-dialog-close class="' . esc($ghost, 'attr') . '">Cancel</button><button type="submit" data-dialog-label class="' . esc($primary, 'attr') . '">Save changes</button></div></form>';
            ?>
            <?= view('components/dialog', ['id' => "edit-{$id}", 'title' => 'Edit announcement', 'body' => $edit, 'size' => 'lg'], ['saveData' => false]) ?>

            <?= view('components/confirm', [
                'id'           => "end-{$id}",
                'title'        => 'End this announcement?',
                'text'         => '“' . $a['title'] . '” stops showing to ' . ($a['reach'] === null ? 'everyone it was for' : number_format((int) $a['reach']) . ($a['reach'] === 1 ? ' person' : ' people')) . ' at once. It stays in your Ended list.',
                'action'       => "{$base}/{$id}/end",
                'confirmLabel' => 'End announcement',
                'tone'         => 'danger',
                'ajax'         => true,
            ], ['saveData' => false]) ?>
        <?php endforeach; ?>

    <?php endif; ?>

</div>

</div>

<?= $this->endSection() ?>
