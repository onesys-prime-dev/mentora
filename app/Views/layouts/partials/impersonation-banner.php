<?php
/**
 * Shown on every page while a system administrator is viewing as someone.
 * Cannot be missed and cannot be hidden: it is part of the layout.
 */
use App\Services\Admin\Impersonation;
use CodeIgniter\Shield\Models\UserModel;

$viewing = Impersonation::current();

if ($viewing === null) {
    return;
}

$person = model(UserModel::class)->findById($viewing['target_id']);
$label  = $person !== null ? (string) ($person->email ?: $person->username) : 'this person';
$left   = Impersonation::minutesLeft();
?>
<div class="flex flex-wrap items-center justify-between gap-3 bg-amber-500 px-6 py-2 text-sm font-semibold text-white" role="alert" data-impersonation-banner>
    <span>
        Viewing as <?= esc($label) ?> (view only, nothing you do here is saved).
        <span class="font-normal"><?= esc((string) $left) ?> min left. Reason: <?= esc($viewing['reason']) ?></span>
    </span>
    <form method="post" action="<?= site_url('impersonation/stop') ?>">
        <?= csrf_field() ?>
        <button type="submit" class="rounded bg-white px-3 py-1 text-xs font-bold text-amber-700 hover:bg-amber-50">Exit view</button>
    </form>
</div>
