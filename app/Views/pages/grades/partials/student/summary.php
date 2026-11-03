<?php
/**
 * The student's summary strip: the GWA (the one that matters, so it leads and stands out), units, and how many
 * courses were passed, failed or left Incomplete. With no default scale at the institution there is no GWA, so its
 * card is left out; with one but nothing counted yet it reads "—". Under the strip, the courses left out of the
 * GWA and why. Two to a row on a phone (the GWA across both), all in one row from a laptop up.
 *
 * @var array{units: float, gwa: ?float, passed: int, failed: int, inc: int, not_counted: list<array{code: string, reason: string}>} $summary
 * @var bool                                                                                                                         $hasScale
 */
$cards = [];

if ($hasScale) {
    $cards['gwa'] = [
        'label'  => 'GWA',
        'value'  => $summary['gwa'] === null ? '—' : number_format($summary['gwa'], 2),
        'detail' => 'Finalized courses only',
        'icon'   => 'grades',
        'size'   => 'prominent',
    ];
}

$cards += [
    'units'  => ['label' => 'Units', 'value' => grade_number($summary['units']), 'icon' => 'hat'],
    'passed' => ['label' => 'Passed', 'value' => (string) $summary['passed'], 'icon' => 'check'],
    'failed' => ['label' => 'Failed', 'value' => (string) $summary['failed'], 'icon' => 'attention', 'tone' => $summary['failed'] > 0 ? 'danger' : 'neutral'],
    'inc'    => ['label' => 'INC', 'value' => (string) $summary['inc'], 'icon' => 'in-progress', 'tone' => $summary['inc'] > 0 ? 'warning' : 'neutral'],
];

$notCounted = $hasScale ? $summary['not_counted'] : [];
$count      = count($notCounted);
?>

<section class="flex flex-col gap-3" aria-labelledby="grades-summary">

    <h2 id="grades-summary" class="sr-only">This term so far</h2>

    <div class="grid grid-cols-2 gap-3 sm:gap-4 <?= $hasScale ? 'lg:grid-cols-5' : 'lg:grid-cols-4' ?>" data-grades-summary>
        <?php foreach ($cards as $key => $card): ?>
            <?php // The GWA spans the row on a phone and carries a brand ring, so it reads first. ?>
            <div class="flex min-w-0 <?= $key === 'gwa' ? 'col-span-2 rounded-2xl ring-2 ring-brand lg:col-span-1' : '' ?>" data-summary="<?= esc($key) ?>">
                <?= view('components/metric-card', $card, ['saveData' => false]) ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($count > 0): ?>
        <p class="rounded-xl border border-[#E3E6EE] bg-[#FAFBFD] px-4 py-2.5 text-xs leading-relaxed text-[#646464]" data-not-counted><?= esc(
            $count . ' ' . ($count === 1 ? 'course' : 'courses') . ' not counted in the GWA: '
            . implode(', ', array_map(static fn (array $c): string => $c['code'] . ' (' . $c['reason'] . ')', $notCounted)),
        ) ?></p>
    <?php endif; ?>

</section>
