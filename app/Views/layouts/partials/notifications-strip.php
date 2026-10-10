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
<div class="bg-blue-50 px-6 py-2 text-sm text-[#0040A6]" data-notifications-strip>
    You have <strong><?= esc((string) $unreadCount) ?></strong> unread <?= $unreadCount === 1 ? 'notification' : 'notifications' ?>.
    <a class="font-semibold underline" href="<?= site_url('notifications') ?>">Read them</a>
</div>
