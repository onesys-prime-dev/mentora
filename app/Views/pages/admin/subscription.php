<?php
/**
 * An institution's subscription: current state, renewal history and forms.
 *
 * @var array<string, mixed>       $subInstitution
 * @var array<string, mixed>|null  $subscription   null when it has none
 * @var list<array<string, mixed>> $subHistory     newest first
 * @var list<array<string, mixed>> $subPlans       plans to choose from (id, name)
 * @var bool                       $subRunning
 * @var array<string, mixed>|null  $subUsage       plan limits and how much is used (null with no subscription)
 * @var string                     $subDefaultCurrency
 */
$input = 'rounded border border-gray-300 bg-white px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#0040A6]/55';
$th    = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600';
$btn   = 'rounded border px-2.5 py-1 text-xs font-semibold';
$label = 'flex flex-col gap-1 text-xs font-medium text-gray-600';

$money = static fn (?string $amount, string $currency): string => $amount === null ? '—' : esc($currency) . ' ' . number_format((float) $amount, 2);

/** state => [badge text, badge colours] */
$stateBadge = [
    'active'    => ['Running', 'bg-green-100 text-green-800'],
    'expiring'  => ['Expiring soon', 'bg-amber-100 text-amber-800'],
    'grace'     => ['Grace period', 'bg-amber-100 text-amber-800'],
    'read_only' => ['Read-only', 'bg-orange-100 text-orange-800'],
    'expired'   => ['Expired', 'bg-red-100 text-red-800'],
];

$stateText = static fn ($status): string => match ($status->state) {
    'active'    => $status->daysToEnd . ' days until the end date.',
    'expiring'  => $status->daysToEnd === 0 ? 'Ends today.' : 'Ends in ' . $status->daysToEnd . ' day' . ($status->daysToEnd === 1 ? '' : 's') . '.',
    'grace'     => 'Ended ' . $status->endDate . '. Full access continues until ' . $status->graceEnds . ', then the institution becomes read-only.',
    'read_only' => 'Read-only until ' . $status->readOnlyEnds . ', then the institution becomes past. A renewal brings it back.',
    default     => 'Expired. The institution is past; a renewal brings it back.',
};

/** limit state => [badge text, badge colours, bar colour] */
$usageStates = [
    'unlimited' => ['Unlimited', 'bg-gray-100 text-gray-700', 'bg-gray-300'],
    'ok'        => ['OK', 'bg-green-100 text-green-800', 'bg-green-500'],
    'warning'   => ['Nearly full', 'bg-amber-100 text-amber-800', 'bg-amber-500'],
    'full'      => ['Full', 'bg-red-100 text-red-800', 'bg-red-500'],
];

$kindLabels = ['start' => 'Started', 'renewal' => 'Renewal', 'correction' => 'Correction'];
?>

<?= $this->extend('layouts/admin') ?>

<?= $this->section('content') ?>

<a class="text-sm font-semibold text-[#0040A6] hover:underline" href="<?= site_url('admin/institutions') ?>">&larr; Institutions</a>

<h1 class="mt-2 text-2xl font-bold text-[#0040A6]">Subscription</h1>
<p class="mt-1 text-sm text-gray-600"><?= esc($subInstitution['name']) ?> <span class="font-mono text-xs">(<?= esc($subInstitution['code']) ?>)</span></p>

<?= view('pages/admin/partials/institution-tabs', ['tabInstitution' => $subInstitution, 'tabActive' => 'subscription']) ?>

<?php if (session()->has('message')): ?>
    <p class="mt-4 rounded bg-green-50 px-3 py-2 text-sm text-green-700" role="status"><?= esc((string) session('message')) ?></p>
<?php endif; ?>
<?php if (session()->has('error')): ?>
    <p class="mt-4 rounded bg-red-50 px-3 py-2 text-sm text-red-700" role="alert"><?= esc((string) session('error')) ?></p>
<?php endif; ?>

<?php if ($subscription === null && $subInstitution['status'] === 'pending'): ?>

    <div class="mt-6 rounded-lg border border-amber-300 bg-amber-50 p-6" data-onboarding-pending>
        <h2 class="text-lg font-semibold text-amber-900">Onboarding is not finished</h2>
        <p class="mt-1 text-sm text-amber-900">A new institution gets its first subscription as part of onboarding, together with its first administrator.</p>
        <a class="mt-3 inline-block rounded bg-[#0040A6] px-4 py-2 text-sm font-semibold text-white hover:bg-[#002068]" href="<?= site_url('admin/institutions/' . $subInstitution['id'] . '/onboarding') ?>">Continue onboarding</a>
    </div>

