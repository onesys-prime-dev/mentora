<?php
/**
 * Shown under the header to people whose institution cannot be changed
 * (read-only, past or deleted). Nothing is printed for everyone else,
 * including system administrators.
 */
$bannerUser = auth()->user();
$bannerState = $bannerUser === null ? 'writable' : (new App\Services\Institutions\InstitutionAccess())->stateFor((int) $bannerUser->id);
?>
<?php if ($bannerState !== 'writable'): ?>
    <div class="border-b border-amber-300 bg-amber-50 px-6 py-2 text-sm text-amber-900" role="status" data-read-only-banner>
        <strong>
            <?= $bannerState === 'read_only' ? 'Read-only.' : 'Institution no longer active.' ?>
        </strong>
        <?= $bannerState === 'read_only'
            ? "Your institution's subscription has ended. You can still look at and export your work, but nothing can be changed."
            : 'You can still look at and export your work, but nothing can be changed.' ?>
        Please contact your institution's administrator.
    </div>
<?php endif; ?>
