<?php
/**
 * The three onboarding steps, with the current one marked (components/stepper).
 *
 * @var int $obStep 1 (institution), 2 (plan and subscription) or 3 (administrator)
 */
?>
<?= view('components/stepper', [
    'steps'     => ['Institution', 'Plan and subscription', 'Administrator'],
    'current'   => $obStep,
    'ariaLabel' => 'Onboarding steps',
    'attrs'     => 'data-onboarding-steps',
], ['saveData' => false]) ?>
