<?php
/**
 * Subscription plans.
 *
 * @var list<array<string, mixed>> $planRows each with `features` as a list
 */
$btn   = 'rounded-full border px-3 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$plain = 'border-gray-300 bg-white text-[#3D3D3D] hover:bg-gray-50';

$cap = static fn ($value, string $unit = ''): string => $value === null ? 'Unlimited' : number_format((int) $value) . $unit;

$rows = [];

foreach ($planRows as $row) {
    $retired = $row['status'] === 'retired';
    $id      = (string) $row['id'];

    $actions = '<div class="flex flex-wrap items-start justify-end gap-2 max-md:justify-start">';

    if ($retired) {
        $actions .= '<form method="post" action="' . site_url('admin/plans/' . $id . '/reinstate') . '">' . csrf_field()
            . '<button type="submit" class="' . $btn . ' border-brand bg-white text-brand hover:bg-brand-tint">Reinstate</button></form>';
    } else {
        $actions .= '<a class="' . $btn . ' ' . $plain . '" href="' . site_url('admin/plans/' . $id . '/edit') . '">Edit</a>'
            . '<form method="post" action="' . site_url('admin/plans/' . $id . '/retire') . '">' . csrf_field()
            . '<button type="submit" class="' . $btn . ' border-[#946200] bg-white text-[#946200] hover:bg-[#FFF1D6]">Retire</button></form>';
    }

    $actions .= '</div>';

    $name = '<span class="font-medium">' . esc($row['name']) . '</span>'
        . ($row['description'] !== null ? '<span class="block max-w-xs text-xs text-[#646464]">' . esc($row['description']) . '</span>' : '');

    $rows[] = [
        'id'      => $id,
        'label'   => $row['name'],
        'cells'   => [
            'name'     => ['html' => $name],
            'status'   => ['html' => view('components/status-badge', ['label' => $retired ? 'Retired' : 'Active', 'tone' => $retired ? 'neutral' : 'success'], ['saveData' => false])],
            'seats'    => $cap($row['seat_limit']),
            'storage'  => $cap($row['storage_limit_mb'], ' MB'),
            'ai'       => $cap($row['ai_generation_limit']),
            'features' => ['html' => $row['features'] === [] ? '—' : '<span class="font-mono text-xs">' . esc(implode(', ', $row['features'])) . '</span>'],
        ],
        'actions' => $actions,
    ];
}
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<?= view('components/page-head', ['title' => 'Plans', 'icon' => 'points', 'actions' => '<a class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" href="' . site_url('admin/plans/new') . '">Add a plan</a>'], ['saveData' => false]) ?>
<p class="mt-2 max-w-3xl text-sm text-gray-600">
    A plan is what an institution subscribes to: the features it gets and the limits that apply. A retired plan can no longer be chosen
    for new subscriptions, but institutions already on it keep it.
</p>

<?php if (session()->has('message')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'success', 'text' => (string) session('message')], ['saveData' => false]) ?></div>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <div class="mt-4"><?= view('components/alert', ['tone' => 'danger', 'text' => (string) session('error')], ['saveData' => false]) ?></div>
<?php endif; ?>

<div class="mt-6">
    <?= view('components/data-table', [
        'caption'    => 'Plans',
        'columns'    => [
            ['key' => 'name', 'label' => 'Plan'],
            ['key' => 'status', 'label' => 'Status'],
            ['key' => 'seats', 'label' => 'Student seats'],
            ['key' => 'storage', 'label' => 'Storage'],
            ['key' => 'ai', 'label' => 'AI generations / term'],
            ['key' => 'features', 'label' => 'Features'],
        ],
        'rows'       => $rows,
        'emptyIcon'  => 'points',
        'emptyTitle' => 'No plans yet',
        'emptyText'  => 'A plan is what an institution subscribes to. Add one to start onboarding customers.',
    ], ['saveData' => false]) ?>
</div>

<?= $this->endSection() ?>
