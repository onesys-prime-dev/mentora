<?php
/**
 * The component gallery. See App\Controllers\UiKitController.
 *
 * @var string $sort
 * @var string $dir
 */
$people = [
    ['id' => 1, 'name' => 'Ann Lee',  'role' => 'Student',    'status' => 'Active'],
    ['id' => 2, 'name' => 'Ben Ng',   'role' => 'Instructor', 'status' => 'Invited'],
    ['id' => 3, 'name' => 'Cy Ho',    'role' => 'Student',    'status' => 'Deactivated'],
];

$tones = ['Active' => 'success', 'Invited' => 'info', 'Deactivated' => 'danger'];

$rows = [];

foreach ($people as $person) {
    $rows[] = [
        'id'      => $person['id'],
        'label'   => $person['name'],
        'cells'   => [
            'name'   => $person['name'],
            'role'   => $person['role'],
            'status' => ['html' => view('components/status-badge', ['label' => $person['status'], 'tone' => $tones[$person['status']]], ['saveData' => false])],
        ],
        'actions' => '<button type="button" data-dialog-open="kit-confirm" class="text-sm font-semibold text-[#C33939] hover:underline">Deactivate</button>',
    ];
}

$sortUrl = static fn (string $column, string $next): string => site_url('ui-kit') . '?' . http_build_query(['sort' => $column, 'dir' => $next]);

