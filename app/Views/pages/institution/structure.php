<?php
/**
 * The academic structure: departments, their sub-departments and programs.
 *
 * @var list<array<string, mixed>> $structureTree
 * @var array<string, int>         $structureCounts
 * @var bool                       $structureCanEdit
 * @var array<int, list<array<string, mixed>>> $structureChairs     active chairpersons by sub-department id
 * @var list<array<string, mixed>> $structureCandidates people who can be assigned
 */
$base    = 'institution/structure';
$primary = 'rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#002068] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50';
$second  = 'rounded-full border border-[#0040A6] px-4 py-1.5 text-sm font-semibold text-[#0040A6] transition hover:bg-[#0040A6]/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50';
$link    = 'text-sm font-semibold text-[#0040A6] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50 rounded';
$warn    = 'text-sm font-semibold text-[#C33939] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C33939]/50 rounded';

$badge = static fn (bool $active, string $label = ''): string => view('components/status-badge', [
    'label' => $label !== '' ? $label : ($active ? 'Active' : 'Inactive'),
    'tone'  => $active ? 'success' : 'neutral',
], ['saveData' => false]);

$json = static fn (array $values): string => esc(json_encode($values, JSON_UNESCAPED_UNICODE), 'attr');

$programTone = ['active' => 'success', 'inactive' => 'neutral', 'archived' => 'neutral'];
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', [
        'title'       => 'Structure',
        'icon'        => 'hat',
        'description' => 'Departments, their sub-departments and the programs under them. Courses and offerings hang below.',
        'backUrl'     => site_url('institution/dashboard'),
        'backLabel'   => 'Back to home',
        'actions'     => $structureCanEdit
            ? '<button type="button" class="' . $primary . '" data-dialog-open="structure-department" data-dialog-title="Add a department" data-dialog-action="' . esc(site_url($base . '/departments')) . '" data-dialog-label="Add department">Add a department</button>'
            : '',
    ], ['saveData' => false]) ?>

    <?= view('components/tabs', ['ariaLabel' => 'Academics', 'tabs' => [
        ['label' => 'Structure', 'href' => site_url('institution/structure'), 'active' => true],
        ['label' => 'Courses', 'href' => site_url('institution/courses')],
        ['label' => 'Terms', 'href' => site_url('institution/terms')],
        ['label' => 'Offerings', 'href' => site_url('institution/offerings')],
    ]], ['saveData' => false]) ?>

    <?php if (! $structureCanEdit): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <p class="text-sm text-[#646464]" aria-live="polite" data-structure-counts>
        <?= esc((string) $structureCounts['departments']) ?> <?= $structureCounts['departments'] === 1 ? 'department' : 'departments' ?>,
        <?= esc((string) $structureCounts['subDepartments']) ?> <?= $structureCounts['subDepartments'] === 1 ? 'sub-department' : 'sub-departments' ?>,
        <?= esc((string) $structureCounts['programs']) ?> <?= $structureCounts['programs'] === 1 ? 'program' : 'programs' ?>
    </p>

    <?php if ($structureTree === []): ?>

        <div class="rounded-2xl bg-white/70" data-structure-empty>
            <?= view('components/empty-state', [
                'icon'  => 'hat',
                'title' => 'No departments yet',
                'text'  => $structureCanEdit ? 'Add the first department to start building your structure.' : 'Departments will be listed here.',
            ], ['saveData' => false]) ?>
        </div>

    <?php endif; ?>

    <?php foreach ($structureTree as $department): ?>
        <?php $deptActive = (int) $department['is_active'] === 1; ?>

        <section class="flex flex-col gap-4 rounded-2xl bg-white p-6 shadow-sm" data-department="<?= esc((string) $department['id']) ?>" aria-labelledby="dept-<?= esc((string) $department['id']) ?>">

            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 flex-col gap-1">
                    <h2 id="dept-<?= esc((string) $department['id']) ?>" class="flex flex-wrap items-center gap-3 text-lg font-bold text-[#0040A6]">
                        <span class="rounded bg-[#F2F7FF] px-2 py-0.5 font-mono text-xs text-[#002068]"><?= esc($department['code']) ?></span>
                        <?= esc($department['name']) ?>
                        <?= $badge($deptActive) ?>
                    </h2>
                    <?php if (($department['location'] ?? '') !== '' || ($department['established_year'] ?? null) !== null): ?>
                        <p class="text-xs text-[#646464]">
                            <?= esc(implode(' · ', array_filter([(string) ($department['location'] ?? ''), $department['established_year'] !== null ? 'Established ' . $department['established_year'] : '']))) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <?php if ($structureCanEdit): ?>
                    <div class="flex flex-wrap items-center gap-4">
                        <button type="button" class="<?= $link ?>"
                            data-dialog-open="structure-department"
                            data-dialog-title="Edit department"
                            data-dialog-label="Save"
                            data-dialog-action="<?= esc(site_url($base . '/departments/' . $department['id'])) ?>"
                            data-dialog-values="<?= $json(['code' => $department['code'], 'name' => $department['name'], 'location' => (string) ($department['location'] ?? ''), 'established_year' => (string) ($department['established_year'] ?? '')]) ?>">Edit</button>

                        <?php if ($deptActive): ?>
                            <button type="button" class="<?= $link ?>"
                                data-dialog-open="structure-sub"
                                data-dialog-title="Add a sub-department to <?= esc($department['name']) ?>"
                                data-dialog-label="Add sub-department"
                                data-dialog-action="<?= esc(site_url($base . '/departments/' . $department['id'] . '/sub-departments')) ?>">Add sub-department</button>
                            <button type="button" class="<?= $warn ?>"
                                data-dialog-open="structure-confirm"
                                data-dialog-title="Deactivate <?= esc($department['name']) ?>?"
                                data-dialog-text="It stays in your records and its history is kept, but nothing new can be added under it. It must have no active sub-departments and no offerings in an active term."
                                data-dialog-label="Deactivate"
                                data-dialog-action="<?= esc(site_url($base . '/departments/' . $department['id'] . '/deactivate')) ?>">Deactivate</button>
                        <?php else: ?>
                            <form method="post" action="<?= esc(site_url($base . '/departments/' . $department['id'] . '/reactivate')) ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="<?= $link ?>">Reactivate</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($department['subDepartments'] === []): ?>
                <p class="rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-[#646464]" data-sub-empty>No sub-departments yet.</p>
            <?php endif; ?>

            <?php foreach ($department['subDepartments'] as $sub): ?>
                <?php $subActive = (int) $sub['is_active'] === 1; ?>

                <details class="group rounded-xl border border-gray-200" data-sub-department="<?= esc((string) $sub['id']) ?>" <?= $subActive ? 'open' : '' ?>>
                    <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-3 rounded-xl px-4 py-3 text-sm marker:content-none hover:bg-[#F2F7FF] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50">
                        <span class="flex flex-wrap items-center gap-3 font-semibold text-[#1A1C1E]">
                            <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs text-[#3D3D3D]"><?= esc($sub['code']) ?></span>
                            <?= esc($sub['name']) ?>
                            <?= $badge($subActive) ?>
                            <span class="text-xs font-normal text-[#646464]"><?= esc((string) count($sub['programs'])) ?> <?= count($sub['programs']) === 1 ? 'program' : 'programs' ?></span>
                        </span>
                    </summary>

                    <div class="flex flex-col gap-3 border-t border-gray-100 px-4 py-3">

                        <?php if ($structureCanEdit): ?>
                            <div class="flex flex-wrap items-center gap-4">
                                <button type="button" class="<?= $link ?>"
                                    data-dialog-open="structure-sub"
                                    data-dialog-title="Edit sub-department"
                                    data-dialog-label="Save"
                                    data-dialog-action="<?= esc(site_url($base . '/sub-departments/' . $sub['id'])) ?>"
                                    data-dialog-values="<?= $json(['code' => $sub['code'], 'name' => $sub['name']]) ?>">Edit</button>

                                <?php if ($subActive): ?>
                                    <button type="button" class="<?= $link ?>"
                                        data-dialog-open="structure-program"
                                        data-dialog-title="Add a program to <?= esc($sub['name']) ?>"
                                        data-dialog-label="Add program"
                                        data-dialog-action="<?= esc(site_url($base . '/sub-departments/' . $sub['id'] . '/programs')) ?>">Add program</button>
                                    <button type="button" class="<?= $warn ?>"
                                        data-dialog-open="structure-confirm"
                                        data-dialog-title="Deactivate <?= esc($sub['name']) ?>?"
                                        data-dialog-text="It stays in your records and its history is kept, but nothing new can be added under it. It must have no active programs and no offerings in an active term."
                                        data-dialog-label="Deactivate"
                                        data-dialog-action="<?= esc(site_url($base . '/sub-departments/' . $sub['id'] . '/deactivate')) ?>">Deactivate</button>
                                <?php elseif ($deptActive): ?>
                                    <form method="post" action="<?= esc(site_url($base . '/sub-departments/' . $sub['id'] . '/reactivate')) ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="<?= $link ?>">Reactivate</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php $chairs = $structureChairs[(int) $sub['id']] ?? []; ?>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm" data-chairs>
                            <span class="font-semibold text-[#3D3D3D]">Chairperson<?= count($chairs) === 1 ? '' : 's' ?>:</span>
                            <?php if ($chairs === []): ?>
                                <span class="text-[#646464]" data-chairs-empty>None assigned yet.</span>
                            <?php endif; ?>
                            <?php foreach ($chairs as $chair): ?>
                                <span class="inline-flex items-center gap-2 rounded-full bg-[#F2F7FF] px-3 py-1" data-chair="<?= esc((string) $chair['id']) ?>">
                                    <?= esc($chair['name']) ?>
                                    <?php if ($structureCanEdit): ?>
                                        <button type="button" class="rounded text-xs font-semibold text-[#C33939] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C33939]/50"
                                            aria-label="Remove <?= esc($chair['name']) ?> as chairperson"
                                            data-dialog-open="structure-confirm"
                                            data-dialog-title="Remove <?= esc($chair['name']) ?> as chairperson?"
                                            data-dialog-text="They keep their account and their other roles. They lose access to this sub-department's courses on their next request."
                                            data-dialog-label="Remove"
                                            data-dialog-action="<?= esc(site_url($base . '/chairpersons/' . $chair['id'] . '/remove')) ?>">Remove</button>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if ($structureCanEdit && $subActive): ?>
                                <button type="button" class="<?= $link ?>"
                                    data-dialog-open="structure-chair"
                                    data-dialog-title="Assign a chairperson to <?= esc($sub['name']) ?>"
                                    data-dialog-label="Assign"
                                    data-dialog-action="<?= esc(site_url($base . '/sub-departments/' . $sub['id'] . '/chairpersons')) ?>">Assign chairperson</button>
                            <?php endif; ?>
                        </div>

                        <?php if ($sub['programs'] === []): ?>
                            <p class="text-sm text-[#646464]" data-program-empty>No programs yet.</p>
                        <?php else: ?>
                            <ul class="flex flex-col divide-y divide-gray-100 text-sm" aria-label="Programs of <?= esc($sub['name']) ?>">
                                <?php foreach ($sub['programs'] as $program): ?>
                                    <li class="flex flex-wrap items-center justify-between gap-3 py-2" data-program="<?= esc((string) $program['id']) ?>">
                                        <span class="flex flex-wrap items-center gap-3">
                                            <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs text-[#3D3D3D]"><?= esc($program['code']) ?></span>
                                            <span class="font-medium text-[#1A1C1E]"><?= esc($program['name']) ?></span>
                                            <?= view('components/status-badge', ['label' => ucfirst((string) $program['status']), 'tone' => $programTone[$program['status']] ?? 'neutral'], ['saveData' => false]) ?>
                                            <span class="text-xs text-[#646464]"><?= esc((string) $program['course_count']) ?> <?= (int) $program['course_count'] === 1 ? 'course' : 'courses' ?></span>
                                        </span>

                                        <?php if ($structureCanEdit): ?>
                                            <span class="flex flex-wrap items-center gap-4">
                                                <button type="button" class="<?= $link ?>"
                                                    data-dialog-open="structure-program"
                                                    data-dialog-title="Edit program"
                                                    data-dialog-label="Save"
                                                    data-dialog-action="<?= esc(site_url($base . '/programs/' . $program['id'])) ?>"
                                                    data-dialog-values="<?= $json(['code' => $program['code'], 'name' => $program['name']]) ?>">Edit</button>

                                                <?php if ($program['status'] === 'active'): ?>
                                                    <button type="button" class="<?= $warn ?>"
                                                        data-dialog-open="structure-confirm"
                                                        data-dialog-title="Deactivate <?= esc($program['name']) ?>?"
                                                        data-dialog-text="Students and instructors keep their history, but no new courses or offerings can be added. It must have no active courses and no offerings in an active term."
                                                        data-dialog-label="Deactivate"
                                                        data-dialog-action="<?= esc(site_url($base . '/programs/' . $program['id'] . '/deactivate')) ?>">Deactivate</button>
                                                <?php elseif ($subActive): ?>
                                                    <form method="post" action="<?= esc(site_url($base . '/programs/' . $program['id'] . '/reactivate')) ?>">
                                                        <?= csrf_field() ?>
                                                        <button type="submit" class="<?= $link ?>">Reactivate</button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($program['status'] !== 'archived'): ?>
                                                    <button type="button" class="<?= $link ?>"
                                                        data-dialog-open="structure-confirm"
                                                        data-dialog-title="Archive <?= esc($program['name']) ?>?"
                                                        data-dialog-text="Archiving is for programs that have ended. It is kept for reports and history. You can reactivate it later."
                                                        data-dialog-label="Archive"
                                                        data-dialog-action="<?= esc(site_url($base . '/programs/' . $program['id'] . '/archive')) ?>">Archive</button>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                    </div>
                </details>
            <?php endforeach; ?>

        </section>
    <?php endforeach; ?>

</div>

<?php if ($structureCanEdit): ?>
    <?php
    // The buttons sit inside the form, so a send can disable them while it runs.
    $form = static function (string $id, string $fields): array {
        $body = '<form id="' . $id . '-form" method="post" action="" data-dialog-form class="flex flex-col gap-4">'
            . csrf_field() . $fields
            . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>'
            . '<div class="flex flex-wrap items-center justify-end gap-3">'
            . '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>'
            . '<button type="submit" data-dialog-label class="rounded-full bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#002068]">Save</button>'
            . '</div></form>';

        return [$body, ''];
    };

    $code = view('components/form-field', ['name' => 'code', 'label' => 'Code', 'required' => true, 'maxlength' => 50, 'help' => 'Letters, numbers, dots, dashes or underscores. Shown in capitals.', 'attrs' => 'autocomplete="off"'], ['saveData' => false]);
    $name = view('components/form-field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'maxlength' => 255, 'attrs' => 'autocomplete="off"'], ['saveData' => false]);

    [$deptBody, $deptFooter] = $form('structure-department', $code . $name
        . view('components/form-field', ['name' => 'location', 'label' => 'Location', 'maxlength' => 255, 'help' => 'Optional. A building or campus.'], ['saveData' => false])
        . view('components/form-field', ['name' => 'established_year', 'label' => 'Year established', 'type' => 'number', 'attrs' => 'min="1800" inputmode="numeric"', 'help' => 'Optional.'], ['saveData' => false]));

    $candidateOptions = ['' => $structureCandidates === [] ? 'No one holds the chairperson role yet' : 'Choose a person'];

    foreach ($structureCandidates as $candidate) {
        $candidateOptions[(string) $candidate['id']] = $candidate['name'] . ' (' . $candidate['email'] . ')';
    }

    [$chairBody, $chairFooter] = $form('structure-chair', view('components/form-field', [
        'name' => 'user_id', 'label' => 'Chairperson', 'type' => 'select', 'options' => $candidateOptions, 'required' => true,
        'help' => 'People who hold the chairperson role in your institution. Give someone the role on the People page.',
    ], ['saveData' => false]));

    [$subBody, $subFooter]   = $form('structure-sub', $code . $name);
    [$progBody, $progFooter] = $form('structure-program', $code . $name);
    ?>

    <?= view('components/dialog', ['id' => 'structure-department', 'title' => 'Department', 'body' => $deptBody, 'footer' => $deptFooter], ['saveData' => false]) ?>
    <?= view('components/dialog', ['id' => 'structure-sub', 'title' => 'Sub-department', 'body' => $subBody, 'footer' => $subFooter], ['saveData' => false]) ?>
    <?= view('components/dialog', ['id' => 'structure-chair', 'title' => 'Assign a chairperson', 'body' => $chairBody, 'footer' => $chairFooter], ['saveData' => false]) ?>
    <?= view('components/dialog', ['id' => 'structure-program', 'title' => 'Program', 'body' => $progBody, 'footer' => $progFooter], ['saveData' => false]) ?>

    <?= view('components/confirm', [
        'id'           => 'structure-confirm',
        'title'        => 'Are you sure?',
        'text'         => 'This changes your structure.',
        'action'       => site_url($base),
        'confirmLabel' => 'Confirm',
        'tone'         => 'danger',
    ], ['saveData' => false]) ?>
<?php endif; ?>

<?= $this->endSection() ?>
