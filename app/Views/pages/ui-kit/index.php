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
$touch   = 'pointer-coarse:min-h-11 pointer-coarse:min-w-11';
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

    <section class="flex flex-col gap-fluid" data-kit="responsive" data-responsive-kit>
        <h2 class="text-lg font-bold text-brand">Responsive</h2>

        <div class="flex flex-col gap-2">
            <p class="text-fluid-h1 font-bold text-brand">Fluid heading one</p>
            <p class="text-fluid-h2 font-bold text-[#1A1C1E]">Fluid heading two</p>
            <p class="text-fluid-metric font-bold text-brand">17 / 20</p>
            <p class="max-w-reading text-base text-[#3D3D3D]">Headings and metrics scale smoothly between 320 and 1440 pixels. Body text stays at 16 pixels.</p>
        </div>

        <div class="grid gap-fluid px-gutter sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach (['Algorithms' => '24 students', 'Databases' => '31 students', 'Networks' => '18 students'] as $course => $count): ?>
                <div class="@container">
                    <div class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-4 @sm:flex-row @sm:items-center @sm:justify-between">
                        <div>
                            <p class="text-sm font-bold text-[#1A1C1E]"><?= esc($course) ?></p>
                            <p class="text-xs text-[#646464]"><?= esc($count) ?></p>
                        </div>
                        <button type="button" class="<?= $second ?> <?= $touch ?>">Open course</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="flex flex-wrap gap-3">
            <button type="button" class="<?= $primary ?> <?= $touch ?>" data-dialog-open="kit-fullscreen">New announcement</button>
            <button type="button" class="<?= $second ?> <?= $touch ?>" data-dialog-open="kit-sheet">Row actions</button>
            <button type="button" class="<?= $second ?> <?= $touch ?>" data-dialog-open="kit-menu">Open menu</button>
            <button type="button" class="<?= $danger ?> <?= $touch ?>" data-dialog-open="kit-resp-confirm">Delete announcement</button>
        </div>

        <div data-action-bar class="sticky bottom-0 -mx-4 sm:mx-0">
            <div id="kit-score-panel" data-action-bar-panel>
                <div>
                    <div class="flex flex-col gap-2 p-4 text-sm text-[#3D3D3D]">
                        <p class="font-semibold text-[#1A1C1E]">Rubric</p>
                        <p>Clarity: 6 / 8</p>
                        <p>Method: 7 / 8</p>
                        <p>Presentation: 4 / 4</p>
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-between gap-3 px-4 pt-3">
                <span class="text-base font-bold text-brand">17 / 20</span>
                <div class="flex items-center gap-3">
                    <button type="button" data-action-bar-toggle aria-controls="kit-score-panel" aria-expanded="false" class="<?= $second ?> <?= $touch ?>">Rubric</button>
                    <button type="button" class="<?= $primary ?> <?= $touch ?>">Save, next</button>
                </div>
            </div>
        </div>

        <div data-split data-split-key="ui-kit-demo" data-split-min="12rem" data-split-max="20rem" data-kit="split">
            <aside id="kit-side-panel" data-side-panel class="flex flex-col gap-2 rounded-xl border border-gray-200 bg-white p-4" data-split-start>
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-bold text-[#1A1C1E]">Students</p>
                    <button type="button" data-side-panel-close class="<?= $second ?> <?= $touch ?> lg:hidden">Close</button>
                </div>
                <?php foreach (['Ana Reyes', 'Ben Cruz', 'Carla Lim'] as $student): ?>
                    <button type="button" class="rounded-lg px-3 py-2 text-left text-sm font-semibold text-[#1A1C1E] hover:bg-gray-50 <?= $touch ?>"><?= esc($student) ?></button>
                <?php endforeach; ?>
            </aside>
            <div data-split-handle role="separator" aria-orientation="vertical" aria-label="Resize students list" tabindex="0"></div>
            <div data-split-end class="flex flex-col items-start gap-3 rounded-xl border border-gray-200 bg-white p-4">
                <button type="button" data-side-panel-toggle aria-controls="kit-side-panel" aria-expanded="false" class="<?= $second ?> <?= $touch ?> lg:hidden">Students</button>
                <p class="text-sm text-[#3D3D3D]">The students list is a panel below lg and a resizable pane from lg up.</p>
            </div>
        </div>

<?php
// The Components showcase: the shared components as phones, tablets and laptops get them (responsive.spec.js).
$learners = [
    ['id' => 11, 'name' => 'Ann Reyes',     'program' => 'BS Computer Science',       'status' => 'Active',      'email' => 'ann.reyes@example.edu',     'seen' => 'Today, 9:41 AM', 'group' => 'Section A'],
    ['id' => 12, 'name' => 'Ben Santos',    'program' => 'BS Information Technology', 'status' => 'Invited',     'email' => 'ben.santos@example.edu',    'seen' => 'Never',          'group' => 'Section A'],
    ['id' => 13, 'name' => 'Carla Mendoza', 'program' => 'BS Computer Science',       'status' => 'Active',      'email' => 'carla.mendoza@example.edu', 'seen' => 'Yesterday',      'group' => 'Section B'],
    ['id' => 14, 'name' => 'Dan Cruz',      'program' => 'BS Data Science',           'status' => 'Deactivated', 'email' => 'dan.cruz@example.edu',      'seen' => '12 Oct 2026',    'group' => 'Section B'],
];

$learnerRows = [];

foreach ($learners as $learner) {
    $learnerRows[] = [
        'id'      => $learner['id'],
        'label'   => $learner['name'],
        'group'   => $learner['group'],
        'cells'   => [
            'name'    => $learner['name'],
            'program' => $learner['program'],
            'status'  => ['html' => view('components/status-badge', ['label' => $learner['status'], 'tone' => $tones[$learner['status']]], ['saveData' => false])],
            'email'   => $learner['email'],
            'seen'    => $learner['seen'],
        ],
        'actions' => '<button type="button" class="inline-flex items-center rounded-full px-3 py-1.5 text-sm font-semibold text-brand hover:bg-brand/10 ' . $touch . '">Send a message</button> '
            . '<button type="button" class="inline-flex items-center rounded-full px-3 py-1.5 text-sm font-semibold text-[#C33939] hover:bg-[#C33939]/10 ' . $touch . '">Remove from section</button>',
    ];
}

$gridRoles = [
    'institutional_admin' => 'Institution administrator',
    'dean'                => 'Dean',
    'chairperson'         => 'Chairperson',
    'instructor'          => 'Instructor',
    'student'             => 'Student',
    'parent'              => 'Parent',
];
$gridSections = [
    'Courses' => [
        'courses.view'   => 'See courses and what is in them',
        'courses.manage' => 'Add and change course content',
        'grading.manage' => 'Grade work and release marks',
    ],
    'People and messages' => [
        'people.view'   => 'See the people of the institution',
        'messages.use'  => 'Send and read messages',
        'children.view' => "Follow a child's courses and grades",
    ],
];
$gridNow = [
    'institutional_admin' => ['courses.view', 'people.view', 'messages.use'],
    'dean'                => ['courses.view', 'messages.use'],
    'chairperson'         => ['courses.view', 'courses.manage', 'grading.manage', 'messages.use'],
    'instructor'          => ['courses.view', 'courses.manage', 'grading.manage', 'messages.use'],
    'student'             => ['courses.view', 'messages.use'],
    'parent'              => ['children.view', 'messages.use'],
];
// One difference from the defaults, so a cell shows its "Edited" mark.
$gridDefault = array_merge($gridNow, ['dean' => ['courses.view']]);

$personFields = static fn (): string => view('components/form-field', ['name' => 'given_name', 'label' => 'First name', 'autocomplete' => 'given-name'], ['saveData' => false])
    . view('components/form-field', ['name' => 'family_name', 'label' => 'Last name', 'autocomplete' => 'family-name'], ['saveData' => false])
    . view('components/form-field', ['name' => 'email', 'label' => 'Email', 'type' => 'email'], ['saveData' => false])
    . view('components/form-field', ['name' => 'student_number', 'label' => 'Student number', 'inputmode' => 'numeric'], ['saveData' => false]);
?>

        <section class="flex flex-col gap-fluid" data-kit="components">
            <h3 class="text-base font-bold text-brand">Components</h3>

            <div class="flex flex-col gap-3" data-kit="people">
                <h4 class="text-sm font-semibold text-[#1A1C1E]">A grouped list with row actions</h4>
                <?= view('components/data-table', [
                    'id'      => 'kit-people',
                    'caption' => 'Students by section',
                    'columns' => [
                        ['key' => 'name', 'label' => 'Name'],
                        ['key' => 'program', 'label' => 'Program'],
                        ['key' => 'status', 'label' => 'Status'],
                        ['key' => 'email', 'label' => 'Email', 'priority' => 2],
                        ['key' => 'seen', 'label' => 'Last active', 'priority' => 3],
                    ],
                    'rows'    => $learnerRows,
                ], ['saveData' => false]) ?>
                <?= view('components/pagination', ['page' => 3, 'pages' => 9, 'total' => 214, 'baseUrl' => site_url('ui-kit')], ['saveData' => false]) ?>
            </div>

            <div class="flex flex-col gap-3" data-kit="grid">
                <h4 class="text-sm font-semibold text-[#1A1C1E]">A wide grid that scrolls under its first column</h4>
                <?= view('components/permission-grid', [
                    'roleTitles'     => $gridRoles,
                    'sections'       => $gridSections,
                    'lockedRole'     => '',
                    'currentMatrix'  => $gridNow,
                    'defaultMatrix'  => $gridDefault,
                    'gridAction'     => site_url('ui-kit'),
                    'gridReadOnly'   => false,
                    'gridScrollable' => false,
                ], ['saveData' => false]) ?>
            </div>

            <div class="flex flex-col gap-3" data-kit="form-dialog">
                <h4 class="text-sm font-semibold text-[#1A1C1E]">A form dialog and a confirm</h4>
                <p class="max-w-reading text-sm text-[#646464]">On a phone the form fills the screen and its button stays at the bottom; the confirm stays small everywhere.</p>
                <div class="flex flex-wrap gap-3">
                    <button type="button" class="<?= $primary ?> <?= $touch ?>" data-dialog-open="kit-comp-form">New course</button>
                </div>
            </div>

            <div class="flex flex-col gap-3" data-kit="forms">
                <h4 class="text-sm font-semibold text-[#1A1C1E]">One form in a narrow card and in a wide one</h4>
                <div class="grid gap-fluid lg:grid-cols-[20rem_minmax(0,1fr)]">
                    <div class="@container rounded-2xl bg-white p-5 shadow-sm" data-kit-form="narrow">
                        <form method="get" action="<?= site_url('ui-kit') ?>" class="form-grid" aria-label="Student details in a narrow card"><?= $personFields() ?></form>
                    </div>
                    <div class="@container rounded-2xl bg-white p-5 shadow-sm" data-kit-form="wide">
                        <form method="get" action="<?= site_url('ui-kit') ?>" class="form-grid" aria-label="Student details in a wide card"><?= $personFields() ?></form>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-3" data-kit="stepper">
                <h4 class="text-sm font-semibold text-[#1A1C1E]">Steps</h4>
                <?= view('components/stepper', ['steps' => ['Choose a file', 'Check the rows', 'Import'], 'current' => 2, 'ariaLabel' => 'Import steps'], ['saveData' => false]) ?>
            </div>

            <div class="flex flex-col gap-3" data-kit="tabs">
                <h4 class="text-sm font-semibold text-[#1A1C1E]">Tabs</h4>
                <?= view('components/breadcrumb', ['crumbs' => [['label' => 'Algorithms'], ['label' => 'Modules'], ['label' => 'Week 3: Sorting and searching']], 'sticky' => false], ['saveData' => false]) ?>
                <?= view('components/tabs', ['ariaLabel' => 'Course sections', 'tabs' => [
                    ['label' => 'Overview', 'href' => '#kit-overview', 'active' => true],
                    ['label' => 'Modules', 'href' => '#kit-modules', 'count' => 8],
                    ['label' => 'Assessments', 'href' => '#kit-assessments', 'count' => 5],
                    ['label' => 'People', 'href' => '#kit-people', 'count' => 38],
                    ['label' => 'Grades', 'href' => '#kit-grades'],
                    ['label' => 'Settings', 'href' => '#kit-settings'],
                ]], ['saveData' => false]) ?>
            </div>

            <div class="flex flex-col gap-3" data-kit="cards">
                <h4 class="text-sm font-semibold text-[#1A1C1E]">Cards of two widths</h4>
                <div class="flex flex-wrap gap-fluid">
                    <div class="w-52 shrink-0">
                        <?= view('components/metric-card', ['label' => 'Handed in', 'value' => '31 / 38', 'icon' => 'check', 'detail' => '7 still to come'], ['saveData' => false]) ?>
                    </div>
                    <div class="min-w-64 flex-1">
                        <?= view('components/metric-card', ['label' => 'Class average', 'value' => '84%', 'icon' => 'hat', 'tone' => 'success', 'size' => 'prominent', 'detail' => 'Up 3 points since the midterm'], ['saveData' => false]) ?>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-3" data-kit="empty">
                <h4 class="text-sm font-semibold text-[#1A1C1E]">Nothing yet</h4>
                <div class="rounded-2xl bg-white/70">
                    <?= view('components/empty-state', [
                        'icon'   => 'user',
                        'title'  => 'No submissions yet',
                        'text'   => 'Work that students hand in shows here.',
                        'action' => '<button type="button" class="' . $second . ' ' . $touch . '">Remind the class</button>',
                    ], ['saveData' => false]) ?>
                </div>
            </div>
        </section>

<?php
$visibility = '';
foreach (['Everyone in the institution', 'Enrolled students', 'Instructors only'] as $choice) {
    $visibility .= '<button type="button" class="flex w-full items-center rounded-lg px-3 py-2 text-left text-sm text-[#1A1C1E] hover:bg-gray-50 ' . $touch . '">' . esc($choice) . '</button>';
}

// One form, its buttons inside it, no footer and no top action: on a phone it fills the screen and pins its submit row.
$courseBody = '<form id="kit-course" method="post" action="' . site_url('ui-kit') . '" data-dialog-form class="flex flex-col gap-4">'
    . csrf_field()
    . view('components/form-field', ['name' => 'name', 'label' => 'Course title', 'required' => true], ['saveData' => false])
    . view('components/form-field', ['name' => 'term', 'label' => 'Term', 'type' => 'select', 'options' => ['1' => 'First term, 2026 to 2027', '2' => 'Second term, 2026 to 2027']], ['saveData' => false])
    . '<div class="flex flex-col gap-1 text-sm font-medium text-[#1A1C1E]"><span aria-hidden="true">Who can see it</span>'
    . '<details data-menu class="relative" data-kit-dropdown>'
    . '<summary class="flex cursor-pointer list-none items-center justify-between rounded-lg border border-gray-300 bg-white px-3 py-2 text-base font-normal sm:text-sm pointer-coarse:min-h-11 [&::-webkit-details-marker]:hidden"><span><span class="sr-only">Who can see it: </span>Enrolled students</span><span aria-hidden="true">&#9662;</span></summary>'
    . '<div class="absolute inset-x-0 top-full z-10 mt-1 flex flex-col rounded-xl border border-gray-200 bg-white p-1 shadow-lg">' . $visibility . '</div>'
    . '</details></div>'
    . view('components/form-field', ['name' => 'description', 'label' => 'Description', 'type' => 'textarea'], ['saveData' => false])
    . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>'
    . '<div class="flex justify-end gap-3">'
    . '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] hover:bg-gray-50 ' . $touch . '">Cancel</button>'
    . '<button type="submit" class="' . $primary . ' ' . $touch . '">Create course</button>'
    . '</div>'
    . '</form>';
?>

<?= view('components/dialog', ['id' => 'kit-comp-form', 'title' => 'New course', 'body' => $courseBody], ['saveData' => false]) ?>

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

<?php
$annBody = '<form id="kit-announcement" method="post" action="' . site_url('ui-kit') . '" data-dialog-form class="flex flex-col gap-4">'
    . csrf_field()
    . '<label class="flex flex-col gap-1 text-sm font-medium text-[#1A1C1E]" data-field="name">Title<input type="text" name="name" required class="pointer-coarse:min-h-11 rounded-lg border border-gray-300 bg-white px-3 py-2 text-base text-[#1A1C1E] placeholder:text-gray-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"><span data-field-error class="text-xs text-[#C33939]"></span></label>'
    . '<label class="flex flex-col gap-1 text-sm font-medium text-[#1A1C1E]">Message<textarea name="message" rows="4" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-base text-[#1A1C1E] placeholder:text-gray-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"></textarea></label>'
    . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>'
    . '</form>';
$annFooter = '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] hover:bg-gray-50 pointer-coarse:min-h-11 pointer-coarse:min-w-11">Cancel</button>'
    . '<button type="submit" form="kit-announcement" class="' . $primary . ' ' . $touch . '">Publish</button>';

$rowActions = '';
foreach (['View submission', 'Grade work', 'Send a message', 'Remove from course'] as $action) {
    $rowActions .= '<button type="button" data-dialog-close class="flex w-full items-center rounded-lg px-3 py-3 text-left text-sm font-semibold text-[#1A1C1E] hover:bg-gray-50 pointer-coarse:min-h-11 pointer-coarse:min-w-11">' . esc($action) . '</button>';
}

$tiles = '<div class="grid grid-cols-2 gap-3">';
foreach (['Courses', 'Grades', 'Messages', 'Schedule'] as $tile) {
    $tiles .= '<button type="button" data-dialog-close class="flex min-h-24 items-center justify-center rounded-xl border border-gray-200 text-sm font-semibold text-brand pointer-coarse:min-w-11">' . esc($tile) . '</button>';
}
$tiles .= '</div>';
?>

<?= view('components/dialog', ['id' => 'kit-fullscreen', 'variant' => 'fullscreen', 'title' => 'New announcement', 'body' => $annBody, 'footer' => $annFooter, 'topAction' => ['label' => 'Publish', 'form' => 'kit-announcement']], ['saveData' => false]) ?>

<?= view('components/dialog', ['id' => 'kit-sheet', 'variant' => 'sheet', 'title' => 'Row actions', 'body' => '<div class="flex flex-col">' . $rowActions . '</div>'], ['saveData' => false]) ?>

<?= view('components/dialog', ['id' => 'kit-menu', 'variant' => 'menu', 'title' => 'Menu', 'body' => $tiles], ['saveData' => false]) ?>

<?= view('components/confirm', [
    'id'           => 'kit-resp-confirm',
    'title'        => 'Delete this announcement?',
    'text'         => 'Students will no longer see it. This cannot be undone.',
    'action'       => site_url('ui-kit'),
    'confirmLabel' => 'Delete',
    'tone'         => 'danger',
], ['saveData' => false]) ?>

    </section>

</div>

<?= $this->endSection() ?>
