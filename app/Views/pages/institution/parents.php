<?php
/**
 * Parents and the students they are linked to.
 *
 * @var list<array<string, mixed>> $parentRows
 * @var int                        $parentTotal
 * @var int                        $parentPages
 * @var int                        $parentPage
 * @var array<string, mixed>       $parentFilters
 * @var int                        $parentOpen      open invitations, for the People tabs
 * @var bool                       $parentCanEdit
 */
$base    = 'institution/people/parents';
$link    = 'text-sm font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded';
$filtered = $parentFilters['q'] !== '';
$query    = array_filter(['q' => $parentFilters['q']], static fn ($v): bool => $v !== '');
$url      = static fn (string $path): string => esc(site_url($base . $path));

$rows = [];

foreach ($parentRows as $p) {
    $chips = '';

    foreach ($p['students'] as $s) {
        $chips .= '<span class="inline-flex items-center gap-2 rounded-full bg-brand-tint px-3 py-1 text-xs" data-linked-student="' . esc((string) $s['link_id']) . '">' . esc($s['name']);

        if ($parentCanEdit) {
            $chips .= ' <button type="button" class="rounded font-semibold text-[#C33939] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C33939]/50"'
                . ' aria-label="Unlink ' . esc($s['name']) . ' from ' . esc($p['name']) . '"'
                . ' data-dialog-open="parent-confirm" data-dialog-title="Unlink ' . esc($s['name']) . '?"'
                . ' data-dialog-text="' . esc($p['name']) . ' will no longer be linked to this student. Nothing else about either of them changes."'
                . ' data-dialog-label="Unlink" data-dialog-action="' . $url('/links/' . $s['link_id'] . '/remove') . '">Unlink</button>';
        }

        $chips .= '</span> ';
    }

    if ($p['students'] === []) {
        $chips = '<span class="text-xs font-semibold text-[#946200]" data-no-students>No student linked</span>';
    }

    $rows[] = [
        'id'      => $p['id'],
        'label'   => $p['name'],
        'cells'   => [
            'name'     => ['html' => '<span class="font-medium">' . esc($p['name']) . '</span><span class="block text-xs text-[#646464]">' . esc($p['email']) . '</span>'],
            'students' => ['html' => $chips],
            'status'   => ['html' => view('components/status-badge', ['label' => $p['active'] ? 'Active' : 'Deactivated', 'tone' => $p['active'] ? 'success' : 'neutral'], ['saveData' => false])],
        ],
        'actions' => $parentCanEdit
            ? '<button type="button" class="' . $link . '" data-dialog-open="parent-link" data-dialog-title="Link students to ' . esc($p['name']) . '" data-dialog-label="Link students" data-dialog-action="' . $url('/' . $p['id'] . '/students') . '">Link students</button>'
            : '',
    ];
}
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('institution/dashboard'), 'backLabel' => 'Back to home'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Parents',
        'icon'        => 'user',
        'description' => 'Which students each parent account is responsible for.',
    ], ['saveData' => false]) ?>

    <?php if (! $parentCanEdit): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <?= view('components/tabs', ['ariaLabel' => 'People', 'tabs' => [
        ['label' => 'People', 'href' => site_url('institution/people')],
        ['label' => 'Invitations', 'href' => site_url('institution/people') . '?view=invitations', 'count' => $parentOpen],
        ['label' => 'Parents', 'href' => site_url($base), 'active' => true],
    ]], ['saveData' => false]) ?>

    <?= view('components/toolbar', [
        'surface'  => 'tinted', // on the page's white panel a white card would not stand out
        'action'   => site_url($base),
        'filtered' => $filtered,
        'fields'   => [
            ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'placeholder' => 'Parent name or email', 'value' => $parentFilters['q'], 'span' => 'lg:col-span-3'],
        ],
    ], ['saveData' => false]) ?>

    <p class="text-sm text-[#646464]" aria-live="polite" data-parents-total>
        <?= esc(number_format($parentTotal)) ?> <?= $parentTotal === 1 ? 'parent' : 'parents' ?><?= $filtered ? ' match' : '' ?>
    </p>

    <?= view('components/data-table', [
        'caption'    => 'Parents',
        'columns'    => [
            ['key' => 'name', 'label' => 'Parent'],
            ['key' => 'students', 'label' => 'Linked students'],
            ['key' => 'status', 'label' => 'Status'],
        ],
        'rows'       => $rows,
        'emptyIcon'  => 'user',
        'emptyTitle' => $filtered ? 'No parents match this search' : 'No parent accounts yet',
        'emptyText'  => $filtered ? 'Try a different name or email.' : 'Invite someone with the parent role on the People page, then link them to their students here.',
    ], ['saveData' => false]) ?>

    <?= view('components/pagination', ['page' => $parentPage, 'pages' => $parentPages, 'total' => $parentTotal, 'baseUrl' => site_url($base), 'query' => $query], ['saveData' => false]) ?>

</div>

</div>

<?php if ($parentCanEdit): ?>
    <?php
    $buttons = '<div class="flex flex-wrap items-center justify-end gap-3">'
        . '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>'
        . '<button type="submit" data-dialog-label class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">Link students</button></div>';

    $form = '<form method="post" action="" data-dialog-form class="flex flex-col gap-4">' . csrf_field()
        . view('components/form-field', ['name' => 'students', 'label' => 'Student numbers or email addresses', 'type' => 'textarea', 'required' => true, 'help' => 'One per line, or separated by commas. They must be students of your institution.'], ['saveData' => false])
        . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>' . $buttons . '</form>';
    ?>

    <?= view('components/dialog', ['id' => 'parent-link', 'title' => 'Link students', 'body' => $form], ['saveData' => false]) ?>

    <?= view('components/confirm', [
        'id'           => 'parent-confirm',
        'title'        => 'Are you sure?',
        'text'         => 'This changes the link.',
        'action'       => site_url($base),
        'confirmLabel' => 'Confirm',
        'tone'         => 'danger',
        'ajax'         => true,
    ], ['saveData' => false]) ?>
<?php endif; ?>

<?= $this->endSection() ?>
