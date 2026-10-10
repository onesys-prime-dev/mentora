<?php
/**
 * The plan and period fields shared by "start a subscription", "record a renewal"
 * and the onboarding step. Echoes grid cells; the caller supplies the <form> and
 * a `sm:grid-cols-2` grid around them. What was typed before a refusal comes back
 * through old(); `tfValues` fills the fields otherwise.
 *
 * @var string                       $tfCurrency default currency code
 * @var list<array<string, mixed>>   $tfPlans    plans to choose from (id, name)
 * @var int|null                     $tfPlan     plan selected when nothing was typed
 * @var array<string, mixed>         $tfValues   saved values (plan_id, period_start, ...), may be empty
 */
$tfInput = 'rounded border border-gray-300 bg-white px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#0040A6]/55';
$tfLabel = 'flex flex-col gap-1 text-xs font-medium text-gray-600';
$tfValue = static fn (string $name, string $default = ''): string => (string) old($name, (string) ($tfValues[$name] ?? $default));
?>
<label class="<?= $tfLabel ?>">Plan
    <select class="<?= $tfInput ?>" name="plan_id" required>
        <option value="">Choose a plan</option>
        <?php foreach ($tfPlans as $plan): ?>
            <option value="<?= esc((string) $plan['id']) ?>" <?= $tfValue('plan_id', (string) $tfPlan) === (string) $plan['id'] ? 'selected' : '' ?>><?= esc($plan['name']) ?></option>
        <?php endforeach; ?>
    </select>
</label>
<label class="<?= $tfLabel ?>">Period starts
    <input class="<?= $tfInput ?>" type="date" name="period_start" value="<?= esc($tfValue('period_start')) ?>" required>
</label>
<label class="<?= $tfLabel ?>">Period ends
    <input class="<?= $tfInput ?>" type="date" name="period_end" value="<?= esc($tfValue('period_end')) ?>" required>
</label>
<label class="<?= $tfLabel ?>">Amount <span class="font-normal text-gray-500">(reference only, optional)</span>
    <input class="<?= $tfInput ?>" type="text" inputmode="decimal" name="amount" value="<?= esc($tfValue('amount')) ?>" placeholder="0.00">
</label>
<label class="<?= $tfLabel ?>">Currency
    <input class="<?= $tfInput ?> uppercase" type="text" name="currency" maxlength="3" value="<?= esc($tfValue('currency', $tfCurrency)) ?>">
</label>
<label class="<?= $tfLabel ?>">Reference <span class="font-normal text-gray-500">(invoice, transfer…)</span>
    <input class="<?= $tfInput ?>" type="text" name="reference" maxlength="100" value="<?= esc($tfValue('reference')) ?>">
</label>
<label class="<?= $tfLabel ?> sm:col-span-2">Notes
    <textarea class="<?= $tfInput ?>" name="notes" rows="2" maxlength="1000"><?= esc($tfValue('notes')) ?></textarea>
</label>
