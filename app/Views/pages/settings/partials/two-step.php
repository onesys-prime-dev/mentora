<?php
/**
 * Settings: the Two-step sign-in card (D14). A switch turns the emailed code on; turning it off asks for the
 * password in a small dialog. A school requirement shows the switch on and disabled.
 *
 * @var array{on: bool, required: bool, email_masked: string} $twoFactor
 * @var array<string, string>                                  $errors
 */
$error  = $errors['two_factor_password'] ?? '';
// Every change reloads the page, so the switch is drawn in its state here (the institution Profile switch's look).
$track  = 'inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:cursor-not-allowed disabled:opacity-60 pointer-coarse:my-2.5 ' . ($twoFactor['on'] ? 'bg-brand' : 'bg-gray-300');
$thumb  = '<span class="ml-1 h-4 w-4 rounded-full bg-white shadow transition-transform' . ($twoFactor['on'] ? ' translate-x-5' : '') . '" aria-hidden="true"></span>';
$switch = 'role="switch" aria-checked="' . ($twoFactor['on'] ? 'true' : 'false') . '" aria-labelledby="two-step-label" aria-describedby="two-step-status"';
?>
<h2 class="text-lg font-bold text-brand">Two-step sign-in</h2>
<p class="text-sm text-[#646464]">After your password, we email you a code to finish signing in.</p>

<div class="flex items-center justify-between gap-4 rounded-xl border border-gray-200 px-4 py-3">
    <div class="flex min-w-0 flex-col gap-0.5">
        <span id="two-step-label" class="text-sm font-semibold text-[#1A1C1E]">Email me a sign-in code</span>
        <span id="two-step-status" class="text-xs text-[#646464]">
            <?= $twoFactor['on'] ? esc('On — we email a code to ' . $twoFactor['email_masked'] . ' when you sign in') : 'Off' ?>
        </span>
        <?php if ($twoFactor['required']): ?>
            <span class="text-xs font-medium text-brand">Required by your school</span>
        <?php endif; ?>
    </div>

    <?php if ($twoFactor['required']): ?>
        <button type="button" <?= $switch ?> disabled class="<?= $track ?>"><?= $thumb ?></button>
    <?php elseif ($twoFactor['on']): ?>
        <button type="button" <?= $switch ?> data-dialog-open="two-step-off" class="<?= $track ?>"><?= $thumb ?></button>
    <?php else: ?>
        <form action="<?= esc(site_url('settings/two-factor'), 'attr') ?>" method="post" class="contents">
            <?= csrf_field() ?>
            <button type="submit" name="action" value="on" <?= $switch ?> class="<?= $track ?>"><?= $thumb ?></button>
        </form>
    <?php endif; ?>
</div>

<?php if ($error !== '' && ($twoFactor['required'] || ! $twoFactor['on'])): ?>
    <p class="text-xs font-medium text-[#C33939]" role="alert"><?= esc($error) ?></p>
<?php endif; ?>

<?php if ($twoFactor['on'] && ! $twoFactor['required']): ?>
    <?php
    $body = '<form action="' . esc(site_url('settings/two-factor'), 'attr') . '" method="post" class="flex flex-col gap-4">'
        . csrf_field() . '<input type="hidden" name="action" value="off">'
        . '<p>Signing in will need your password only. Type your password to turn it off.</p>'
        . view('pages/settings/partials/password-field', ['name' => 'password', 'id' => 'two-step-password', 'label' => 'Password', 'autocomplete' => 'current-password', 'error' => $error], ['saveData' => false])
        . '<div class="flex flex-wrap items-center justify-end gap-3">'
        . '<button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50 pointer-coarse:min-h-11">Cancel</button>'
        . '<button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11">Turn off</button>'
        . '</div></form>';
    ?>
    <?= view('components/dialog', ['id' => 'two-step-off', 'title' => 'Turn off two-step sign-in?', 'body' => $body, 'size' => 'sm', 'centered' => true, 'autoOpen' => $error !== ''], ['saveData' => false]) ?>
<?php endif; ?>
