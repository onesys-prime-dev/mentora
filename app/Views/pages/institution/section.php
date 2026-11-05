<?php
/**
 * One block section: its courses and its students.
 *
 * @var array<string, mixed>       $section
 * @var list<array<string, mixed>> $sectionCourses     its offerings (not archived), with instructors and enrolled counts
 * @var list<array<string, mixed>> $sectionStudents    its members, with how many of its courses they hold a place in
 * @var list<array<string, mixed>> $sectionAvailable   active courses it does not take yet
 * @var bool                       $sectionCanEdit
 */
$s       = $section;
$base    = 'institution/sections/' . $s['id'];
$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$second  = 'rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$link    = 'text-sm font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded';
$warn    = 'text-sm font-semibold text-[#C33939] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C33939]/50 rounded';
$json    = static fn (array $values): string => esc(json_encode($values, JSON_UNESCAPED_UNICODE));

$active   = $s['status'] === 'active';
$termOpen = in_array($s['term_status'], ['upcoming', 'active'], true);
$canEdit  = $sectionCanEdit && $active && $termOpen;
$members  = count($sectionStudents);
$capacity = (int) $s['capacity'];
$courseCount = count($sectionCourses);

$courseRows = [];

foreach ($sectionCourses as $c) {
    $actions = '<a class="' . $link . ' mr-3" href="' . esc(site_url('institution/enrolments/offering/' . $c['id'])) . '" aria-label="Roster of ' . esc($c['course_code']) . '">Roster</a>';

    if ($canEdit) {
        $actions .= '<button type="button" class="' . $warn . '" data-dialog-open="section-confirm"'
            . ' data-dialog-title="Take ' . esc($c['course_code']) . ' out of ' . esc($s['code']) . '?"'
            . ' data-dialog-text="The course stays in the catalog and its content is kept. This only works while nobody is enrolled in it here."'
            . ' data-dialog-label="Take out" data-dialog-action="' . esc(site_url($base . '/courses/' . $c['id'] . '/remove')) . '">Take out</button>';
    }

    $chips = $c['instructors'] === []
        ? '<span class="text-xs font-semibold text-[#946200]" data-no-instructor>No instructor</span>'
        : implode(' ', array_map(static fn (string $n): string => '<span class="inline-flex items-center rounded-full bg-brand-tint px-3 py-1 text-xs">' . esc($n) . '</span>', $c['instructors']));

    $courseRows[] = [
        'id'      => $c['id'],
        'label'   => $c['course_code'],
        'cells'   => [
            'course'      => ['html' => '<span class="font-mono text-xs font-semibold">' . esc($c['course_code']) . '</span> <span class="font-medium">' . esc($c['course_title']) . '</span>'],
            'instructors' => ['html' => $chips],
            'enrolled'    => (string) $c['enrolled_count'] . ' / ' . (string) $c['capacity'],
        ],
        'actions' => $actions,
    ];
}

$studentRows = [];

