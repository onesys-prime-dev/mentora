<?php
/**
 * The institutional administrator's home.
 *
 * @var array<string, mixed> $homeInstitution
 * @var array<string, mixed> $homeSnapshot from InstitutionSnapshot::for()
 * @var array<string, mixed> $homeUsage    from PlanLimits::report()
 */
$statusTone  = ['active' => 'success', 'pending' => 'info', 'read_only' => 'warning', 'past' => 'danger'];
$statusLabel = ['active' => 'Active', 'pending' => 'Pending', 'read_only' => 'Read-only', 'past' => 'Past'];
$status      = (string) $homeInstitution['status'];

$roleLabels = ['student' => 'Students', 'instructor' => 'Instructors', 'chairperson' => 'Chairpersons', 'institutional_admin' => 'Administrators', 'parent' => 'Parents'];
$term       = $homeSnapshot['activeTerm'];
$canExport  = in_array($status, ['read_only', 'past'], true);
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-8 py-3">

    <?= view('components/page-head', [
        'title'       => (string) $homeInstitution['name'],
        'icon'        => 'hat',
        'description' => 'Your institution at a glance.',
        'actions'     => view('components/status-badge', ['label' => $statusLabel[$status] ?? ucfirst($status), 'tone' => $statusTone[$status] ?? 'neutral'], ['saveData' => false]),
    ], ['saveData' => false]) ?>

    <?php if ($status === 'read_only'): ?>
        <?= view('components/alert', ['tone' => 'warning', 'title' => 'Read-only', 'text' => 'The subscription has ended. You can still look at everything and take a full export of your data.'], ['saveData' => false]) ?>
    <?php elseif ($status === 'past'): ?>
        <?= view('components/alert', ['tone' => 'danger', 'title' => 'No longer active', 'text' => 'This institution is past. You can still take a full export of your data.'], ['saveData' => false]) ?>
    <?php endif; ?>

    <section class="flex flex-col gap-4" aria-labelledby="home-snapshot" data-section="snapshot">
        <h2 id="home-snapshot" class="text-lg font-bold text-[#0040A6]">Snapshot</h2>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <?= view('components/metric-card', [
                'label'  => 'People',
                'value'  => number_format($homeSnapshot['peopleTotal']),
                'icon'   => 'user',
                'detail' => number_format($homeSnapshot['people']['student']) . ' students, ' . number_format($homeSnapshot['people']['instructor']) . ' instructors',
            ], ['saveData' => false]) ?>
            <?= view('components/metric-card', [
                'label'  => 'Courses',
                'value'  => number_format($homeSnapshot['courses']),
                'icon'   => 'hat',
                'detail' => 'Not archived',
            ], ['saveData' => false]) ?>
            <?= view('components/metric-card', [
                'label'  => 'Offerings this term',
                'value'  => number_format($homeSnapshot['offerings']),
                'icon'   => 'modules',
                'detail' => $term === null ? 'No term is active' : $term['name'] . ' ' . $term['academic_year'],
                'tone'   => $term === null ? 'warning' : 'neutral',
            ], ['saveData' => false]) ?>
            <?= view('components/metric-card', [
                'label'  => 'Enrolments this term',
                'value'  => number_format($homeSnapshot['enrolments']),
                'icon'   => 'lessons',
                'detail' => 'Students currently enrolled',
            ], ['saveData' => false]) ?>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-3">

        <section class="flex flex-col gap-4 rounded-2xl bg-white p-6 shadow-sm lg:col-span-2" aria-labelledby="home-plan" data-section="plan">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="home-plan" class="text-lg font-bold text-[#0040A6]">Plan and usage</h2>
                <span class="text-sm text-[#646464]" data-plan-name><?= $homeUsage['plan'] !== null ? esc((string) $homeUsage['plan']) : 'No plan yet' ?></span>
            </div>

            <?= view('components/usage-bar', ['label' => 'Student seats', 'used' => $homeUsage['seats']['used'], 'limit' => $homeUsage['seats']['limit']], ['saveData' => false]) ?>
            <?= view('components/usage-bar', ['label' => 'Storage', 'used' => $homeUsage['storage']['used_mb'], 'limit' => $homeUsage['storage']['limit_mb'], 'unitLabel' => 'MB'], ['saveData' => false]) ?>
            <?= view('components/usage-bar', ['label' => 'AI generations this term', 'used' => $homeUsage['ai']['used'], 'limit' => $homeUsage['ai']['limit']], ['saveData' => false]) ?>
        </section>

        <section class="flex flex-col gap-3 rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="home-people" data-section="people">
            <h2 id="home-people" class="text-lg font-bold text-[#0040A6]">People by role</h2>
            <ul class="flex flex-col divide-y divide-gray-100 text-sm">
                <?php foreach ($roleLabels as $role => $label): ?>
                    <li class="flex items-center justify-between py-2">
                        <span class="text-[#3D3D3D]"><?= esc($label) ?></span>
                        <span class="font-semibold text-[#1A1C1E]" data-role-count="<?= esc($role) ?>"><?= esc(number_format($homeSnapshot['people'][$role])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

    </div>

    <section class="flex flex-col gap-3 rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="home-next" data-section="next">
        <h2 id="home-next" class="text-lg font-bold text-[#0040A6]">What you can do here</h2>
        <p class="text-sm text-[#3D3D3D]">
            More tools arrive here as they are built: structure, terms, offerings, enrolment, people and reports.
            <?php if ($canExport): ?>
                Your data export is ready to take.
            <?php else: ?>
                A full export of your data becomes available when the subscription ends.
            <?php endif; ?>
        </p>
        <div>
            <a class="inline-flex rounded-full border border-[#0040A6] px-5 py-2 text-sm font-semibold text-[#0040A6] transition hover:bg-[#0040A6]/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50" href="<?= site_url('institution/export') ?>">Data export</a>
        </div>
    </section>

</div>

<?= $this->endSection() ?>
