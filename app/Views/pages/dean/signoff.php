<?php
/**
 * The dean's term-end sign-off: the department's sections for a term, grouped by program. A section is
 * Ready (the instructor finalized the grades, no override request open), Blocked (and the page says
 * why) or Signed off. Signing off asks for the password again; "Sign off all ready" does a program's
 * ready sections at once and says how many first. Reopening a sign-off needs a reason.
 *
 * @var array{terms: list<array<string, mixed>>, term: ?int, programs: list<array<string, mixed>>} $board
 */
use App\Services\Courses\Grades\GradeSignoffService;

$area ??= 'dean';
$base   = site_url($area . '/signoff');
$termId = $board['term'];

// The administrators share the page: any of them may reopen what another signed off.
$reopener = $area === 'dean' ? 'only you can' : 'only an administrator can';
$scope    = $area === 'dean' ? "your department's sections" : 'the sections of departments with no dean who signs off';

$termOptions = [];

foreach ($board['terms'] as $term) {
    $termOptions[(string) $term['id']] = $term['name'] . ' (' . $term['academic_year'] . ')';
}

$field = static fn (array $f): string => view('components/form-field', $f, ['saveData' => false]);
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url($area . '/dashboard'), 'backLabel' => 'Back to dashboard'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Term-end sign-off',
        'icon'        => 'check',
        'description' => 'Sign off the final grades of ' . $scope . '. Instructors finalize first; you then sign them off, and ' . $reopener . ' reopen a sign-off.',
        'actions'     => $loadError || $board['term'] === null || $area !== 'dean' ? '' : '<a data-export="csv" href="' . esc(site_url('dean/reports/export/signoff') . '?term=' . (int) $board['term'], 'attr') . '" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">Download CSV</a>',
    ], ['saveData' => false]) ?>

    <?php if ($termOptions !== []): ?>
        <?= view('components/toolbar', [
            'surface'  => 'tinted', // on the page's white panel a white card would not stand out
            'action'   => $base,
            'filtered' => false,
            'fields'   => [['type' => 'select', 'name' => 'term', 'label' => 'Term', 'options' => $termOptions, 'value' => (string) $termId]],
        ], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if ($loadError): ?>

        <div class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-[#C33939]/30 bg-white px-8 py-14 text-center" role="alert" data-load-error>
            <?= icon('warning', 'h-10 w-10 text-[#C33939]') ?>
            <span class="text-sm font-semibold text-[#1A1C1E]">We could not load the sections</span>
            <span class="text-xs text-[#646464]">Something went wrong on our side. Nothing was signed off.</span>
            <a href="<?= esc($base) ?>" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">Try again</a>
        </div>

    <?php elseif ($board['programs'] === []): ?>

        <?= view('components/empty-state', [
            'icon'  => 'check',
            'title' => 'No sections to sign off',
            'text'  => ucfirst($scope) . ' appear here, term by term, once there are some.',
        ], ['saveData' => false]) ?>

    <?php else: ?>

        <?php foreach ($board['programs'] as $program): ?>
            <?php // What this person may sign off: a section an administrator teaches is another administrator's (D4). ?>
            <?php $ready = (int) $program['counts'][GradeSignoffService::READY] - (int) ($program['own_ready'] ?? 0); ?>

            <section class="flex flex-col gap-3 rounded-2xl bg-white p-5 shadow-sm" aria-labelledby="program-<?= (int) $program['id'] ?>" data-program="<?= (int) $program['id'] ?>">

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 id="program-<?= (int) $program['id'] ?>" class="text-base font-bold text-brand"><?= esc($program['name']) ?></h2>
                        <p class="text-xs text-[#646464]" data-program-counts>
                            <?= (int) $program['counts'][GradeSignoffService::READY] ?> ready · <?= (int) $program['counts'][GradeSignoffService::BLOCKED] ?> blocked · <?= (int) $program['counts'][GradeSignoffService::SIGNED_OFF] ?> signed off
                        </p>
                    </div>

                    <?php if ($ready > 0): ?>
                        <button type="button" data-dialog-open="signoff-program-<?= (int) $program['id'] ?>" data-signoff-all class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">
                            Sign off all <?= $ready ?> ready
                        </button>
                    <?php endif; ?>
                </div>

                <ul class="flex flex-col divide-y divide-gray-100">
                    <?php foreach ($program['sections'] as $section): ?>
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3" data-section="<?= (int) $section['offering_id'] ?>" data-state="<?= esc($section['state'], 'attr') ?>">

                            <div class="flex min-w-0 flex-col gap-0.5">
                                <span class="text-sm font-semibold text-[#1A1C1E]">
                                    <?= esc($section['course_code'] . ' ' . $section['section_code']) ?>
                                    <span class="font-normal text-[#646464]">· <?= esc($section['course_title']) ?></span>
                                </span>
                                <span class="text-xs text-[#646464]">
                                    <?= esc($section['instructor'] ?? 'No instructor assigned') ?> · <?= (int) $section['students'] ?> <?= $section['students'] === 1 ? 'student' : 'students' ?>
                                </span>

                                <?php if ($section['state'] === GradeSignoffService::BLOCKED): ?>
                                    <span class="text-xs text-[#946200]" data-blockers><?= esc(implode(' ', $section['blockers'])) ?></span>
                                    <span class="flex flex-wrap gap-3 text-xs font-semibold" data-blocker-links>
                                        <a class="text-brand hover:underline" href="<?= esc($section['grades_url']) ?>">See the grades (view only)</a>
                                        <?php if ((int) $section['counts']['open_requests'] > 0): ?>
                                            <a class="text-brand hover:underline" href="<?= esc(site_url($area . '/approvals')) ?>">Decide the open requests</a>
                                        <?php endif; ?>
                                        <?php if ($area === 'dean'): ?><a data-follow-up-link class="text-brand hover:underline" href="<?= esc(site_url('dean/follow-ups') . '?' . http_build_query(['offering' => (int) $section['offering_id'], 'reason' => 'blocked_signoff'])) ?>">Send a follow-up</a><?php endif; ?>
                                    </span>
                                <?php elseif ($section['state'] === GradeSignoffService::SIGNED_OFF): ?>
                                    <span class="text-xs text-[#2B7549]" data-signed-by>
                                        Signed off <?= esc(date('M j, Y', strtotime((string) $section['signed_at']))) ?><?= $section['signed_by_name'] ? ' by ' . esc($section['signed_by_name']) : '' ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="flex items-center gap-3">
                                <?= view('components/status-badge', match ($section['state']) {
                                    GradeSignoffService::READY      => ['label' => 'Ready', 'tone' => 'info', 'icon' => 'check'],
                                    GradeSignoffService::SIGNED_OFF => ['label' => 'Signed off', 'tone' => 'success', 'icon' => 'lock'],
                                    default                         => ['label' => 'Blocked', 'tone' => 'warning', 'icon' => 'attention'],
                                }, ['saveData' => false]) ?>

                                <?php if ($section['state'] === GradeSignoffService::READY && ! empty($section['own_section'])): ?>
                                    <span class="text-xs text-[#646464]" data-own-section>You teach this section, so another administrator signs it off.</span>
                                <?php elseif ($section['state'] === GradeSignoffService::READY): ?>
                                    <button type="button" data-dialog-open="signoff-<?= (int) $section['offering_id'] ?>" class="rounded-full border border-brand px-4 py-1.5 text-xs font-semibold text-brand transition hover:bg-brand-tint">Sign off</button>
                                <?php elseif ($section['state'] === GradeSignoffService::SIGNED_OFF): ?>
                                    <button type="button" data-dialog-open="reopen-<?= (int) $section['offering_id'] ?>" class="rounded-full border border-gray-300 px-4 py-1.5 text-xs font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Reopen…</button>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>

        <?php foreach ($board['programs'] as $program): ?>
            <?php $ready = (int) $program['counts'][GradeSignoffService::READY] - (int) ($program['own_ready'] ?? 0); ?>

            <?php if ($ready > 0): ?>
                <?= view('components/confirm', [
                    'id'           => 'signoff-program-' . (int) $program['id'],
                    'title'        => 'Sign off all ready sections',
                    'text'         => 'Sign off the grades of ' . $ready . ($ready === 1 ? ' ready section' : ' ready sections') . ' in ' . $program['name'] . '? Sections that are not ready are left as they are' . ((int) ($program['own_ready'] ?? 0) > 0 ? ', and so are those you teach' : '') . '. ' . ucfirst($reopener) . ' reopen a sign-off.',
                    'action'       => site_url($area . '/signoff/program/' . (int) $program['id'] . '/' . (int) $termId),
                    'confirmLabel' => 'Sign off ' . $ready,
                    'ajax'         => true,
                ], ['saveData' => false]) ?>
            <?php endif; ?>

            <?php foreach ($program['sections'] as $section): ?>
                <?php $label = $section['course_code'] . ' ' . $section['section_code']; ?>

                <?php if ($section['state'] === GradeSignoffService::READY): ?>
                    <?= view('components/confirm', [
                        'id'           => 'signoff-' . (int) $section['offering_id'],
                        'title'        => 'Sign off ' . $label,
                        'text'         => 'Sign off the final grades of ' . $label . ' (' . (int) $section['students'] . ' ' . ($section['students'] === 1 ? 'student' : 'students') . ')? The instructor and chairperson can no longer reopen them: ' . $reopener . '.',
                        'action'       => site_url($area . '/signoff/' . (int) $section['offering_id']),
                        'confirmLabel' => 'Sign off',
                        'ajax'         => true,
                    ], ['saveData' => false]) ?>
                <?php elseif ($section['state'] === GradeSignoffService::SIGNED_OFF): ?>
                    <?php
                    $reopenBody = '<form method="post" action="' . esc(site_url($area . '/signoff/' . (int) $section['offering_id'] . '/reopen'), 'attr') . '" data-dialog-form class="flex flex-col gap-4">' . csrf_field()
                        . '<p class="text-sm text-[#3D3D3D]">This ends the sign-off of ' . esc($label) . '. The grades stay finalized, and the instructor and chairperson are told why.</p>'
                        . $field(['name' => 'reason', 'label' => 'Why are you reopening it?', 'type' => 'textarea', 'required' => true, 'maxlength' => 2000])
                        . '<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>'
                        . '<div class="flex flex-wrap items-center justify-end gap-3"><button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>'
                        . '<button type="submit" data-dialog-label class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">Reopen</button></div></form>';
                    ?>
                    <?= view('components/dialog', ['id' => 'reopen-' . (int) $section['offering_id'], 'title' => 'Reopen ' . $label, 'body' => $reopenBody], ['saveData' => false]) ?>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>

    <?php endif; ?>

</div>

</div>

<?= $this->endSection() ?>