foreach ($sectionStudents as $st) {
    $actions = '';

    if ($canEdit) {
        $actions = '<button type="button" class="' . $warn . '" data-dialog-open="section-confirm"'
            . ' data-dialog-title="Take ' . esc($st['name']) . ' out of ' . esc($s['code']) . '?"'
            . ' data-dialog-text="Their places in this section\'s courses are dropped. Their work and grades are kept, and you can add them back."'
            . ' data-dialog-label="Take out" data-dialog-action="' . esc(site_url($base . '/students/' . $st['id'] . '/remove')) . '">Take out</button>';
    }

    $partial = $courseCount > 0 && $st['places'] < $courseCount;

    $studentRows[] = [
        'id'      => $st['id'],
        'label'   => $st['name'],
        'cells'   => [
            'name'   => ['html' => '<span class="font-medium">' . esc($st['name']) . '</span>' . ($st['email'] !== '' && $st['email'] !== $st['name'] ? '<span class="block text-xs text-[#646464]">' . esc($st['email']) . '</span>' : '')],
            'number' => $st['student_number'] !== '' ? $st['student_number'] : '—',
            'places' => ['html' => '<span class="' . ($partial ? 'font-semibold text-[#946200]' : '') . '"' . ($partial ? ' data-partial-places title="Some of this student\'s places in the section\'s courses are not active, for example dropped on a roster or managed by the SIS."' : '') . '>' . esc((string) $st['places']) . ' of ' . esc((string) $courseCount) . '</span>'],
        ],
        'actions' => $actions,
    ];
}
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('institution/sections'), 'backLabel' => 'Back to sections'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => $s['code'],
        'icon'        => 'user',
        'description' => $s['program_code'] . ' · Year ' . $s['year_level'] . ' · ' . $s['term_name'] . ' ' . $s['academic_year'],
        'actions'     => $canEdit
            ? '<button type="button" class="' . $second . '" data-dialog-open="section-edit" data-dialog-title="Edit ' . esc($s['code']) . '" data-dialog-label="Save"'
                . ' data-dialog-action="' . esc(site_url($base)) . '" data-dialog-values="' . $json(['code' => $s['code'], 'year_level' => (string) $s['year_level'], 'capacity' => (string) $s['capacity']]) . '">Edit</button>'
            : '',
    ], ['saveData' => false]) ?>

    <?php if (! $sectionCanEdit): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php elseif ($active && ! $termOpen): ?>
        <?= view('components/lock-note', ['kind' => 'closed', 'text' => $s['term_name'] . ' is closed, so this section cannot be changed. You can still look at it.'], ['saveData' => false]) ?>
    <?php elseif (! $active): ?>
        <?php if ($termOpen): ?>
            <?= view('components/lock-note', ['kind' => 'closed', 'text' => 'This section is archived, so it cannot be changed. Bring it back to use it again.'], ['saveData' => false]) ?>
            <form method="post" action="<?= esc(site_url($base . '/reactivate')) ?>"><?= csrf_field() ?>
                <button type="submit" class="<?= $second ?>">Reactivate this section</button>
            </form>
        <?php else: ?>
            <?= view('components/lock-note', ['kind' => 'closed', 'text' => 'This section is archived and its term is closed, so it cannot be changed.'], ['saveData' => false]) ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <section class="rounded-2xl border border-[#A9A9A9]/50 bg-white p-5" aria-label="Places">
        <?= view('components/usage-bar', ['label' => 'Students in the section', 'used' => $members, 'limit' => $capacity], ['saveData' => false]) ?>
        <?php if ($members > $capacity): ?>
            <p class="mt-2 text-xs font-semibold text-[#946200]" data-over-capacity>Over capacity by <?= esc((string) ($members - $capacity)) ?>.</p>
        <?php endif; ?>
        <p class="mt-3 text-xs text-[#646464]">A student in this section is enrolled in every one of its courses. A student who takes only one course of it is enrolled in that course on its roster instead.</p>
    </section>

    <section class="flex flex-col gap-4" aria-labelledby="section-courses-h">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="section-courses-h" class="text-lg font-bold text-brand">Courses <span class="text-sm font-normal text-[#646464]" data-course-count>(<?= esc((string) $courseCount) ?>)</span></h2>
            <?php if ($canEdit && $sectionAvailable !== []): ?>
                <button type="button" class="<?= $primary ?>" data-dialog-open="section-course" data-dialog-title="Add a course to <?= esc($s['code']) ?>" data-dialog-action="<?= esc(site_url($base . '/courses')) ?>" data-dialog-label="Add course">Add a course</button>
            <?php endif; ?>
        </div>

        <?= view('components/data-table', [
            'caption'    => 'Courses of ' . $s['code'],
            'columns'    => [
                ['key' => 'course', 'label' => 'Course'],
                ['key' => 'instructors', 'label' => 'Instructors'],
                ['key' => 'enrolled', 'label' => 'Enrolled / capacity'],
            ],
            'rows'       => $courseRows,
            'emptyIcon'  => 'modules',
            'emptyTitle' => 'No courses yet',
            'emptyText'  => $canEdit ? 'Add the courses this section takes. Students in the section are enrolled in each one.' : 'Courses will be listed here.',
        ], ['saveData' => false]) ?>
    </section>

    <section class="flex flex-col gap-4" aria-labelledby="section-students-h">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="section-students-h" class="text-lg font-bold text-brand">Students <span class="text-sm font-normal text-[#646464]" data-student-count>(<?= esc((string) $members) ?>)</span></h2>
            <?php if ($canEdit): ?>
                <button type="button" class="<?= $primary ?>" data-dialog-open="section-students" data-dialog-title="Add students to <?= esc($s['code']) ?>" data-dialog-action="<?= esc(site_url($base . '/students')) ?>" data-dialog-label="Add students">Add students</button>
            <?php endif; ?>
        </div>

        <?= view('components/data-table', [
            'caption'    => 'Students of ' . $s['code'],
            'columns'    => [
                ['key' => 'name', 'label' => 'Student'],
                ['key' => 'number', 'label' => 'Student number'],
                ['key' => 'places', 'label' => 'Places in its courses'],
            ],
            'rows'       => $studentRows,
            'emptyIcon'  => 'user',
            'emptyTitle' => 'No students yet',
            'emptyText'  => $canEdit ? 'Add students by their student number or email address.' : 'Students will be listed here.',
        ], ['saveData' => false]) ?>
    </section>

    <?php if ($canEdit): ?>
    <section class="mt-2" aria-label="Archive this section">
        <button type="button" class="<?= $warn ?>" data-dialog-open="section-confirm"
                data-dialog-title="Archive <?= esc($s['code']) ?>?"
                data-dialog-text="Archiving takes the section and its courses out of use. It must have no students in it. You can bring it back while its term is open."
                data-dialog-label="Archive" data-dialog-action="<?= esc(site_url($base . '/archive')) ?>">Archive this section</button>
    </section>
    <?php endif; ?>

