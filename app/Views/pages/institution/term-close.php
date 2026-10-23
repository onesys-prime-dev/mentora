<?php
/**
 * What closing a term would change.
 *
 * @var array<string, mixed> $closePlan
 * @var bool                 $closeCanEdit
 */
$plan    = $closePlan;
$term    = $plan['term'];
$base    = 'institution/terms';
$primary = 'rounded-full bg-[#C33939] px-5 py-2 text-sm font-semibold text-white transition hover:bg-[#A82E2E] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#C33939]/50';
$second  = 'rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';

$rows = [];

foreach ($plan['offerings'] as $o) {
    $rows[] = [
        'id'    => $o['id'],
        'label' => $o['course_code'] . ' ' . $o['section_code'],
        'cells' => [
            'course'   => ['html' => '<span class="font-mono text-xs font-semibold">' . esc($o['course_code']) . '</span> <span class="font-medium">' . esc($o['course_title']) . '</span>'],
            'section'  => (string) $o['section_code'],
            'enrolled' => (string) $o['enrolled'],
        ],
    ];
}
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="py-3">

<?php // The way back stays on the background, above the panel, not in the box. ?>
<?= view('components/back-link', ['backUrl' => site_url($base), 'backLabel' => 'Back to terms'], ['saveData' => false]) ?>

<div class="flex flex-col gap-6 rounded-3xl bg-white p-5 shadow-sm sm:p-8" data-page-panel>

    <?= view('components/page-head', [
        'title'       => 'Close ' . $term['name'],
        'icon'        => 'calendar',
        'description' => $term['academic_year'] . ' · ' . date('j M Y', strtotime((string) $term['start_date'])) . ' to ' . date('j M Y', strtotime((string) $term['end_date'])),
    ], ['saveData' => false]) ?>

    <?php if (session()->has('error')): ?>
        <?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?>
    <?php endif; ?>

    <?php if (! $plan['canClose']): ?>
        <?= view('components/alert', ['tone' => 'info', 'text' => (string) $plan['reason']], ['saveData' => false]) ?>
    <?php else: ?>

        <p class="text-sm text-[#3D3D3D]">Closing a term ends it. Here is exactly what changes; nothing is deleted.</p>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3" data-close-totals>
            <?= view('components/metric-card', ['label' => 'Offerings completed', 'value' => (string) $plan['offeringCount'], 'icon' => 'modules', 'tone' => 'neutral'], ['saveData' => false]) ?>
            <?= view('components/metric-card', ['label' => 'Enrolments completed', 'value' => (string) $plan['enrolmentCount'], 'icon' => 'user', 'tone' => 'neutral'], ['saveData' => false]) ?>
            <?= view('components/metric-card', ['label' => 'Left as they are', 'value' => (string) $plan['untouched'], 'icon' => 'info', 'tone' => 'neutral', 'detail' => 'Archived offerings'], ['saveData' => false]) ?>
        </div>

        <section class="rounded-2xl border border-[#E3E6EE] bg-[#F6F7FA] p-6 shadow-md" aria-label="What will change">
            <h2 class="text-sm font-bold text-brand">What happens</h2>
            <ul class="mt-3 flex list-disc flex-col gap-1 pl-5 text-sm text-[#3D3D3D]">
                <li>The term and its offerings become <strong>completed</strong>.</li>
                <li>Students still enrolled become <strong>completed</strong> in them.</li>
                <li>Instructors and students can still look at the courses and download what is there, but <strong>cannot change anything</strong> in them.</li>
                <li>Work, grades and history are kept. You can reopen the term later and everything above is put back.</li>
            </ul>
        </section>

        <?= view('components/data-table', [
            'caption'    => 'Offerings that will be completed',
            'columns'    => [
                ['key' => 'course', 'label' => 'Course'],
                ['key' => 'section', 'label' => 'Section'],
                ['key' => 'enrolled', 'label' => 'Students enrolled'],
            ],
            'rows'       => $rows,
            'emptyIcon'  => 'modules',
            'emptyTitle' => 'No active offerings in this term',
            'emptyText'  => 'Only the term itself will be closed.',
        ], ['saveData' => false]) ?>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <a class="<?= $second ?>" href="<?= esc(site_url($base)) ?>">Cancel</a>
            <?php if ($closeCanEdit): ?>
                <form method="post" action="<?= esc(site_url($base . '/' . $term['id'] . '/close')) ?>"><?= csrf_field() ?>
                    <button type="submit" class="<?= $primary ?>">Close <?= esc($term['name']) ?></button>
                </form>
            <?php endif; ?>
        </div>

        <?php if (! $closeCanEdit): ?>
            <?= view('components/lock-note', ['kind' => 'read_only'], ['saveData' => false]) ?>
        <?php endif; ?>

    <?php endif; ?>

</div>

</div>

<?= $this->endSection() ?>
