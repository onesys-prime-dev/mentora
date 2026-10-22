<?php
/**
 * Bulk entry from CSV files: upload (step 1) and preview (step 2).
 *
 * @var int                        $uploadStep
 * @var bool                       $uploadBlocked  a SIS connection is enabled
 * @var bool                       $uploadCanEdit
 * @var list<string>               $uploadDatasets
 * @var array<string, mixed>|null  $uploadKept     the kept preview (step 2)
 * @var string                     $uploadToken
 */
$base    = 'institution/people/upload';
$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:cursor-not-allowed disabled:opacity-50';
$second  = 'rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$link    = 'text-sm font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded';

$labels = [
    'students'    => ['Students', 'external_id, email, first_name, last_name; student_number is optional.'],
    'instructors' => ['Instructors', 'external_id, email, first_name, last_name.'],
    'courses'     => ['Courses', 'external_id, course_code, course_title; units and program_code are optional.'],
    'enrollments' => ['Enrolments', 'course_external_id, student_external_id; section and status are optional.'],
    'staff_assignments' => ['Staff assignments', 'role (dean, chairperson or instructor), person_external_id, and what they lead or teach: department_code for a dean, sub_department_code for a chairperson, course_external_id (and section) for an instructor. People and courses must be known already or be in the same upload. Whoever is no longer listed, among those a file like this assigned before, is ended.'],
];
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', [
        'title'       => 'Upload from CSV',
        'icon'        => 'user',
        'description' => 'Add many students, instructors, courses, enrolments and staff assignments at once from spreadsheet files.',
        'backUrl'     => site_url('institution/people'),
        'backLabel'   => 'Back to people',
    ], ['saveData' => false]) ?>

    <?= view('components/stepper', ['steps' => ['Upload', 'Preview', 'Apply'], 'current' => $uploadStep], ['saveData' => false]) ?>

    <?php if (! $uploadCanEdit): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php elseif ($uploadBlocked): ?>
        <?= view('components/lock-note', ['kind' => 'sis', 'text' => 'Your student information system supplies this data, so files cannot be uploaded while it is connected. Change it there; the next sync brings it here.'], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if ($uploadStep === 1): ?>

        <form method="post" action="<?= esc(site_url($base)) ?>" enctype="multipart/form-data" class="flex flex-col gap-5 rounded-2xl bg-white p-6 shadow-sm" data-upload-form>
            <?= csrf_field() ?>

            <p class="text-sm text-[#3D3D3D]">Choose the files you have; none is required, but at least one is. People and courses go in first, then enrolments and staff assignments, so those files can refer to people and courses in the same upload. Nothing is saved until you have looked at the preview.</p>

            <?php foreach ($uploadDatasets as $dataset): ?>
                <div class="flex flex-col gap-1" data-field="<?= esc($dataset) ?>">
                    <label class="text-sm font-semibold text-[#1A1C1E]" for="upload-<?= esc($dataset) ?>"><?= esc($labels[$dataset][0] ?? $dataset) ?> (<?= esc($dataset) ?>.csv)</label>
                    <input id="upload-<?= esc($dataset) ?>" name="<?= esc($dataset) ?>" type="file" accept=".csv,text/csv" aria-describedby="upload-<?= esc($dataset) ?>-help"
                           class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm file:mr-3 file:rounded-full file:border-0 file:bg-brand-tint file:px-4 file:py-1.5 file:text-sm file:font-semibold file:text-brand" <?= $uploadBlocked || ! $uploadCanEdit ? 'disabled' : '' ?>>
                    <p id="upload-<?= esc($dataset) ?>-help" class="text-xs text-[#646464]">
                        Columns: <?= esc($labels[$dataset][1] ?? '') ?>
                        <a class="<?= $link ?> ml-1" href="<?= esc(site_url($base . '/template/' . $dataset)) ?>">Download a template</a>
                    </p>
                </div>
            <?php endforeach; ?>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <a class="<?= $second ?>" href="<?= esc(site_url('institution/people')) ?>">Cancel</a>
                <button type="submit" class="<?= $primary ?>" <?= $uploadBlocked || ! $uploadCanEdit ? 'disabled' : '' ?>>Preview the changes</button>
            </div>
        </form>

    <?php else: ?>

        <?php
        $totals = $uploadKept['totals'];
        $names  = ['created' => 'Will be created', 'updated' => 'Will be updated', 'skipped' => 'Already as uploaded', 'failed' => 'Refused'];
        $tones  = ['created' => 'success', 'updated' => 'info', 'skipped' => 'neutral', 'failed' => $totals['failed'] > 0 ? 'danger' : 'neutral'];
        $ended  = (int) ($totals['ended'] ?? 0);

        // A card for what would be ended only when something would be: it is not a change to a record but somebody
        // losing a job (a dean, a chairperson, an instructor the SIS-style file no longer lists), so it stands apart.
        if ($ended > 0) {
            $names['ended'] = 'Will be ended';
            $tones['ended'] = 'warning';
        }

        $apply = $totals['created'] + $totals['updated'] + $ended;
        ?>

        <div class="grid grid-cols-2 gap-4 <?= $ended > 0 ? 'lg:grid-cols-5' : 'lg:grid-cols-4' ?>" data-upload-totals>
            <?php foreach ($names as $key => $label): ?>
                <?= view('components/metric-card', ['label' => $label, 'value' => (string) $totals[$key], 'icon' => $key === 'failed' ? 'warning' : 'info', 'tone' => in_array($tones[$key], ['danger', 'success', 'warning'], true) ? $tones[$key] : 'neutral'], ['saveData' => false]) ?>
            <?php endforeach; ?>
        </div>

        <section class="rounded-2xl bg-white p-6 shadow-sm" aria-label="Files">
            <h2 class="text-sm font-bold text-brand">By file</h2>
            <ul class="mt-3 flex flex-col gap-2 text-sm">
                <?php foreach ($uploadKept['datasets'] as $dataset => $counts): ?>
                    <li class="flex flex-wrap items-center justify-between gap-2" data-upload-file="<?= esc($dataset) ?>">
                        <span class="font-medium"><?= esc($dataset) ?>.csv</span>
                        <span class="text-[#646464]"><?= esc((string) $counts['created']) ?> to create · <?= esc((string) $counts['updated']) ?> to update · <?= esc((string) $counts['skipped']) ?> unchanged<?= isset($counts['ended']) ? ' · ' . esc((string) $counts['ended']) . ' to end' : '' ?> · <?= esc((string) $counts['failed']) ?> refused</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <?php if ($uploadKept['issues'] !== []): ?>
            <section class="rounded-2xl bg-white p-6 shadow-sm" aria-label="Refused rows" data-upload-issues>
                <h2 class="text-sm font-bold text-[#C33939]">Refused rows</h2>
                <p class="mt-1 text-xs text-[#646464]">These rows are left out. Everything else is still applied.</p>
                <ul class="mt-3 flex flex-col gap-1 text-sm">
                    <?php foreach (array_slice($uploadKept['issues'], 0, 50) as $issue): ?>
                        <li><span class="font-medium"><?= esc($issue['dataset']) ?>.csv <?= esc($issue['item']) ?>:</span> <?= esc($issue['reason']) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($uploadKept['issues']) > 50): ?>
                    <p class="mt-2 text-xs text-[#646464]">And <?= esc((string) (count($uploadKept['issues']) - 50)) ?> more.</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <p class="rounded-xl bg-brand-tint px-4 py-3 text-sm text-brand-dark" data-upload-nothing-saved>Nothing has been saved yet.</p>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <?php if ($uploadCanEdit && ! $uploadBlocked): ?>
                <form method="post" action="<?= esc(site_url($base . '/' . $uploadToken . '/discard')) ?>"><?= csrf_field() ?><button type="submit" class="<?= $second ?>">Start over</button></form>
                <form method="post" action="<?= esc(site_url($base . '/' . $uploadToken . '/apply')) ?>"><?= csrf_field() ?>
                    <button type="submit" class="<?= $primary ?>" <?= $apply === 0 ? 'disabled' : '' ?>>Apply <?= esc((string) $apply) ?> change<?= $apply === 1 ? '' : 's' ?></button>
                </form>
            <?php else: ?>
                <a class="<?= $second ?>" href="<?= esc(site_url($base)) ?>">Back</a>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</div>

<?= $this->endSection() ?>