</div>

</div>

<?php if ($canEdit): ?>
    <?php
    $field = static fn (array $f): string => view('components/form-field', $f, ['saveData' => false]);

    $buttons = static fn (string $label): string => '<div class="flex flex-wrap items-center justify-end gap-3">'
        . '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>'
        . '<button type="submit" data-dialog-label class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">' . esc($label) . '</button></div>';

    $form = static fn (string $inside, string $label): string => '<form method="post" action="" data-dialog-form class="flex flex-col gap-4">' . csrf_field()
        . $inside . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>' . $buttons($label) . '</form>';

    $courseSelect = ['' => 'Choose a course'];

    foreach ($sectionAvailable as $c) {
        $courseSelect[(string) $c['id']] = $c['course_code'] . ' · ' . $c['course_title'];
    }
    ?>

    <?= view('components/dialog', ['id' => 'section-edit', 'title' => 'Edit section', 'body' => $form(
        $field(['name' => 'code', 'label' => 'Section name', 'required' => true, 'maxlength' => 50, 'attrs' => 'autocomplete="off"', 'help' => 'Its courses take the new name too.'])
        . $field(['name' => 'year_level', 'label' => 'Year level', 'required' => true, 'type' => 'number', 'attrs' => 'min="1" max="12" inputmode="numeric"'])
        . $field(['name' => 'capacity', 'label' => 'Capacity', 'required' => true, 'type' => 'number', 'attrs' => 'min="1" max="1000" inputmode="numeric"', 'help' => 'It cannot be lower than the students already in the section (' . $members . ').']),
        'Save',
    )], ['saveData' => false]) ?>

    <?php if ($sectionAvailable !== []): ?>
    <?= view('components/dialog', ['id' => 'section-course', 'title' => 'Add a course', 'body' => $form(
        $field(['name' => 'course_id', 'label' => 'Course', 'type' => 'select', 'options' => $courseSelect, 'required' => true, 'help' => 'The students already in the section are enrolled in it.']),
        'Add course',
    )], ['saveData' => false]) ?>
    <?php endif; ?>

    <?= view('components/dialog', ['id' => 'section-students', 'title' => 'Add students', 'body' => $form(
        $field(['name' => 'students', 'label' => 'Student numbers or email addresses', 'type' => 'textarea', 'required' => true, 'attrs' => 'autocomplete="off" data-student-autocomplete="' . esc(site_url('institution/enrolments/suggest') . '?section=' . $s['id'], 'attr') . '"', 'help' => 'One per line, or separated by commas. Up to 500 at once. Start typing to pick a student from your institution. They are enrolled in each of the section\'s courses; anyone who cannot be is listed.'])
        . $field(['name' => 'over_capacity', 'label' => 'Add them even if the section goes over capacity', 'type' => 'checkbox', 'help' => $capacity . ' places, ' . $members . ' taken.']),
        'Add students',
    )], ['saveData' => false]) ?>

    <?= view('components/confirm', [
        'id'           => 'section-confirm',
        'title'        => 'Are you sure?',
        'text'         => 'This changes the section.',
        'action'       => site_url($base),
        'confirmLabel' => 'Confirm',
        'tone'         => 'danger',
    ], ['saveData' => false]) ?>
<?php endif; ?>

<?= $this->endSection() ?>
