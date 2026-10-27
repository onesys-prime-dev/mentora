<?php
/**
 * @var array<string, array{status: string, detail: string}> $monHealth
 * @var string                                              $monOverall
 * @var array<string, int>                                  $monPlatform
 * @var list<array<string, mixed>>                          $monWatch
 * @var list<array<string, mixed>>                          $monUsage
 * @var array<string, string>                               $monRuntime
 * @var string                                              $monLiveUrl
 */
use App\Services\Monitoring\SystemHealth;

$badge = [
    'up'       => ['Up', 'bg-[#E8F5EC] text-[#2B7549]', 'bg-[#2B7549]'],
    'degraded' => ['Degraded', 'bg-[#FFF1D6] text-[#946200]', 'bg-[#E0A100]'],
    'down'     => ['Down', 'bg-[#FDEDEC] text-[#C33939]', 'bg-[#C33939]'],
];
$lineBadge = [
    'unlimited' => ['Unlimited', 'text-[#646464]'],
    'ok'        => ['OK', 'text-green-700'],
    'warning'   => ['Nearly full', 'text-amber-700 font-semibold'],
    'full'      => ['Full', 'text-red-700 font-semibold'],
];
$th = 'sticky top-0 z-10 bg-gray-50 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-[#646464]';

$line = static function (array $l, bool $storage = false) use ($lineBadge): string {
    [$text, $class] = $lineBadge[$l['state']];
    $used = $storage ? number_format($l['used_mb']) . ' MB' : number_format($l['used']);
    $of   = $storage ? $l['limit_mb'] : $l['limit'];

    return '<span class="' . $class . '">' . esc($used) . ($of === null ? '' : ' of ' . esc($storage ? number_format($of) . ' MB' : number_format($of))) . ' · ' . esc($text) . '</span>';
};

// The checks that have a number to draw: a small line under their name.
$spark = ['database' => 'ms', 'disk' => '% used'];

