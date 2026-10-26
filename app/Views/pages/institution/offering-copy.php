<?php
/**
 * Copy offerings into another term.
 *
 * @var array{sources: list<array<string, mixed>>, targets: list<array<string, mixed>>} $copyTerms
 * @var int                       $copySource
 * @var int                       $copyTarget
 * @var array<string, mixed>|null $copyPlan
 * @var string|null               $copyError
 * @var bool                      $copyCanEdit
 */
$base    = 'institution/terms/copy';
$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:cursor-not-allowed disabled:opacity-50';
$second  = 'rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';

$sourceOptions = ['' => 'Choose a term'];

foreach ($copyTerms['sources'] as $t) {
    $sourceOptions[(string) $t['id']] = $t['name'] . ' ' . $t['academic_year'] . ' (' . $t['offering_count'] . ' offering' . ((int) $t['offering_count'] === 1 ? '' : 's') . ')';
}

$targetOptions = ['' => 'Choose a term'];

foreach ($copyTerms['targets'] as $t) {
    $targetOptions[(string) $t['id']] = $t['name'] . ' ' . $t['academic_year'] . ' (' . $t['status'] . ')';
}

$rows = [];

if ($copyPlan !== null) {
    foreach ($copyPlan['offerings'] as $o) {
        $rows[] = [
            'id'    => $o['id'],
            'label' => $o['course_code'] . ' ' . $o['section_code'],
            'cells' => [
                'course'   => ['html' => '<span class="font-mono text-xs font-semibold">' . esc($o['course_code']) . '</span> <span class="font-medium">' . esc($o['course_title']) . '</span>'],
                'section'  => (string) $o['section_code'],
                'content'  => $o['modules'] . ' modules · ' . $o['items'] . ' items · ' . $o['assessments'] . ' assessments',
                'instructors' => (string) $o['instructors'],
                'status'   => ['html' => $o['conflict']
                    ? view('components/status-badge', ['label' => 'Section already there', 'tone' => 'warning'], ['saveData' => false])
                    : view('components/status-badge', ['label' => 'Ready', 'tone' => 'success'], ['saveData' => false])],
            ],
        ];
    }
}