<?php elseif ($subscription === null): ?>

    <div class="mt-6 rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-[#0040A6]">Start a subscription</h2>
        <p class="mt-1 text-sm text-gray-600">This institution has no subscription yet. Pick its plan and the period it has paid for.</p>

        <form method="post" action="<?= site_url('admin/institutions/' . $subInstitution['id'] . '/subscription') ?>" class="mt-4 grid gap-3 sm:grid-cols-2">
            <?= csrf_field() ?>
            <?= view('pages/admin/partials/term-fields', ['tfCurrency' => $subDefaultCurrency, 'tfPlans' => $subPlans, 'tfPlan' => null, 'tfValues' => []]) ?>
            <div class="sm:col-span-2">
                <button type="submit" class="rounded bg-[#0040A6] px-4 py-2 text-sm font-semibold text-white hover:bg-[#002068]">Start subscription</button>
            </div>
        </form>
    </div>

<?php else: ?>

    <div class="mt-6 rounded-lg bg-white p-6 shadow">
        <div class="flex flex-wrap items-center gap-3">
            <h2 class="text-lg font-semibold text-[#0040A6]"><?= esc($subscription['plan_name']) ?></h2>
            <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $stateBadge[$subStatus->state][1] ?>"><?= esc($stateBadge[$subStatus->state][0]) ?></span>
            <?php if ($subscription['plan_status'] === 'retired'): ?><span class="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-700">Plan retired</span><?php endif; ?>
        </div>
        <p class="mt-2 text-sm text-gray-700">
            From <strong><?= esc($subscription['start_date']) ?></strong> to <strong><?= esc($subscription['end_date']) ?></strong>.
        </p>
        <p class="mt-1 text-sm text-gray-600"><?= esc($stateText($subStatus)) ?></p>
    </div>

    <div class="mt-6 rounded-lg bg-white p-6 shadow" data-usage>
        <h2 class="text-lg font-semibold text-[#0040A6]">Usage</h2>
        <p class="mt-1 text-sm text-gray-600">How much of the plan's limits this institution has used. From 90% it is a warning; when a limit is used up, anything that would add to it is refused. Nothing is ever removed.</p>

        <dl class="mt-4 grid gap-4 sm:grid-cols-3">
            <?php foreach ([
                ['Student seats', $subUsage['seats'], number_format($subUsage['seats']['used']), $subUsage['seats']['limit'] === null ? null : number_format($subUsage['seats']['limit'])],
                ['Storage', $subUsage['storage'], number_format($subUsage['storage']['used_mb']) . ' MB', $subUsage['storage']['limit_mb'] === null ? null : number_format($subUsage['storage']['limit_mb']) . ' MB'],
                ['AI generations this term', $subUsage['ai'], number_format($subUsage['ai']['used']), $subUsage['ai']['limit'] === null ? null : number_format($subUsage['ai']['limit'])],
            ] as [$name, $line, $usedText, $limitText]): ?>
                <?php [$stateLabel, $stateClass, $barClass] = $usageStates[$line['state']]; ?>
                <div data-usage-line="<?= esc($line['state']) ?>">
                    <dt class="flex items-center justify-between text-sm font-medium text-gray-700">
                        <?= esc($name) ?>
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $stateClass ?>"><?= esc($stateLabel) ?></span>
                    </dt>
                    <dd class="mt-1 text-sm text-gray-600"><?= esc($usedText) ?> of <?= $limitText === null ? 'unlimited' : esc($limitText) ?><?= $line['percent'] === null ? '' : ' (' . esc((string) $line['percent']) . '%)' ?></dd>
                    <?php if ($line['percent'] !== null): ?>
                        <div class="mt-2 h-2 overflow-hidden rounded bg-gray-200" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= esc((string) min(100, $line['percent'])) ?>" aria-label="<?= esc($name) ?>">
                            <div class="h-full <?= $barClass ?>" style="width: <?= esc((string) min(100, $line['percent'])) ?>%"></div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </dl>
    </div>

    <div class="mt-6 rounded-lg bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-[#0040A6]">Record a renewal</h2>
        <p class="mt-1 text-sm text-gray-600">A renewal adds a new term and moves the end date to the end of it. It must end after <?= esc($subscription['end_date']) ?>.</p>

        <form method="post" action="<?= site_url('admin/institutions/' . $subInstitution['id'] . '/subscription/renew') ?>" class="mt-4 grid gap-3 sm:grid-cols-2">
            <?= csrf_field() ?>
            <?= view('pages/admin/partials/term-fields', ['tfCurrency' => $subDefaultCurrency, 'tfPlans' => $subPlans, 'tfPlan' => (int) $subscription['plan_id'], 'tfValues' => []]) ?>
            <div class="sm:col-span-2">
                <button type="submit" class="rounded bg-[#0040A6] px-4 py-2 text-sm font-semibold text-white hover:bg-[#002068]">Record renewal</button>
            </div>
        </form>
    </div>

    <h2 class="mt-8 text-lg font-semibold text-[#0040A6]">History</h2>
    <p class="mt-1 text-sm text-gray-600">Every term, newest first. Records are never changed or removed; a mistake is put right with a correction.</p>

    <div class="mt-2 overflow-x-auto rounded-lg bg-white shadow">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="<?= $th ?>">Recorded</th>
                    <th class="<?= $th ?>">Type</th>
                    <th class="<?= $th ?>">Plan</th>
                    <th class="<?= $th ?>">Period</th>
                    <th class="<?= $th ?>">Amount</th>
                    <th class="<?= $th ?>">Reference</th>
                    <th class="<?= $th ?>">By</th>
                    <th class="<?= $th ?>">Correct</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($subHistory as $row): ?>
                    <tr>
                        <td class="whitespace-nowrap px-3 py-2 text-gray-600"><?= esc(substr((string) $row['recorded_at'], 0, 16)) ?></td>
                        <td class="px-3 py-2">
                            <?= esc($kindLabels[$row['kind']] ?? $row['kind']) ?>
                            <?php if ($row['corrects_renewal_id'] !== null): ?><span class="block text-xs text-gray-500">of record #<?= esc((string) $row['corrects_renewal_id']) ?></span><?php endif; ?>
                        </td>
                        <td class="px-3 py-2"><?= esc($row['plan_name']) ?></td>
                        <td class="whitespace-nowrap px-3 py-2"><?= esc($row['period_start']) ?> &rarr; <?= esc($row['period_end']) ?></td>
                        <td class="whitespace-nowrap px-3 py-2"><?= $money($row['amount'], (string) $row['currency']) ?></td>
                        <td class="px-3 py-2">
                            <?= esc($row['reference'] ?? '—') ?>
                            <?php if ($row['notes'] !== null): ?><span class="block max-w-xs text-xs text-gray-500"><?= esc($row['notes']) ?></span><?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-600"><?= esc($row['recorded_by_label'] ?? '—') ?></td>
                        <td class="px-3 py-2">
                            <details>
                                <summary class="<?= $btn ?> cursor-pointer border-gray-300 text-gray-700 hover:bg-gray-50">Correct</summary>
                                <form method="post" action="<?= site_url('admin/institutions/' . $subInstitution['id'] . '/subscription/renewals/' . $row['id'] . '/correct') ?>" class="mt-2 flex w-56 flex-col gap-2">
                                    <?= csrf_field() ?>
                                    <label class="<?= $label ?>">Amount
                                        <input class="<?= $input ?>" type="text" inputmode="decimal" name="amount" value="<?= esc((string) ($row['amount'] ?? '')) ?>">
                                    </label>
                                    <label class="<?= $label ?>">Currency
                                        <input class="<?= $input ?> uppercase" type="text" name="currency" maxlength="3" value="<?= esc((string) $row['currency']) ?>">
                                    </label>
                                    <label class="<?= $label ?>">Reference
                                        <input class="<?= $input ?>" type="text" name="reference" maxlength="100" value="<?= esc((string) ($row['reference'] ?? '')) ?>">
                                    </label>
                                    <label class="<?= $label ?>">Notes
                                        <textarea class="<?= $input ?>" name="notes" rows="2" maxlength="1000"><?= esc((string) ($row['notes'] ?? '')) ?></textarea>
                                    </label>
                                    <button type="submit" class="<?= $btn ?> border-[#0040A6] text-[#0040A6] hover:bg-[#0040A6]/10">Add correction</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

<?= $this->endSection() ?>
