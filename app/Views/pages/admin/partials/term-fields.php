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
$tfValue = static fn (string $name, string $default = ''): string => (string) old($name, (string) ($tfValues[$name] ?? $default));

$tfPlanOptions = ['' => 'Choose a plan'];

foreach ($tfPlans as $plan) {
    $tfPlanOptions[(string) $plan['id']] = (string) $plan['name'];
}

$tfField = static fn (array $spec): string => view('components/form-field', $spec, ['saveData' => false]);
?>
<?= $tfField(['name' => 'plan_id', 'label' => 'Plan', 'type' => 'select', 'options' => $tfPlanOptions, 'value' => $tfValue('plan_id', (string) $tfPlan), 'required' => true]) ?>
<?= $tfField(['name' => 'period_start', 'label' => 'Period starts', 'type' => 'date', 'value' => $tfValue('period_start'), 'required' => true]) ?>
<?= $tfField(['name' => 'period_end', 'label' => 'Period ends', 'type' => 'date', 'value' => $tfValue('period_end'), 'required' => true]) ?>
<?= $tfField(['name' => 'amount', 'label' => 'Amount (reference only, optional)', 'value' => $tfValue('amount'), 'placeholder' => '0.00', 'attrs' => 'inputmode="decimal"']) ?>
<?= $tfField(['name' => 'currency', 'label' => 'Currency', 'value' => $tfValue('currency', $tfCurrency), 'maxlength' => 3, 'attrs' => 'autocapitalize="characters"']) ?>
<?= $tfField(['name' => 'reference', 'label' => 'Reference (invoice, transfer…)', 'value' => $tfValue('reference'), 'maxlength' => 100]) ?>
<?= $tfField(['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => $tfValue('notes'), 'maxlength' => 1000, 'span' => 'sm:col-span-2']) ?>