$tiles = ['institutions' => 'Institutions', 'users' => 'Active users', 'signedInToday' => 'Active today', 'sessions' => 'Live sessions', 'failedLogins24h' => 'Failed sign-ins (24 h)', 'failedLogins7d' => 'Failed sign-ins (7 d)'];
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-5 rounded-3xl bg-white p-5 shadow-sm sm:p-8 lg:h-full lg:overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" data-page-panel data-live-url="<?= esc($monLiveUrl, 'attr') ?>">

    <?= view('components/page-head', ['title' => 'System status', 'icon' => 'score', 'actions' => '<span class="rounded-full px-3 py-1 text-sm font-semibold ' . $badge[$monOverall][1] . '" data-overall="' . esc($monOverall) . '">' . esc($badge[$monOverall][0]) . '</span>'], ['saveData' => false]) ?>
    <?php /* Without scripts the browser's own refresh does it; with them the graphs move and the person can pause them (WCAG 2.2.1). */ ?>
    <noscript><meta http-equiv="refresh" content="60"></noscript>
    <p class="-mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-[#646464]">
        <span>Checked <?= esc($monRuntime['checkedAt']) ?>. This page changes nothing.</span>
        <label class="flex items-center gap-2">
            <input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-brand focus:ring-brand/40" checked data-auto-refresh>
            Live updates every 5 seconds
        </label>
    </p>

    <section data-section="health">
        <h2 class="sr-only">Health</h2>
        <ul class="grid grid-cols-2 overflow-hidden rounded-2xl border border-gray-200 sm:grid-cols-3 lg:grid-cols-6">
            <?php foreach ($monHealth as $name => $result): ?>
                <li class="border-b border-r border-gray-200 px-3 py-2.5 text-sm" data-check="<?= esc($name) ?>" data-status="<?= esc($result['status']) ?>" title="<?= esc($result['detail']) ?>">
                    <span class="block text-xs text-[#646464]"><?= esc(SystemHealth::LABELS[$name] ?? $name) ?></span>
                    <span class="mt-0.5 flex items-center gap-1.5 font-semibold text-gray-900">
                        <span class="h-2 w-2 shrink-0 rounded-full <?= $badge[$result['status']][2] ?>" data-dot></span>
                        <span data-state-text><?= esc($badge[$result['status']][0]) ?></span>
                        <?php if (isset($spark[$name])): ?><span class="ml-auto text-xs font-normal text-[#646464]" data-reading></span><?php endif; ?>
                    </span>
                    <?php if (isset($spark[$name])): ?>
                        <svg class="mt-1 h-6 w-full" viewBox="0 0 100 24" preserveAspectRatio="none" role="img" aria-label="<?= esc(SystemHealth::LABELS[$name] . ' (' . $spark[$name] . ') over the last minutes') ?>">
                            <polyline fill="none" stroke="#0B3A8F" stroke-width="1.5" vector-effect="non-scaling-stroke" data-spark="<?= esc($name) ?>"/>
                        </svg>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="mt-2 text-xs text-[#646464]">PHP <?= esc($monRuntime['php']) ?> · CodeIgniter <?= esc($monRuntime['framework']) ?> · <?= esc($monRuntime['environment']) ?></p>
    </section>

    <section class="grid gap-4 lg:grid-cols-3" data-section="platform">
        <div class="rounded-2xl border border-gray-200 p-3 lg:col-span-2">
            <div class="flex items-baseline justify-between text-xs uppercase tracking-wide text-[#646464]">
                <span>Live sessions · since you opened this page</span>
                <b class="text-base font-semibold normal-case text-gray-900" data-reading-sessions><?= esc(number_format($monPlatform['sessions'])) ?> now</b>
            </div>
            <svg class="mt-2 h-24 w-full" viewBox="0 0 300 96" preserveAspectRatio="none" role="img" aria-label="Live sessions over the time this page has been open">
                <line x1="0" y1="95" x2="300" y2="95" stroke="#D5DAE3" stroke-width="1" vector-effect="non-scaling-stroke"/>
                <polyline fill="none" stroke="#0B3A8F" stroke-width="1.8" vector-effect="non-scaling-stroke" data-spark="sessions"/>
            </svg>
        </div>
        <dl class="grid grid-cols-3 gap-2 text-sm">
            <?php foreach ($tiles as $key => $label): ?>
                <div class="rounded-2xl border border-gray-200 px-3 py-2"><dt class="text-xs text-[#646464]"><?= esc($label) ?></dt><dd class="text-xl font-semibold text-gray-900" data-tile="<?= esc($key) ?>"><?= esc(number_format($monPlatform[$key])) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    </section>

    <section data-section="watch">
        <h2 class="text-sm font-semibold text-brand">Subscription watch</h2>
        <?php if ($monWatch === []): ?>
            <p class="mt-1 text-sm text-[#646464]">Nothing is expiring, in grace or read-only.</p>
        <?php else: ?>
            <ul class="mt-2 flex flex-wrap gap-2 text-sm">
                <?php foreach ($monWatch as $row): ?>
                    <li class="rounded-full bg-[#FFF1D6] px-3 py-1" data-watch="<?= esc($row['state']) ?>">
                        <a class="font-medium text-[#946200] hover:underline" href="<?= site_url('admin/institutions/' . $row['id'] . '/subscription') ?>"><?= esc($row['name']) ?></a>
                        <span class="text-[#946200]">· <?= esc(str_replace('_', '-', $row['state'])) ?> · ends <?= esc($row['endDate']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="flex flex-col lg:min-h-[21rem] lg:flex-1" data-section="usage">
        <h2 class="mb-2 text-sm font-semibold text-brand">Usage against plans</h2>
        <div class="overflow-auto rounded-2xl border border-gray-200 lg:min-h-0 lg:flex-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" data-table>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead>
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
        </div>
    </section>

</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
    (function () {
        var panel = document.querySelector('[data-live-url]');
        var box = document.querySelector('[data-auto-refresh]');
        if (!panel) { return; }

        var url = panel.getAttribute('data-live-url');
        var KEEP = 60;
        var series = { database: [], disk: [], sessions: [] };
        var look = {
            up: ['Up', 'bg-[#2B7549]', 'bg-[#E8F5EC] text-[#2B7549]'],
            degraded: ['Degraded', 'bg-[#E0A100]', 'bg-[#FFF1D6] text-[#946200]'],
            down: ['Down', 'bg-[#C33939]', 'bg-[#FDEDEC] text-[#C33939]']
        };
        var dotClasses = ['bg-[#2B7549]', 'bg-[#E0A100]', 'bg-[#C33939]'];
        var timer = null;

        function push(name, value) {
            if (value === null || value === undefined) { return; }
            series[name].push(value);
            if (series[name].length > KEEP) { series[name].shift(); }
        }

        function draw(name, width, height, floor) {
            var line = document.querySelector('[data-spark="' + name + '"]');
            var data = series[name];
            if (!line || data.length === 0) { return; }

            var lo = Math.min.apply(null, data.concat(floor === null ? [] : [floor]));
            var hi = Math.max.apply(null, data.concat(floor === null ? [] : [floor]));
            if (hi - lo < 1) { hi = lo + 1; }

            var pad = 3;
            line.setAttribute('points', data.map(function (v, i) {
                var x = data.length === 1 ? width : (i / (data.length - 1)) * width;
                var y = height - pad - ((v - lo) / (hi - lo)) * (height - pad * 2);
                return x.toFixed(1) + ',' + y.toFixed(1);
            }).join(' '));
        }

        function text(selector, value) {
            var el = document.querySelector(selector);
            if (el) { el.textContent = value; }
        }

        function paintChecks(checks) {
            Object.keys(checks).forEach(function (name) {
                var cell = document.querySelector('[data-check="' + name + '"]');
                var look1 = look[checks[name].status];
                if (!cell || !look1) { return; }

                cell.setAttribute('data-status', checks[name].status);
                cell.setAttribute('title', checks[name].detail);
                cell.querySelector('[data-state-text]').textContent = look1[0];
                var dot = cell.querySelector('[data-dot]');
                dotClasses.forEach(function (c) { dot.classList.remove(c); });
                dot.classList.add(look1[1]);
            });

            var worst = 'up';
            document.querySelectorAll('[data-check]').forEach(function (cell) {
                var s = cell.getAttribute('data-status');
                if (s === 'down') { worst = 'down'; } else if (s === 'degraded' && worst !== 'down') { worst = 'degraded'; }
            });

            var badge = document.querySelector('[data-overall]');
            if (badge) {
                badge.setAttribute('data-overall', worst);
                badge.textContent = look[worst][0];
                badge.className = 'rounded-full px-3 py-1 text-sm font-semibold ' + look[worst][2];
            }
        }

        function read(data) {
            push('database', data.databaseMs);
            push('disk', data.diskUsed);
            push('sessions', data.sessions);

            draw('database', 100, 24, 0);
            draw('disk', 100, 24, null);
            draw('sessions', 300, 96, 0);

            text('[data-check="database"] [data-reading]', data.databaseMs + ' ms');
            text('[data-check="disk"] [data-reading]', data.diskUsed === null ? '' : data.diskUsed + '%');
            text('[data-reading-sessions]', data.sessions + ' now');
            text('[data-tile="sessions"]', String(data.sessions));
            text('[data-tile="failedLogins24h"]', String(data.failed24h));
            text('[data-tile="failedLogins7d"]', String(data.failed7d));
            text('[data-tile="signedInToday"]', String(data.signedInToday));

            paintChecks(data.checks);
        }

        function tick() {
            window.clearTimeout(timer);

            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) { if (data) { read(data); } })
                .catch(function () {})
                .then(function () {
                    if (box && box.checked) { timer = window.setTimeout(tick, 5000); }
                });
        }

        if (box) { box.addEventListener('change', function () { if (box.checked) { tick(); } else { window.clearTimeout(timer); } }); }

        tick();
    })();
</script>
<?= $this->endSection() ?>
