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
    'up'       => ['Up', 'bg-[#E8F5EC] text-[#2B7549]'],
    'degraded' => ['Degraded', 'bg-[#FFF1D6] text-[#946200]'],
    'down'     => ['Down', 'bg-[#FDEDEC] text-[#C33939]'],
];
$lineBadge = [
    'unlimited' => ['Unlimited', 'text-[#646464]'],
    'ok'        => ['OK', 'text-green-700'],
    'warning'   => ['Nearly full', 'text-amber-700 font-semibold'],
    'full'      => ['Full', 'text-red-700 font-semibold'],
];
$th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-[#646464]';

$line = static function (array $l, bool $storage = false) use ($lineBadge): string {
    [$text, $class] = $lineBadge[$l['state']];
    $used = $storage ? number_format($l['used_mb']) . ' MB' : number_format($l['used']);
    $of   = $storage ? $l['limit_mb'] : $l['limit'];

    return '<span class="' . $class . '">' . esc($used) . ($of === null ? '' : ' of ' . esc($storage ? number_format($of) . ' MB' : number_format($of))) . ' · ' . esc($text) . '</span>';
};
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<?= view('components/page-head', ['title' => 'System status', 'icon' => 'score', 'actions' => '<span class="rounded-full px-3 py-1 text-sm font-semibold ' . $badge[$monOverall][1] . '" data-overall="' . esc($monOverall) . '">' . esc($badge[$monOverall][0]) . '</span>'], ['saveData' => false]) ?>
<?php /* Without scripts the browser's own refresh does it; with them the person can stop it (WCAG 2.2.1). */ ?>
<noscript><meta http-equiv="refresh" content="60"></noscript>
<p class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-[#646464]">
    <span>Checked <?= esc($monRuntime['checkedAt']) ?>. This page changes nothing.</span>
    <label class="flex items-center gap-2">
        <input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-brand focus:ring-brand/40" checked data-auto-refresh>
        Refresh every minute
    </label>
</p>

<section class="mt-6 rounded-2xl bg-white p-6 shadow-sm" data-section="health">
    <h2 class="text-lg font-semibold text-brand">Health</h2>
    <ul class="mt-3 divide-y divide-gray-100">
        <?php foreach ($monHealth as $name => $result): ?>
            <li class="flex flex-wrap items-center justify-between gap-2 py-2" data-check="<?= esc($name) ?>" data-status="<?= esc($result['status']) ?>">
                <span class="text-sm font-medium text-gray-800"><?= esc(SystemHealth::LABELS[$name] ?? $name) ?></span>
                <span class="text-sm text-[#646464]"><?= esc($result['detail']) ?></span>
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $badge[$result['status']][1] ?>"><?= esc($badge[$result['status']][0]) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="mt-3 text-xs text-[#646464]">PHP <?= esc($monRuntime['php']) ?> · CodeIgniter <?= esc($monRuntime['framework']) ?> · <?= esc($monRuntime['environment']) ?></p>
</section>

<section class="mt-6 rounded-2xl bg-white p-6 shadow-sm" data-section="platform">
    <h2 class="text-lg font-semibold text-brand">Platform</h2>
    <dl class="mt-3 grid grid-cols-2 gap-4 text-sm sm:grid-cols-3 lg:grid-cols-6">
        <?php foreach (['institutions' => 'Institutions', 'users' => 'Active users', 'signedInToday' => 'Active today', 'sessions' => 'Live sessions', 'failedLogins24h' => 'Failed sign-ins (24 h)', 'failedLogins7d' => 'Failed sign-ins (7 d)'] as $key => $label): ?>
            <div><dt class="text-[#646464]"><?= esc($label) ?></dt><dd class="text-xl font-semibold text-gray-900"><?= esc(number_format($monPlatform[$key])) ?></dd></div>
        <?php endforeach; ?>
    </dl>
</section>

<section class="mt-6 rounded-2xl bg-white p-6 shadow-sm" data-section="watch">
    <h2 class="text-lg font-semibold text-brand">Subscription watch</h2>
    <?php if ($monWatch === []): ?>
        <p class="mt-3 text-sm text-[#646464]">Nothing is expiring, in grace or read-only.</p>
    <?php else: ?>
        <ul class="mt-3 divide-y divide-gray-100 text-sm">
            <?php foreach ($monWatch as $row): ?>
                <li class="flex flex-wrap justify-between gap-2 py-2" data-watch="<?= esc($row['state']) ?>">
                    <a class="font-medium text-brand hover:underline" href="<?= site_url('admin/institutions/' . $row['id'] . '/subscription') ?>"><?= esc($row['name']) ?></a>
                    <span class="text-[#646464]"><?= esc(str_replace('_', '-', $row['state'])) ?> · ends <?= esc($row['endDate']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="mt-6 overflow-x-auto rounded-lg bg-white shadow" data-section="usage">
    <h2 class="px-6 pt-6 text-lg font-semibold text-brand">Usage against plans</h2>
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
                <tr><td class="px-3 py-6 text-center text-[#646464]" colspan="6">No institutions yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($monUsage as $row): ?>
                <tr data-institution="<?= esc((string) $row['id']) ?>">
                    <td class="px-3 py-2 font-medium"><a class="text-brand hover:underline" href="<?= site_url('admin/institutions/' . $row['id']) ?>"><?= esc($row['name']) ?></a></td>
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

<?= $this->section('scripts') ?>
<script>
    (function () {
        var box = document.querySelector('[data-auto-refresh]');
        var timer = null;

        function arm() {
            window.clearTimeout(timer);

            if (box && box.checked) {
                timer = window.setTimeout(function () { window.location.reload(); }, 60000);
            }
        }

        if (box) {
            box.addEventListener('change', arm);
        }

        arm();
    })();
</script>
<?= $this->endSection() ?>
