<?php
/** A line under the header when the signed-in person has unread notifications. */
if (! auth()->loggedIn()) {
    return;
}

$unreadCount = (new App\Services\Notifications\NotificationService())->unreadCount((int) auth()->id());

if ($unreadCount === 0) {
    return;
}
?>
<div class="bg-blue-50 px-6 py-2 text-sm text-brand" data-notifications-strip>
    You have <strong data-unread-count><?= esc((string) $unreadCount) ?></strong> unread <?= $unreadCount === 1 ? 'notification' : 'notifications' ?>.
    <a class="font-semibold underline" href="<?= site_url('notifications') ?>">Read them</a>
</div>
