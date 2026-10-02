<?php

/*
|--------------------------------------------------------------------------
| Overview key metrics
|--------------------------------------------------------------------------
| Thin loop over the shared components/metric-card. Each metric:
| label, icon, tone (neutral|warning|danger|success), value, detail.
|--------------------------------------------------------------------------
*/

$metrics ??= [];

$columns = count($metrics) >= 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3';

// Which course tab each Overview metric opens when clicked.
$tabs = [
    'Current Grade' => 'grades',
];
?>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 <?= $columns ?>">

    <?php foreach ($metrics as $metric): ?>
        <?= view('components/metric-card', $metric + ['feature' => $tabs[$metric['label']] ?? 'assessments'], ['saveData' => false]) ?>
    <?php endforeach; ?>

</div>
