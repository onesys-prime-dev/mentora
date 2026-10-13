<?php
/**
 * Subscription plans.
 *
 * @var list<array<string, mixed>> $planRows each with `features` as a list
 */
$th  = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600';
$btn = 'rounded border px-2.5 py-1 text-xs font-semibold';

$cap = static fn ($value, string $unit = ''): string => $value === null ? 'Unlimited' : number_format((int) $value) . $unit;
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

<div class="mt-6 overflow-x-auto rounded-lg bg-white shadow">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
            <tr>
                <th class="<?= $th ?>">Plan</th>
                <th class="<?= $th ?>">Status</th>
                <th class="<?= $th ?>">Student seats</th>
                <th class="<?= $th ?>">Storage</th>
                <th class="<?= $th ?>">AI generations / term</th>
                <th class="<?= $th ?>">Features</th>
                <th class="<?= $th ?>">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php if ($planRows === []): ?>
                <tr><td class="px-3 py-8 text-center text-gray-500" colspan="7">No plans yet.</td></tr>
            <?php endif; ?>

            <?php foreach ($planRows as $row): ?>
                <?php $retired = $row['status'] === 'retired'; ?>
                <tr class="<?= $retired ? 'bg-gray-50 text-gray-500' : '' ?>">
                    <td class="px-3 py-2">
                        <span class="font-medium"><?= esc($row['name']) ?></span>
                        <?php if ($row['description'] !== null): ?><span class="block max-w-xs text-xs text-gray-500"><?= esc($row['description']) ?></span><?php endif; ?>
                    </td>
                    <td class="px-3 py-2">
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $retired ? 'bg-gray-200 text-gray-700' : 'bg-green-100 text-green-800' ?>"><?= $retired ? 'Retired' : 'Active' ?></span>
                    </td>
                    <td class="px-3 py-2"><?= esc($cap($row['seat_limit'])) ?></td>
                    <td class="px-3 py-2"><?= esc($cap($row['storage_limit_mb'], ' MB')) ?></td>
                    <td class="px-3 py-2"><?= esc($cap($row['ai_generation_limit'])) ?></td>
                    <td class="px-3 py-2 font-mono text-xs"><?= $row['features'] === [] ? '—' : esc(implode(', ', $row['features'])) ?></td>
                    <td class="px-3 py-2">
                        <div class="flex flex-wrap items-start gap-2">
                            <?php if ($retired): ?>
                                <form method="post" action="<?= site_url('admin/plans/' . $row['id'] . '/reinstate') ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="<?= $btn ?> border-brand text-brand hover:bg-brand/10">Reinstate</button>
                                </form>
                            <?php else: ?>
                                <a class="<?= $btn ?> border-gray-300 text-gray-700 hover:bg-gray-50" href="<?= site_url('admin/plans/' . $row['id'] . '/edit') ?>">Edit</a>
                                <form method="post" action="<?= site_url('admin/plans/' . $row['id'] . '/retire') ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="<?= $btn ?> border-amber-500 text-amber-700 hover:bg-amber-50">Retire</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?= $this->endSection() ?>