$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$second  = 'rounded-full border border-brand px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$danger  = 'rounded-full bg-[#C33939] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#A82E2E] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C33939]/50';
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-10 py-3">

    <?= view('components/page-head', [
        'title'       => 'Component gallery',
        'icon'        => 'apps',
        'description' => 'Every shared interface component, in every state. Build screens from these; do not invent new ones.',
        'backUrl'     => site_url('/'),
        'backLabel'   => 'Back',
        'actions'     => '<button type="button" class="' . $primary . '">Primary action</button><button type="button" class="' . $second . '">Secondary</button>',
    ], ['saveData' => false]) ?>

    <section class="flex flex-col gap-3" data-kit="buttons">
        <h2 class="text-lg font-bold text-brand">Buttons</h2>
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" class="<?= $primary ?>">Primary</button>
            <button type="button" class="<?= $second ?>">Secondary</button>
            <button type="button" class="<?= $danger ?>">Destructive</button>
            <button type="button" class="<?= $primary ?> opacity-50" disabled>Disabled</button>
            <a class="text-sm font-semibold text-brand hover:underline" href="#">Text link</a>
        </div>
        <p class="text-xs text-[#646464]">One primary action per view. Destructive actions are confirmed first.</p>
    </section>

    <section class="flex flex-col gap-3" data-kit="alerts">
        <h2 class="text-lg font-bold text-brand">Alerts and toasts</h2>
        <?php foreach (['success' => 'Saved. Ann Lee was invited.', 'info' => 'Invitations expire after 7 days.', 'warning' => 'The term ends in 9 days.', 'danger' => 'The import could not be read. Nothing was changed.'] as $tone => $text): ?>
            <?= view('components/alert', ['tone' => $tone, 'text' => $text, 'title' => ucfirst($tone)], ['saveData' => false]) ?>
        <?php endforeach; ?>
        <div class="flex flex-wrap gap-3">
            <button type="button" class="<?= $second ?>" data-kit-toast="success">Show a success toast</button>
            <button type="button" class="<?= $second ?>" data-kit-toast="danger">Show an error toast</button>
        </div>
    </section>

    <section class="flex flex-col gap-3" data-kit="status">
        <h2 class="text-lg font-bold text-brand">Status and numbers</h2>
        <div class="flex flex-wrap gap-2">
            <?php foreach (['success' => 'Active', 'warning' => 'Expiring', 'danger' => 'Read-only', 'info' => 'Invited', 'neutral' => 'Draft'] as $tone => $label): ?>
                <?= view('components/status-badge', ['label' => $label, 'tone' => $tone], ['saveData' => false]) ?>
            <?php endforeach; ?>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <?= view('components/metric-card', ['label' => 'People', 'value' => '512', 'icon' => 'user', 'detail' => '410 students'], ['saveData' => false]) ?>
            <?= view('components/metric-card', ['label' => 'Offerings', 'value' => '42', 'icon' => 'hat', 'tone' => 'warning', 'detail' => '3 have no instructor'], ['saveData' => false]) ?>
            <?= view('components/metric-card', ['label' => 'Seats', 'value' => '100%', 'icon' => 'attention', 'tone' => 'danger', 'detail' => 'Limit reached'], ['saveData' => false]) ?>
            <?= view('components/metric-card', ['label' => 'Completed', 'value' => '94%', 'icon' => 'check', 'tone' => 'success', 'detail' => 'On track'], ['saveData' => false]) ?>
        </div>
        <div class="grid gap-4 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-3">
            <?= view('components/usage-bar', ['label' => 'Seats', 'used' => 410, 'limit' => 500], ['saveData' => false]) ?>
            <?= view('components/usage-bar', ['label' => 'Storage', 'used' => 19, 'limit' => 20, 'unitLabel' => 'GB'], ['saveData' => false]) ?>
            <?= view('components/usage-bar', ['label' => 'AI generations', 'used' => 200, 'limit' => 200], ['saveData' => false]) ?>
        </div>
        <?= view('components/lock-note', ['kind' => 'sis'], ['saveData' => false]) ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    </section>

    <section class="flex flex-col gap-4" data-kit="list">
        <h2 class="text-lg font-bold text-brand">A list: toolbar, table, bulk bar, pagination</h2>
        <?= view('components/toolbar', [
            'action'   => site_url('ui-kit'),
            'filtered' => false,
            'fields'   => [
                ['type' => 'search', 'name' => 'q', 'label' => 'Search', 'placeholder' => 'Name or email', 'span' => 'lg:col-span-2'],
                ['type' => 'select', 'name' => 'role', 'label' => 'Role', 'options' => ['' => 'Any role', 'student' => 'Student', 'instructor' => 'Instructor']],
                ['type' => 'select', 'name' => 'status', 'label' => 'Status', 'options' => ['' => 'Any status', 'active' => 'Active']],
            ],
        ], ['saveData' => false]) ?>
        <p class="text-sm text-[#646464]" aria-live="polite">3 people match</p>
        <?= view('components/data-table', [
            'caption'    => 'Sample people',
            'columns'    => [
                ['key' => 'name', 'label' => 'Name', 'sortable' => true],
                ['key' => 'role', 'label' => 'Role', 'sortable' => true],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows'       => $rows,
            'sort'       => $sort,
            'dir'        => $dir,
            'sortUrl'    => $sortUrl,
            'selectable' => true,
            'nounOne'    => 'person',
            'nounMany'   => 'people',
            'bulk'       => '<form method="post" action="' . site_url('ui-kit') . '" data-bulk-form class="flex gap-2">' . csrf_field()
                . '<button type="submit" class="' . $second . '">Export</button></form>',
        ], ['saveData' => false]) ?>
        <?= view('components/pagination', ['page' => 2, 'pages' => 7, 'total' => 163, 'baseUrl' => site_url('ui-kit'), 'query' => ['sort' => $sort, 'dir' => $dir]], ['saveData' => false]) ?>
    </section>

    <section class="flex flex-col gap-3" data-kit="states">
        <h2 class="text-lg font-bold text-brand">Loading, empty and error</h2>
        <?= view('components/skeleton', ['rows' => 3, 'label' => 'Loading people'], ['saveData' => false]) ?>
        <div class="rounded-2xl bg-white/70">
            <?= view('components/empty-state', ['icon' => 'user', 'title' => 'No people yet', 'text' => 'Invite the first person to get started.'], ['saveData' => false]) ?>
        </div>
        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-[#C33939]/30 bg-white px-8 py-10 text-center" role="alert">
            <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
            <span class="text-sm font-semibold text-[#1A1C1E]">We could not load the people</span>
            <span class="text-xs text-[#646464]">Something went wrong on our side. Nothing has been lost.</span>
            <a href="<?= site_url('ui-kit') ?>" class="<?= $primary ?>">Try again</a>
        </div>
    </section>

    <section class="flex flex-col gap-4" data-kit="navigation">
        <h2 class="text-lg font-bold text-brand">Tabs and steps</h2>
        <?= view('components/tabs', ['tabs' => [
            ['label' => 'Details', 'href' => '#details', 'active' => true],
            ['label' => 'Instructors', 'href' => '#instructors', 'count' => 2],
            ['label' => 'Roster', 'href' => '#roster', 'count' => 38],
        ]], ['saveData' => false]) ?>
        <?= view('components/stepper', ['steps' => ['Upload', 'Preview', 'Apply'], 'current' => 2], ['saveData' => false]) ?>
    </section>

    <section class="flex flex-col gap-3" data-kit="dialogs">
        <h2 class="text-lg font-bold text-brand">Dialogs</h2>
        <div class="flex flex-wrap gap-3">
            <button type="button" class="<?= $primary ?>" data-dialog-open="kit-form">Open a form dialog</button>
            <button type="button" class="<?= $danger ?>" data-dialog-open="kit-confirm">Open a confirm</button>
        </div>
    </section>

</div>

<?php
$formBody = '<form id="kit-form-el" method="post" action="' . site_url('ui-kit') . '" data-dialog-form class="flex flex-col gap-4">'
    . csrf_field()
    . view('components/form-field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'help' => 'Leave it empty to see how an error looks.'], ['saveData' => false])
    . view('components/form-field', ['name' => 'role', 'label' => 'Role', 'type' => 'select', 'options' => ['student' => 'Student', 'instructor' => 'Instructor']], ['saveData' => false])
    . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>'
    . '</form>';

$formFooter = '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] hover:bg-gray-50">Cancel</button>'
    . '<button type="submit" form="kit-form-el" class="' . $primary . '">Save</button>';
?>

<?= view('components/dialog', ['id' => 'kit-form', 'title' => 'Add a person', 'body' => $formBody, 'footer' => $formFooter], ['saveData' => false]) ?>

<?= view('components/confirm', [
    'id'            => 'kit-confirm',
    'title'         => 'Deactivate Ann Lee?',
    'text'          => 'She will be signed out and will not be able to sign in until you reactivate her. Her work and grades are kept.',
    'action'        => site_url('ui-kit'),
    'confirmLabel'  => 'Deactivate',
    'tone'          => 'danger',
    'typeToConfirm' => 'Ann Lee',
], ['saveData' => false]) ?>

<?= $this->endSection() ?>