$shift = $copyPlan['shiftDays'] ?? 0;
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('institution/terms'), 'backLabel' => 'Back to terms'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Copy offerings',
        'icon'        => 'calendar',
        'description' => 'Start a term from the courses you already built: content, assessments and grading setup come across; students, work and grades do not.',
    ], ['saveData' => false]) ?>

    <?= view('components/stepper', ['steps' => ['Choose terms', 'Choose offerings', 'Copy'], 'current' => $copyPlan === null ? 1 : 2], ['saveData' => false]) ?>

    <?php if (! $copyCanEdit): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if ($copyError !== null): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => $copyError], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if ($copyTerms['targets'] === []): ?>
        <?= view('components/alert', ['tone' => 'info', 'title' => 'Add a term first', 'text' => 'Offerings are copied into an upcoming or active term, and there is none yet. Add one on the Terms page.'], ['saveData' => false]) ?>
    <?php endif; ?>

    <form method="get" action="<?= esc(site_url($base)) ?>" class="grid grid-cols-1 gap-4 rounded-2xl border border-gray-200 bg-gray-50 p-6 shadow-md sm:grid-cols-3 sm:items-end" data-copy-terms>
        <?= view('components/form-field', ['name' => 'source', 'label' => 'Copy from', 'type' => 'select', 'options' => $sourceOptions, 'value' => $copySource > 0 ? (string) $copySource : '', 'required' => true], ['saveData' => false]) ?>
        <?= view('components/form-field', ['name' => 'target', 'label' => 'Copy into', 'type' => 'select', 'options' => $targetOptions, 'value' => $copyTarget > 0 ? (string) $copyTarget : '', 'required' => true, 'help' => 'An upcoming or active term.'], ['saveData' => false]) ?>
        <div><button type="submit" class="<?= $second ?>">Show the offerings</button></div>
    </form>

    <?php if ($copyPlan !== null): ?>

        <section class="rounded-2xl border border-gray-200 bg-gray-50 p-6 shadow-md" aria-label="What will be copied" data-copy-summary>
            <h2 class="text-sm font-bold text-brand">From <?= esc($copyPlan['source']['name']) ?> into <?= esc($copyPlan['target']['name']) ?></h2>
            <ul class="mt-3 flex list-disc flex-col gap-1 pl-5 text-sm text-[#3D3D3D]">
                <li><strong>Copied:</strong> sections and weekly schedules, modules, units, items and resources, assessments with their questions and rubrics, and the grading scheme.</li>
                <li><strong>Not copied:</strong> students, enrolments, submissions, scores and grades, announcements, discussions and progress.</li>
                <li><strong>Dates:</strong>
                    <?php if ($shift === 0): ?>
                        the two terms start on the same day, so dates stay as they are.
                    <?php else: ?>
                        assessment dates move <?= esc((string) abs($shift)) ?> day<?= abs($shift) === 1 ? '' : 's' ?> <?= $shift > 0 ? 'later' : 'earlier' ?>, the difference between the two start dates.
                    <?php endif; ?>
                </li>
                <li><strong>Files</strong> are shared with the original, not duplicated, so no extra storage is used.</li>
            </ul>
        </section>

        <form method="post" action="<?= esc(site_url($base)) ?>" class="flex flex-col gap-4" data-copy-form>
            <?= csrf_field() ?>
            <input type="hidden" name="source" value="<?= esc((string) $copySource) ?>">
            <input type="hidden" name="target" value="<?= esc((string) $copyTarget) ?>">

            <?php if ($rows === []): ?>
                <?= view('components/empty-state', ['icon' => 'modules', 'title' => 'No offerings to copy', 'text' => 'This term has no offerings that are not archived.'], ['saveData' => false]) ?>
            <?php else: ?>
                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-gray-50 shadow-md" data-table>
                    <table class="w-full text-sm">
                        <caption class="sr-only">Offerings to copy</caption>
                        <thead class="max-md:sr-only border-b border-gray-100 bg-gray-50/70">
                            <tr>
                                <th class="w-10 px-4 py-3" scope="col"><span class="sr-only">Copy</span></th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-[#646464]" scope="col">Course</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-[#646464]" scope="col">Section</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-[#646464]" scope="col">Holds</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-[#646464]" scope="col">Instructors</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-[#646464]" scope="col">Target</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($copyPlan['offerings'] as $i => $o): ?>
                                <tr class="transition hover:bg-brand-tint">
                                    <td class="w-10 px-4 py-3">
                                        <input type="checkbox" name="offerings[]" value="<?= esc((string) $o['id']) ?>" class="h-4 w-4 rounded border-gray-300 text-brand focus:ring-brand/40"
                                               aria-label="Copy <?= esc($o['course_code'] . ' ' . $o['section_code']) ?>" <?= $o['conflict'] || ! $copyCanEdit ? 'disabled' : 'checked' ?>>
                                    </td>
                                    <td class="px-4 py-3"><?= $rows[$i]['cells']['course']['html'] ?></td>
                                    <td class="px-4 py-3"><?= esc($rows[$i]['cells']['section']) ?></td>
                                    <td class="px-4 py-3 text-[#3D3D3D]"><?= esc($rows[$i]['cells']['content']) ?></td>
                                    <td class="px-4 py-3"><?= esc($rows[$i]['cells']['instructors']) ?></td>
                                    <td class="px-4 py-3"><?= $rows[$i]['cells']['status']['html'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <label class="flex items-center gap-2 text-sm text-[#1A1C1E]">
                    <input type="checkbox" name="with_instructors" value="1" class="h-4 w-4 rounded border-gray-300 text-brand focus:ring-brand/40" <?= $copyCanEdit ? '' : 'disabled' ?>>
                    Also assign the same instructors
                </label>

                <div class="flex flex-wrap items-center justify-end gap-3">
                    <a class="<?= $second ?>" href="<?= esc(site_url('institution/terms')) ?>">Cancel</a>
                    <?php if ($copyCanEdit): ?>
                        <button type="submit" class="<?= $primary ?>">Copy the ticked offerings</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>

    <?php endif; ?>

</div>

</div>

<?= $this->endSection() ?>
