<?php
/**
 * The academic structure: colleges, their departments and programs.
 *
 * @var list<array<string, mixed>> $structureTree
 * @var array<string, int>         $structureCounts
 * @var bool                       $structureCanEdit
 * @var array<int, list<array<string, mixed>>> $structureChairs     active chairpersons by department id
 * @var array<int, true>                        $structureSisChairs  chairperson assignments the SIS supplies (not editable here)
 * @var list<array<string, mixed>> $structureCandidates people who can be assigned
 * @var array<int, array<string, mixed>> $structureDeans    active dean by college id
 * @var array<int, true>                 $structureSisDeans  dean assignments the SIS supplies (not editable here)
 * @var list<array<string, mixed>> $structureDeanCandidates people who hold the dean role
 */
$base    = 'institution/structure';
$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$second  = 'rounded-full border border-brand px-4 py-1.5 text-sm font-semibold text-brand transition hover:bg-brand/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$link    = 'text-sm font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded';
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

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url('institution/dashboard'), 'backLabel' => 'Back to home'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Structure',
        'icon'        => 'hat',
        'description' => 'Colleges, their departments and the programs under them.',
        'actions'     => $structureCanEdit
            ? '<button type="button" class="' . $primary . '" data-dialog-open="structure-college-new" data-dialog-title="Add a college" data-dialog-action="' . esc(site_url($base . '/colleges')) . '" data-dialog-label="Add college">Add a college</button>'
            : '',
    ], ['saveData' => false]) ?>

    <?php if (! $structureCanEdit): ?>
        <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if (session()->has('message')): ?>
        <?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?>
    <?php endif; ?>
    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <p class="flex flex-wrap gap-2 text-sm text-[#646464]" aria-live="polite" data-structure-counts>
        <?php foreach ([
            ['colleges', 'college', 'colleges'],
            ['departments', 'department', 'departments'],
            ['programs', 'program', 'programs'],
        ] as [$countKey, $one, $many]): ?>
            <span class="rounded-xl bg-brand/5 px-3 py-1.5"><strong class="text-base text-brand"><?= esc((string) $structureCounts[$countKey]) ?></strong> <?= $structureCounts[$countKey] === 1 ? $one : $many ?></span>
        <?php endforeach; ?>
    </p>

    <?php if ($structureTree === []): ?>

        <div class="rounded-2xl border border-gray-200 bg-gray-50" data-structure-empty>
            <?= view('components/empty-state', [
                'icon'  => 'hat',
                'title' => 'No colleges yet',
                'text'  => $structureCanEdit ? 'Add the first college to start building your structure.' : 'Colleges will be listed here.',
            ], ['saveData' => false]) ?>
        </div>

    <?php else: ?>

    <?php // From lg up the tree and the details each scroll on their own; the heading and the counts stay put. ?>
    <div class="grid gap-6 lg:grid-cols-[19rem_minmax(0,1fr)] lg:grid-rows-[minmax(0,1fr)]" data-panel-scroll="inner" data-structure-split>

        <aside class="flex flex-col gap-3 lg:min-h-0 lg:overflow-y-auto lg:border-r lg:border-gray-100 lg:pr-5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="Colleges and programs">

            <label class="sr-only" for="structure-search">Search colleges and programs</label>
            <input id="structure-search" type="search" placeholder="Search structure" autocomplete="off" spellcheck="false"
                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm placeholder:text-[#767676] focus:border-brand focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand/20" data-structure-search>

            <ul class="flex flex-col gap-1" data-structure-tree>
                <?php foreach ($structureTree as $college): ?>
                    <?php $collegeActive = (int) $college['is_active'] === 1; ?>
                    <li data-tree-college="<?= esc((string) $college['id']) ?>">
                        <button type="button" data-select="college-<?= esc((string) $college['id']) ?>" data-search-text="<?= esc(strtolower($college['code'] . ' ' . $college['name']), 'attr') ?>"
                            class="group/college flex w-full items-center gap-2 rounded-xl px-3 py-2 text-left text-sm font-semibold text-[#1A1C1E] transition hover:bg-brand/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 data-[active=true]:bg-brand/10 data-[active=true]:text-brand">
                            <span class="rounded bg-brand-tint px-1.5 py-0.5 font-mono text-[11px] text-brand-dark"><?= esc($college['code']) ?></span>
                            <span class="min-w-0 flex-1 break-words leading-snug <?= $collegeActive ? '' : 'text-[#646464]' ?>"><?= esc($college['name']) ?></span>
                            <span class="text-xs font-normal text-[#646464]"><?= esc((string) count($college['departments'])) ?></span>
                            <?php if ($college['departments'] !== []): ?>
                                <svg class="h-4 w-4 shrink-0 text-[#646464] transition-transform group-data-[open=true]/college:rotate-90" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
                            <?php endif; ?>
                        </button>

                        <?php if ($college['departments'] !== []): ?>
                            <ul class="ml-5 mt-1 flex flex-col gap-1 border-l border-gray-100 pl-2" data-tree-children hidden>
                                <?php foreach ($college['departments'] as $department): ?>
                                    <?php $departmentActive = (int) $department['is_active'] === 1; ?>
                                    <li>
                                        <button type="button" data-select="department-<?= esc((string) $department['id']) ?>" data-search-text="<?= esc(strtolower($department['code'] . ' ' . $department['name'] . ' ' . implode(' ', array_map(static fn (array $p): string => $p['code'] . ' ' . $p['name'], $department['programs']))), 'attr') ?>"
                                            class="flex w-full items-center gap-2 rounded-xl px-3 py-1.5 text-left text-sm text-[#1A1C1E] transition hover:bg-brand/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 data-[active=true]:bg-brand/10 data-[active=true]:font-semibold data-[active=true]:text-brand">
                                            <span class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-[#646464]"><?= esc($department['code']) ?></span>
                                            <span class="min-w-0 flex-1 break-words leading-snug <?= $departmentActive ? '' : 'text-[#646464]' ?>"><?= esc($department['name']) ?></span>
                                            <span class="text-xs text-[#646464]"><?= esc((string) count($department['programs'])) ?></span>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <p class="hidden px-3 py-4 text-sm text-[#646464]" data-structure-search-empty>Nothing matches that search.</p>
        </aside>

        <div class="flex min-w-0 flex-col lg:min-h-0 lg:overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" data-structure-detail tabindex="-1">

        <?php foreach ($structureTree as $college): ?>
            <?php $collegeActive = (int) $college['is_active'] === 1; ?>

            <section class="flex flex-col gap-6" data-college="<?= esc((string) $college['id']) ?>" aria-labelledby="college-<?= esc((string) $college['id']) ?>" hidden>

                <?php // The college itself ?>
                <div class="flex flex-col gap-5" data-view="college-<?= esc((string) $college['id']) ?>" hidden>

                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex min-w-0 flex-col gap-1">
                            <h2 id="college-<?= esc((string) $college['id']) ?>" class="flex flex-wrap items-center gap-3 text-lg font-bold text-brand">
                                <span class="rounded bg-brand-tint px-2 py-0.5 font-mono text-xs text-brand-dark"><?= esc($college['code']) ?></span>
                                <?= esc($college['name']) ?>
                                <?= $badge($collegeActive) ?>
                            </h2>
                            <p class="text-xs text-[#646464]">
                                College<?= (($college['location'] ?? '') !== '' || ($college['established_year'] ?? null) !== null) ? ' · ' . esc(implode(' · ', array_filter([(string) ($college['location'] ?? ''), $college['established_year'] !== null ? 'Established ' . $college['established_year'] : '']))) : '' ?>
                            </p>
                        </div>

                        <?php if ($structureCanEdit): ?>
                            <div class="flex flex-wrap items-center gap-3">
                                <button type="button" class="<?= $second ?>"
                                    data-dialog-open="structure-college"
                                    data-dialog-title="Edit college"
                                    data-dialog-label="Save"
                                    data-dialog-action="<?= esc(site_url($base . '/colleges/' . $college['id'])) ?>"
                                    data-dialog-values="<?= $json(['code' => $college['code'], 'name' => $college['name'], 'location' => (string) ($college['location'] ?? ''), 'established_year' => (string) ($college['established_year'] ?? '')]) ?>">Edit</button>

                                <?php if ($collegeActive): ?>
                                    <button type="button" class="<?= $second ?>"
                                        data-dialog-open="structure-sub"
                                        data-dialog-title="Add a department to <?= esc($college['name']) ?>"
                                        data-dialog-label="Add department"
                                        data-dialog-action="<?= esc(site_url($base . '/colleges/' . $college['id'] . '/departments')) ?>">Add department</button>
                                    <button type="button" class="<?= $warn ?>"
                                        data-dialog-open="structure-confirm"
                                        data-dialog-title="Deactivate <?= esc($college['name']) ?>?"
                                        data-dialog-text="It stays in your records and its history is kept, but nothing new can be added under it. It must have no active departments and no offerings in an active term."
                                        data-dialog-label="Deactivate"
                                        data-dialog-action="<?= esc(site_url($base . '/colleges/' . $college['id'] . '/deactivate')) ?>">Deactivate</button>
                                <?php else: ?>
                                    <form method="post" action="<?= esc(site_url($base . '/colleges/' . $college['id'] . '/reactivate')) ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="<?= $link ?>">Reactivate</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php $dean = $structureDeans[(int) $college['id']] ?? null; ?>
                    <div class="flex flex-col gap-2" data-dean>
                        <h3 class="flex items-center justify-between gap-3 text-xs font-semibold uppercase tracking-wide text-[#646464]">
                            Dean
                            <?php if ($structureCanEdit && $collegeActive && ($dean === null || ! isset($structureSisDeans[(int) $dean['id']]))): ?>
                                <button type="button" class="text-sm font-semibold normal-case tracking-normal text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded"
                                    data-dialog-open="structure-dean"
                                    data-dialog-title="<?= $dean === null ? 'Assign a dean to ' . esc($college['name']) : 'Replace ' . esc($dean['name']) . ' as dean of ' . esc($college['name']) . '? Their assignment ends.' ?>"
                                    data-dialog-label="<?= $dean === null ? 'Assign' : 'Replace' ?>"
                                    data-dialog-action="<?= esc(site_url($base . '/colleges/' . $college['id'] . '/deans')) ?>"><?= $dean === null ? 'Assign dean' : 'Replace dean' ?></button>
                            <?php endif; ?>
                        </h3>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <?php if ($dean === null): ?>
                                <span class="rounded-full border border-dashed border-gray-300 px-3 py-1 text-[#646464]" data-dean-empty>None assigned yet.</span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-2 rounded-full bg-brand-tint px-3 py-1" data-dean-name="<?= esc((string) $dean['id']) ?>">
                                    <?= esc($dean['name']) ?>
                                    <?php if (isset($structureSisDeans[(int) $dean['id']])): ?>
                                        <span class="inline-flex items-center gap-1 text-xs text-[#646464]" data-sis-managed title="Managed by your student information system. Change it there; the next sync brings it here."><?= icon('lock', 'h-3 w-3') ?>From the SIS</span>
                                    <?php elseif ($structureCanEdit): ?>
                                        <button type="button" class="rounded text-xs font-semibold text-[#C33939] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C33939]/50"
                                            aria-label="Remove <?= esc($dean['name']) ?> as dean"
                                            data-dialog-open="structure-confirm"
                                            data-dialog-title="Remove <?= esc($dean['name']) ?> as dean?"
                                            data-dialog-text="They keep their account and their other roles. Approvals and sign-off for this college go to the institution's administrators until another dean is assigned."
                                            data-dialog-label="Remove"
                                            data-dialog-action="<?= esc(site_url($base . '/deans/' . $dean['id'] . '/remove')) ?>">Remove</button>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-[#646464]">Departments</h3>

                        <?php if ($college['departments'] === []): ?>
                            <p class="rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-[#646464]" data-department-empty>No departments yet.</p>
                        <?php else: ?>
                            <ul class="flex flex-col divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 text-sm">
                                <?php foreach ($college['departments'] as $department): ?>
                                    <?php $departmentActive = (int) $department['is_active'] === 1; ?>
                                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" data-department-row="<?= esc((string) $department['id']) ?>">
                                        <span class="flex flex-wrap items-center gap-3 font-medium text-[#1A1C1E]">
                                            <span class="rounded bg-brand-tint px-2 py-0.5 font-mono text-xs font-semibold text-brand-dark"><?= esc($department['code']) ?></span>
                                            <?= esc($department['name']) ?>
                                            <?= $badge($departmentActive) ?>
                                        </span>
                                        <span class="flex items-center gap-4">
                                            <span class="text-xs text-[#646464]"><?= esc((string) count($department['programs'])) ?> <?= count($department['programs']) === 1 ? 'program' : 'programs' ?></span>
                                            <button type="button" class="<?= $link ?>" data-select="department-<?= esc((string) $department['id']) ?>">Open</button>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <?php foreach ($college['departments'] as $department): ?>
                    <?php $departmentActive = (int) $department['is_active'] === 1; ?>

                    <?php // One department ?>
                    <div class="flex flex-col gap-5" data-department="<?= esc((string) $department['id']) ?>" data-view="department-<?= esc((string) $department['id']) ?>" hidden>

                        <button type="button" class="flex w-fit items-center gap-1 text-xs font-semibold uppercase tracking-wide text-[#646464] hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded" data-select="college-<?= esc((string) $college['id']) ?>">
                            <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg><?= esc($college['name']) ?>
                        </button>

                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex min-w-0 flex-col gap-1">
                                <h2 class="flex flex-wrap items-center gap-3 text-lg font-bold text-[#1A1C1E]">
                                    <span class="rounded bg-brand-tint px-2 py-0.5 font-mono text-xs font-semibold text-brand-dark"><?= esc($department['code']) ?></span>
                                    <?= esc($department['name']) ?>
                                    <?= $badge($departmentActive) ?>
                                </h2>
                                <p class="text-xs text-[#646464]">Department of <?= esc($college['name']) ?> · <?= esc((string) count($department['programs'])) ?> <?= count($department['programs']) === 1 ? 'program' : 'programs' ?></p>
                            </div>

                            <?php if ($structureCanEdit): ?>
                                <div class="flex flex-wrap items-center gap-3">
                                    <button type="button" class="<?= $second ?>"
                                        data-dialog-open="structure-sub"
                                        data-dialog-title="Edit department"
                                        data-dialog-label="Save"
                                        data-dialog-action="<?= esc(site_url($base . '/departments/' . $department['id'])) ?>"
                                        data-dialog-values="<?= $json(['code' => $department['code'], 'name' => $department['name']]) ?>">Edit</button>

                                    <?php if ($departmentActive): ?>
                                        <button type="button" class="<?= $second ?>"
                                            data-dialog-open="structure-program"
                                            data-dialog-title="Add a program to <?= esc($department['name']) ?>"
                                            data-dialog-label="Add program"
                                            data-dialog-action="<?= esc(site_url($base . '/departments/' . $department['id'] . '/programs')) ?>">Add program</button>
                                        <button type="button" class="<?= $warn ?>"
                                            data-dialog-open="structure-confirm"
                                            data-dialog-title="Deactivate <?= esc($department['name']) ?>?"
                                            data-dialog-text="It stays in your records and its history is kept, but nothing new can be added under it. It must have no active programs and no offerings in an active term."
                                            data-dialog-label="Deactivate"
                                            data-dialog-action="<?= esc(site_url($base . '/departments/' . $department['id'] . '/deactivate')) ?>">Deactivate</button>
                                    <?php elseif ($collegeActive): ?>
                                        <form method="post" action="<?= esc(site_url($base . '/departments/' . $department['id'] . '/reactivate')) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="<?= $link ?>">Reactivate</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php $chairs = $structureChairs[(int) $department['id']] ?? []; ?>
                        <div class="flex flex-col gap-2" data-chairs>
                            <h3 class="flex items-center justify-between gap-3 text-xs font-semibold uppercase tracking-wide text-[#646464]">
                                Chairperson
                                <?php if ($structureCanEdit && $departmentActive && $chairs === []): ?>
                                    <button type="button" class="text-sm font-semibold normal-case tracking-normal text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded"
                                        data-dialog-open="structure-chair"
                                        data-dialog-title="Assign a chairperson to <?= esc($department['name']) ?>"
                                        data-dialog-label="Assign"
                                        data-dialog-action="<?= esc(site_url($base . '/departments/' . $department['id'] . '/chairpersons')) ?>">Assign chairperson</button>
                                <?php elseif ($structureCanEdit && $departmentActive && ! isset($structureSisChairs[(int) $chairs[0]['id']])): ?>
                                    <?php // One chairperson per department (16.0): choosing someone else replaces them, once confirmed here. ?>
                                    <button type="button" class="text-sm font-semibold normal-case tracking-normal text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded"
                                        data-dialog-open="structure-chair"
                                        data-dialog-title="Replace <?= esc($chairs[0]['name']) ?> as chairperson of <?= esc($department['name']) ?>? Their assignment ends and they are told."
                                        data-dialog-label="Replace"
                                        data-dialog-values="<?= esc(json_encode(['replace' => '1']), 'attr') ?>"
                                        data-dialog-action="<?= esc(site_url($base . '/departments/' . $department['id'] . '/chairpersons')) ?>">Replace chairperson</button>
                                <?php endif; ?>
                            </h3>
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <?php if ($chairs === []): ?>
                                    <span class="rounded-full border border-dashed border-gray-300 px-3 py-1 text-[#646464]" data-chairs-empty>None assigned yet.</span>
                                <?php endif; ?>
                                <?php foreach ($chairs as $chair): ?>
                                    <span class="inline-flex items-center gap-2 rounded-full bg-brand-tint px-3 py-1" data-chair="<?= esc((string) $chair['id']) ?>">
                                        <?= esc($chair['name']) ?>
                                        <?php if (isset($structureSisChairs[(int) $chair['id']])): ?>
                                            <span class="inline-flex items-center gap-1 text-xs text-[#646464]" data-sis-managed title="Managed by your student information system. Change it there; the next sync brings it here."><?= icon('lock', 'h-3 w-3') ?>From the SIS</span>
                                        <?php elseif ($structureCanEdit): ?>
                                            <button type="button" class="rounded text-xs font-semibold text-[#C33939] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C33939]/50"
                                                aria-label="Remove <?= esc($chair['name']) ?> as chairperson"
                                                data-dialog-open="structure-confirm"
                                                data-dialog-title="Remove <?= esc($chair['name']) ?> as chairperson?"
                                                data-dialog-text="They keep their account and their other roles. They lose access to this department's courses on their next request."
                                                data-dialog-label="Remove"
                                                data-dialog-action="<?= esc(site_url($base . '/chairpersons/' . $chair['id'] . '/remove')) ?>">Remove</button>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2">
                            <h3 class="flex items-center justify-between gap-3 text-xs font-semibold uppercase tracking-wide text-[#646464]">
                                Programs
                                <?php if ($structureCanEdit && $departmentActive): ?>
                                    <button type="button" class="text-sm font-semibold normal-case tracking-normal text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 rounded"
                                        data-dialog-open="structure-program"
                                        data-dialog-title="Add a program to <?= esc($department['name']) ?>"
                                        data-dialog-label="Add program"
                                        data-dialog-action="<?= esc(site_url($base . '/departments/' . $department['id'] . '/programs')) ?>">Add program</button>
                                <?php endif; ?>
                            </h3>

                            <?php if ($department['programs'] === []): ?>
                                <p class="rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-[#646464]" data-program-empty>No programs yet.</p>
                            <?php else: ?>
                                <ul class="flex flex-col divide-y divide-gray-100 rounded-xl border border-gray-200 text-sm" aria-label="Programs of <?= esc($department['name']) ?>">
                                    <?php foreach ($department['programs'] as $program): ?>
                                        <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" data-program="<?= esc((string) $program['id']) ?>">
                                            <span class="flex flex-wrap items-center gap-3">
                                                <span class="rounded bg-[#E6F4EC] px-2 py-0.5 font-mono text-xs font-semibold text-[#1E7A44]"><?= esc($program['code']) ?></span>
                                                <span class="font-medium text-[#1A1C1E]"><?= esc($program['name']) ?></span>
                                                <?= view('components/status-badge', ['label' => ucfirst((string) $program['status']), 'tone' => $programTone[$program['status']] ?? 'neutral'], ['saveData' => false]) ?>
                                            </span>

                                            <span class="flex items-center gap-3">
                                                <span class="text-xs text-[#646464]"><?= esc((string) $program['course_count']) ?> <?= (int) $program['course_count'] === 1 ? 'course' : 'courses' ?></span>

                                                <?php if ($structureCanEdit): ?>
                                                    <details class="relative" data-row-menu>
                                                        <summary class="flex h-8 w-8 cursor-pointer list-none items-center justify-center rounded-lg border border-gray-200 text-[#3D3D3D] marker:content-none hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" aria-label="Actions for <?= esc($program['name']) ?>">⋯</summary>
                                                        <div class="absolute right-0 z-10 mt-1 flex min-w-44 flex-col rounded-xl border border-gray-200 bg-white p-1 shadow-lg">
                                                            <button type="button" class="rounded-lg px-3 py-2 text-left text-sm hover:bg-gray-50"
                                                                data-dialog-open="structure-program"
                                                                data-dialog-title="Edit program"
                                                                data-dialog-label="Save"
                                                                data-dialog-action="<?= esc(site_url($base . '/programs/' . $program['id'])) ?>"
                                                                data-dialog-values="<?= $json(['code' => $program['code'], 'name' => $program['name']]) ?>">Edit</button>

                                                            <?php if ($program['status'] === 'active'): ?>
                                                                <button type="button" class="rounded-lg px-3 py-2 text-left text-sm text-[#C33939] hover:bg-gray-50"
                                                                    data-dialog-open="structure-confirm"
                                                                    data-dialog-title="Deactivate <?= esc($program['name']) ?>?"
                                                                    data-dialog-text="Students and instructors keep their history, but no new courses or offerings can be added. It must have no active courses and no offerings in an active term."
                                                                    data-dialog-label="Deactivate"
                                                                    data-dialog-action="<?= esc(site_url($base . '/programs/' . $program['id'] . '/deactivate')) ?>">Deactivate</button>
                                                            <?php elseif ($departmentActive): ?>
                                                                <form method="post" action="<?= esc(site_url($base . '/programs/' . $program['id'] . '/reactivate')) ?>">
                                                                    <?= csrf_field() ?>
                                                                    <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-gray-50">Reactivate</button>
                                                                </form>
                                                            <?php endif; ?>

                                                            <?php if ($program['status'] !== 'archived'): ?>
                                                                <button type="button" class="rounded-lg px-3 py-2 text-left text-sm hover:bg-gray-50"
                                                                    data-dialog-open="structure-confirm"
                                                                    data-dialog-title="Archive <?= esc($program['name']) ?>?"
                                                                    data-dialog-text="Archiving is for programs that have ended. It is kept for reports and history. You can reactivate it later."
                                                                    data-dialog-label="Archive"
                                                                    data-dialog-action="<?= esc(site_url($base . '/programs/' . $program['id'] . '/archive')) ?>">Archive</button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </details>
                                                <?php endif; ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

            </section>
        <?php endforeach; ?>

        </div>

    </div>

    <?php endif; ?>

</div>

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
            . '<button type="submit" data-dialog-label class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">Save</button>'
            . '</div></form>';

        return [$body, ''];
    };

    // Built per dialog: each field needs its own id, or a label would point into another dialog.
    $code = static fn (): string => view('components/form-field', ['name' => 'code', 'label' => 'Code', 'required' => true, 'maxlength' => 50, 'help' => 'Letters, numbers, dots, dashes or underscores. Shown in capitals.', 'attrs' => 'autocomplete="off"'], ['saveData' => false]);
    $name = static fn (): string => view('components/form-field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'maxlength' => 255, 'attrs' => 'autocomplete="off"'], ['saveData' => false]);

    [$collegeBody, $collegeFooter] = $form('structure-college', $code() . $name()
        . view('components/form-field', ['name' => 'location', 'label' => 'Location', 'maxlength' => 255, 'help' => 'Optional. A building or campus.'], ['saveData' => false])
        . view('components/form-field', ['name' => 'established_year', 'label' => 'Year established', 'type' => 'number', 'attrs' => 'min="1800" inputmode="numeric"', 'help' => 'Optional.'], ['saveData' => false]));

    $deanOptions = ['' => $structureDeanCandidates === [] ? 'No one holds the dean role yet' : 'Choose a person'];

    foreach ($structureDeanCandidates as $candidate) {
        $deanOptions[(string) $candidate['id']] = $candidate['name'] . ' (' . $candidate['email'] . ')';
    }

    $deanHelp = 'People who hold the dean role in your institution. Give someone the role on the People page.';

    // Adding a college also picks its dean, so grades and requests there have someone to decide them.
    [$collegeNewBody, $collegeNewFooter] = $form('structure-college-new', $code() . $name()
        . view('components/form-field', ['name' => 'location', 'label' => 'Location', 'maxlength' => 255, 'help' => 'Optional. A building or campus.'], ['saveData' => false])
        . view('components/form-field', ['name' => 'established_year', 'label' => 'Year established', 'type' => 'number', 'attrs' => 'min="1800" inputmode="numeric"', 'help' => 'Optional.'], ['saveData' => false])
        . view('components/form-field', ['name' => 'dean_user_id', 'label' => 'Dean', 'type' => 'select', 'options' => $deanOptions, 'help' => $deanHelp . ' You can also assign one later.'], ['saveData' => false]));

    [$deanBody, $deanFooter] = $form('structure-dean', view('components/form-field', [
        'name' => 'user_id', 'label' => 'Dean', 'type' => 'select', 'options' => $deanOptions, 'required' => true, 'help' => $deanHelp,
    ], ['saveData' => false]));

    $candidateOptions = ['' => $structureCandidates === [] ? 'No one holds the chairperson role yet' : 'Choose a person'];

    foreach ($structureCandidates as $candidate) {
        $candidateOptions[(string) $candidate['id']] = $candidate['name'] . ' (' . $candidate['email'] . ')';
    }

    [$chairBody, $chairFooter] = $form('structure-chair', view('components/form-field', [
        'name' => 'user_id', 'label' => 'Chairperson', 'type' => 'select', 'options' => $candidateOptions, 'required' => true,
        'help' => 'People who hold the chairperson role in your institution. Give someone the role on the People page.',
    ], ['saveData' => false]) . '<input type="hidden" name="replace" value="">');

    [$departmentBody, $departmentFooter]   = $form('structure-sub', $code() . $name());
    [$progBody, $progFooter] = $form('structure-program', $code() . $name());
    ?>

    <?= view('components/dialog', ['id' => 'structure-college', 'title' => 'College', 'body' => $collegeBody, 'footer' => $collegeFooter], ['saveData' => false]) ?>
    <?= view('components/dialog', ['id' => 'structure-college-new', 'title' => 'Add a college', 'body' => $collegeNewBody, 'footer' => $collegeNewFooter], ['saveData' => false]) ?>
    <?= view('components/dialog', ['id' => 'structure-dean', 'title' => 'Assign a dean', 'body' => $deanBody, 'footer' => $deanFooter], ['saveData' => false]) ?>
    <?= view('components/dialog', ['id' => 'structure-sub', 'title' => 'Department', 'body' => $departmentBody, 'footer' => $departmentFooter], ['saveData' => false]) ?>
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

<?= $this->section('scripts') ?>

<script>
// The tree on the left chooses what the right-hand side shows. Nothing is fetched: every
// college and department is already in the page, only one of them is visible.
(function () {
    var detail = document.querySelector('[data-structure-detail]');
    if (!detail) return;

    var KEY = 'structure.selected';
    var buttons = document.querySelectorAll('[data-structure-tree] [data-select]');
    var views = detail.querySelectorAll('[data-view]');
    var colleges = detail.querySelectorAll('[data-college]');

    function remember(id) {
        try { sessionStorage.setItem(KEY, id); } catch (e) { /* private window */ }
        if (history.replaceState) history.replaceState(null, '', '#' + id);
    }

    // A college's row opens and closes the departments under it.
    function setExpanded(item, open) {
        var list = item.querySelector(':scope > [data-tree-children]');
        var head = item.querySelector(':scope > [data-select]');
        if (!list) return;
        list.hidden = !open;
        head.dataset.open = String(open);
        head.setAttribute('aria-expanded', String(open));
    }

    function expandOnlyActive() {
        document.querySelectorAll('[data-tree-college]').forEach(function (item) {
            setExpanded(item, item.querySelector('[data-active="true"]') !== null);
        });
    }

    function select(id, keep) {
        var view = detail.querySelector('[data-view="' + id + '"]');
        if (!view) return false;

        colleges.forEach(function (d) { d.hidden = !d.contains(view); });
        views.forEach(function (v) { v.hidden = v !== view; });
        buttons.forEach(function (b) { b.dataset.active = String(b.dataset.select === id); });
        expandOnlyActive();

        if (!keep) { detail.scrollTop = 0; remember(id); }
        return true;
    }

    document.addEventListener('click', function (event) {
        var target = event.target.closest('[data-select]');

        if (target) {
            var row = target.closest('[data-tree-college]');
            var isCollegeRow = row !== null && target.parentElement === row;
            var list = isCollegeRow ? row.querySelector(':scope > [data-tree-children]') : null;
            var wasOpen = list !== null && !list.hidden && target.dataset.active === 'true';

            if (select(target.dataset.select)) {
                // Clicking the college that is already showing folds it; any other click opens it.
                if (isCollegeRow && wasOpen) setExpanded(row, false);
                return;
            }
        }

        // A row's ⋯ menu closes when something inside it is chosen or the page is clicked elsewhere.
        document.querySelectorAll('[data-row-menu][open]').forEach(function (menu) {
            if (!menu.contains(event.target) || event.target.closest('button, [type="submit"]')) menu.open = false;
        });
    });

    var saved = null;
    try { saved = sessionStorage.getItem(KEY); } catch (e) { saved = null; }

    var first = detail.querySelector('[data-view^="college-"]');
    var start = location.hash.slice(1);

    if (!select(start, true) && !select(saved, true) && first) select(first.dataset.view, true);

    var search = document.querySelector('[data-structure-search]');
    var none = document.querySelector('[data-structure-search-empty]');

    if (search) {
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            var shown = 0;

            if (q === '') expandOnlyActive();

            document.querySelectorAll('[data-tree-college]').forEach(function (college) {
                var own = college.querySelector(':scope > [data-select]');
                var children = college.querySelectorAll('ul [data-select]');
                var collegeHit = q === '' || own.dataset.searchText.indexOf(q) !== -1;
                var any = collegeHit;

                children.forEach(function (c) {
                    var hit = q === '' || collegeHit || c.dataset.searchText.indexOf(q) !== -1;
                    c.parentElement.hidden = !hit;
                    any = any || hit;
                });

                college.hidden = !any;
                if (any) shown++;

                // While searching, a college with a match shows what matched.
                if (q !== '') setExpanded(college, any);
            });

            if (none) none.classList.toggle('hidden', shown > 0);
        });
    }
})();
</script>

<?= $this->endSection() ?>
