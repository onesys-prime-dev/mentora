<?php
/**
 * @var array<string, array{status: string, detail: string}> $monHealth
 * @var string                                              $monOverall
 * @var array<string, int>                                  $monPlatform
 * @var list<array<string, mixed>>                          $monWatch
 * @var list<array<string, mixed>>                          $monUsage
 * @var array<string, string>                               $monRuntime
 */
use App\Services\Monitoring\SystemHealth;

$badge = [
    'up'       => ['Up', 'bg-green-100 text-green-800'],
    'degraded' => ['Degraded', 'bg-amber-100 text-amber-800'],
    'down'     => ['Down', 'bg-red-100 text-red-800'],
];
$lineBadge = [
    'unlimited' => ['Unlimited', 'text-gray-500'],
    'ok'        => ['OK', 'text-green-700'],
    'warning'   => ['Nearly full', 'text-amber-700 font-semibold'],
    'full'      => ['Full', 'text-red-700 font-semibold'],
];
$th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600';

$line = static function (array $l, bool $storage = false) use ($lineBadge): string {
    [$text, $class] = $lineBadge[$l['state']];
    $used = $storage ? number_format($l['used_mb']) . ' MB' : number_format($l['used']);
    $of   = $storage ? $l['limit_mb'] : $l['limit'];

    return '<span class="' . $class . '">' . esc($used) . ($of === null ? '' : ' of ' . esc($storage ? number_format($of) . ' MB' : number_format($of))) . ' · ' . esc($text) . '</span>';
};
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('head') ?>
<meta http-equiv="refresh" content="60">
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<div class="flex flex-wrap items-center gap-3">
    <h1 class="text-2xl font-bold text-[#0040A6]">System status</h1>
    <span class="rounded-full px-3 py-1 text-sm font-semibold <?= $badge[$monOverall][1] ?>" data-overall="<?= esc($monOverall) ?>"><?= esc($badge[$monOverall][0]) ?></span>
</div>
<p class="mt-1 text-xs text-gray-500">Checked <?= esc($monRuntime['checkedAt']) ?>. This page refreshes itself every minute and changes nothing.</p>

<section class="mt-6 rounded-lg bg-white p-6 shadow" data-section="health">
    <h2 class="text-lg font-semibold text-[#0040A6]">Health</h2>
    <ul class="mt-3 divide-y divide-gray-100">
        <?php foreach ($monHealth as $name => $result): ?>
            <li class="flex flex-wrap items-center justify-between gap-2 py-2" data-check="<?= esc($name) ?>" data-status="<?= esc($result['status']) ?>">
                <span class="text-sm font-medium text-gray-800"><?= esc(SystemHealth::LABELS[$name] ?? $name) ?></span>
                <span class="text-sm text-gray-600"><?= esc($result['detail']) ?></span>
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $badge[$result['status']][1] ?>"><?= esc($badge[$result['status']][0]) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="mt-3 text-xs text-gray-500">PHP <?= esc($monRuntime['php']) ?> · CodeIgniter <?= esc($monRuntime['framework']) ?> · <?= esc($monRuntime['environment']) ?></p>
</section>

<section class="mt-6 rounded-lg bg-white p-6 shadow" data-section="platform">
    <h2 class="text-lg font-semibold text-[#0040A6]">Platform</h2>
    <dl class="mt-3 grid grid-cols-2 gap-4 text-sm sm:grid-cols-3 lg:grid-cols-6">
        <?php foreach (['institutions' => 'Institutions', 'users' => 'Active users', 'signedInToday' => 'Active today', 'sessions' => 'Live sessions', 'failedLogins24h' => 'Failed sign-ins (24 h)', 'failedLogins7d' => 'Failed sign-ins (7 d)'] as $key => $label): ?>
            <div><dt class="text-gray-500"><?= esc($label) ?></dt><dd class="text-xl font-semibold text-gray-900"><?= esc(number_format($monPlatform[$key])) ?></dd></div>
        <?php endforeach; ?>
    </dl>
</section>

<section class="mt-6 rounded-lg bg-white p-6 shadow" data-section="watch">
    <h2 class="text-lg font-semibold text-[#0040A6]">Subscription watch</h2>
    <?php if ($monWatch === []): ?>
        <p class="mt-3 text-sm text-gray-600">Nothing is expiring, in grace or read-only.</p>
    <?php else: ?>
        <ul class="mt-3 divide-y divide-gray-100 text-sm">
            <?php foreach ($monWatch as $row): ?>
                <li class="flex flex-wrap justify-between gap-2 py-2" data-watch="<?= esc($row['state']) ?>">
                    <a class="font-medium text-[#0040A6] hover:underline" href="<?= site_url('admin/institutions/' . $row['id'] . '/subscription') ?>"><?= esc($row['name']) ?></a>
                    <span class="text-gray-600"><?= esc(str_replace('_', '-', $row['state'])) ?> · ends <?= esc($row['endDate']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="mt-6 overflow-x-auto rounded-lg bg-white shadow" data-section="usage">
    <h2 class="px-6 pt-6 text-lg font-semibold text-[#0040A6]">Usage against plans</h2>
    <table class="mt-3 min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
            <tr>
                <th class="<?= $th ?>">Institution</th>
                <th class="<?= $th ?>">Plan</th>
                <th class="<?= $th ?>">Active users</th>
                <th class="<?= $th ?>">Seats</th>
                <th class="<?= $th ?>">Storage</th>
                <th class="<?= $th ?>">AI</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php if ($monUsage === []): ?>
                <tr><td class="px-3 py-6 text-center text-gray-500" colspan="6">No institutions yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($monUsage as $row): ?>
                <tr data-institution="<?= esc((string) $row['id']) ?>">
                    <td class="px-3 py-2 font-medium"><a class="text-[#0040A6] hover:underline" href="<?= site_url('admin/institutions/' . $row['id']) ?>"><?= esc($row['name']) ?></a></td>
                    <td class="px-3 py-2"><?= esc($row['plan'] ?? '—') ?></td>
                    <td class="px-3 py-2"><?= esc((string) $row['activeUsers']) ?></td>
                    <td class="px-3 py-2"><?= $line($row['seats']) ?></td>
                    <td class="px-3 py-2"><?= $line($row['storage'], true) ?></td>
                    <td class="px-3 py-2"><?= $line($row['ai']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?= $this->endSection() ?>
