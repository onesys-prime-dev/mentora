<?php
/**
 * Settings: the Notifications card (D15). Who may email you about new messages, quiet hours (school default, your own, or none) and a parent's email choice for each child, moved here unchanged. One form, one Save.
 *
 * @var array{
 *     messages: array{quiet_mode: string, quiet_start: ?string, quiet_end: ?string, email_alerts: bool, email_scope: string, school_default: array{0: ?string, 1: ?string}}|null,
 *     parent: array{portal_on: bool, children: list<array{student_id: int, name: string, email: bool}>}|null,
 * } $notifications
 * @var array<string, string> $errors
 */
$messages = $notifications['messages'];
$parent   = $notifications['parent'];
$error    = $errors['quiet_start'] ?? '';
$row      = 'flex items-center justify-between gap-4 border-t border-gray-100 py-3 first:border-t-0';
$scopeError = $errors['email_scope'] ?? '';
$scope    = (string) old('email_scope', $messages['email_scope'] ?? 'everyone');
$choices  = ['off' => 'Off', 'everyone' => 'Everyone', 'talked' => "People I've talked to"];
$time     = 'rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm pointer-coarse:min-h-11 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';

/** A checkbox drawn as a switch (the two-step switch's look); it posts with the form. */
$switch = static fn (string $name, string $value, bool $on, string $labelledBy): string => '<span class="relative inline-flex shrink-0 pointer-coarse:my-2.5">'
    . '<input type="checkbox" role="switch" name="' . esc($name) . '" value="' . esc($value) . '" aria-labelledby="' . esc($labelledBy) . '"' . ($on ? ' checked' : '')
    . ' class="peer h-6 w-11 cursor-pointer appearance-none rounded-full bg-gray-300 transition-colors checked:bg-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">'
    . '<span class="pointer-events-none absolute left-1 top-1 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5" aria-hidden="true"></span></span>';
?>
<h2 class="text-lg font-bold text-brand">Notifications</h2>
<p class="text-sm text-[#646464]">The emails Mentora sends you. Notifications in the app are always on.</p>

<form action="<?= esc(site_url('settings/notifications')) ?>" method="post" class="flex flex-col">
    <?= csrf_field() ?>

    <?php if ($messages !== null): ?>
        <fieldset class="flex flex-col gap-2 py-3"<?= $scopeError !== '' ? ' aria-describedby="notify-scope-error"' : '' ?>>
            <legend class="text-sm font-semibold text-[#1A1C1E]">Email me about new messages</legend>
            <p class="text-xs text-[#646464]">Emails say who wrote and where, never what was written. Notices in the app always arrive.</p>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($choices as $value => $label): ?>
                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm text-[#1A1C1E] has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand/50 pointer-coarse:min-h-11">
                        <input type="radio" name="email_scope" value="<?= esc($value) ?>"<?= $scope === $value ? ' checked' : '' ?> class="h-4 w-4 accent-brand focus-visible:outline-none">
                        <?= esc($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if ($scopeError !== ''): ?>
                <p id="notify-scope-error" class="text-xs font-medium text-[#C33939]" role="alert"><?= esc($scopeError) ?></p>
            <?php endif; ?>
        </fieldset>

        <?php
        $quietMode  = (string) old('quiet_mode', $messages['quiet_mode']);
        $quietError = $errors['quiet_mode'] ?? $error;
        [$schoolStart, $schoolEnd] = $messages['school_default'];
        $quietChoices = ['default' => 'School default' . ($schoolStart !== null ? ' (' . $schoolStart . ' to ' . $schoolEnd . ')' : ''), 'own' => 'My own', 'none' => 'None'];
        ?>
        <fieldset class="group/quiet flex flex-col gap-2 border-t border-gray-100 py-3"<?= $quietError !== '' ? ' aria-describedby="notify-quiet-error"' : '' ?>>
            <legend class="text-sm font-semibold text-[#1A1C1E]">Quiet hours</legend>
            <p class="text-xs text-[#646464]">Nothing is notified or emailed during quiet hours; messages still arrive and are counted.</p>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($quietChoices as $value => $label): ?>
                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-300 px-3 py-2 text-sm text-[#1A1C1E] has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand/50 pointer-coarse:min-h-11">
                        <input type="radio" id="notify-quiet-<?= esc($value) ?>" name="quiet_mode" value="<?= esc($value) ?>"<?= $quietMode === $value ? ' checked' : '' ?> class="h-4 w-4 accent-brand focus-visible:outline-none">
                        <?= esc($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="hidden flex-wrap items-center gap-2 group-has-[#notify-quiet-own:checked]/quiet:flex">
                <label for="notify-quiet-start" class="text-xs text-gray-600">From</label>
                <input id="notify-quiet-start" type="time" name="quiet_start" value="<?= esc((string) old('quiet_start', $messages['quiet_mode'] === 'own' ? ($messages['quiet_start'] ?? '') : '')) ?>" class="<?= $time ?>"<?= $error !== '' ? ' aria-invalid="true"' : '' ?>>
                <label for="notify-quiet-end" class="text-xs text-gray-600">to</label>
                <input id="notify-quiet-end" type="time" name="quiet_end" value="<?= esc((string) old('quiet_end', $messages['quiet_mode'] === 'own' ? ($messages['quiet_end'] ?? '') : '')) ?>" class="<?= $time ?>">
            </div>
            <?php if ($quietError !== ''): ?>
                <p id="notify-quiet-error" class="text-xs font-medium text-[#C33939]" role="alert"><?= esc($quietError) ?></p>
            <?php endif; ?>
        </fieldset>
    <?php endif; ?>

    <?php if ($parent !== null): ?>
        <fieldset class="flex flex-col border-t border-gray-100 pt-3 first:border-t-0">
            <legend class="text-sm font-semibold text-[#1A1C1E]">Your children</legend>

            <?php if (! $parent['portal_on']): ?>
                <p class="pt-2 text-sm text-[#646464]">The parent portal is switched off right now, so there is nothing to choose. Contact your school.</p>
            <?php elseif ($parent['children'] === []): ?>
                <p class="pt-2 text-sm text-[#646464]">You are not linked to a student yet.</p>
            <?php else: ?>
                <?php foreach ($parent['children'] as $child): ?>
                    <?php $id = 'notify-child-' . (int) $child['student_id']; ?>
                    <div class="<?= $row ?>">
                        <div class="flex min-w-0 flex-col gap-0.5">
                            <span id="<?= $id ?>" class="text-sm font-semibold text-[#1A1C1E]"><?= esc($child['name']) ?></span>
                            <span class="text-xs text-[#646464]">Email me about <?= esc(strtok($child['name'], ' ') ?: 'this child') ?></span>
                        </div>
                        <?= $switch('email[]', (string) (int) $child['student_id'], $child['email'], $id) ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </fieldset>
    <?php endif; ?>

    <div class="pt-3">
        <button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 max-sm:w-full pointer-coarse:min-h-11">Save notifications</button>
    </div>
</form>
