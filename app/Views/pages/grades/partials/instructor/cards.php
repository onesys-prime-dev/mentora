<?php
/**
 * The summary strip: how much is waiting, how many sections are ready or finalized, and how many students are
 * at risk, across every section of the term. Two to a row on a phone, four from a laptop up.
 *
 * @var array{waiting: int, ready: int, finalized: int, total: int} $summary
 * @var array<string, int>                                           $counts
 */
$cards = [
    'waiting'   => ['label' => 'Waiting to grade', 'value' => (string) $summary['waiting'], 'icon' => 'clock', 'tone' => $summary['waiting'] > 0 ? 'warning' : 'neutral'],
    'ready'     => ['label' => 'Ready to finalize', 'value' => $summary['ready'] . ' / ' . $summary['total'], 'icon' => 'check', 'tone' => 'neutral'],
    'at-risk'   => ['label' => 'At risk', 'value' => (string) $counts['at-risk'], 'icon' => 'attention', 'tone' => $counts['at-risk'] > 0 ? 'warning' : 'neutral'],
    'finalized' => ['label' => 'Finalized', 'value' => $summary['finalized'] . ' / ' . $summary['total'], 'icon' => 'lock', 'tone' => 'neutral'],
];
?>

<div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4" data-grades-summary>
    <?php foreach ($cards as $key => $card): ?>
        <div class="flex min-w-0" data-summary="<?= esc($key) ?>">
            <?= view('components/metric-card', $card, ['saveData' => false]) ?>
        </div>
    <?php endforeach; ?>
</div>
