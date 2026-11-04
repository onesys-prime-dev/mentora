<?php
/**
 * @var list<array<string, mixed>> $noteRows
 * @var bool                       $noteIsAdmin
 */
$unreadRows  = array_filter($noteRows, static fn (array $row): bool => $row['read_at'] === null);
$unread      = count($unreadRows);
$unreadMsgs  = count(array_filter($unreadRows, static fn (array $row): bool => $row['type'] === 'message'));
$unreadUrgent = count(array_filter($unreadRows, static fn (array $row): bool => (int) $row['urgent'] === 1));

// Newest first, grouped by the day they arrived.
$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$groups    = [];

foreach ($noteRows as $row) {
    $day   = substr((string) $row['created_at'], 0, 10);
    $label = $day === $today ? 'Today' : ($day === $yesterday ? 'Yesterday' : 'Earlier');

    $groups[$label][] = $row;
}
?>

<?= $this->extend($noteIsAdmin ? 'layouts/admin' : 'layouts/app') ?>

<?= $this->section('content') ?>

<div class="mx-auto flex max-w-3xl flex-col gap-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-brand">Notifications</h1>
        <?php if ($unread > 0): ?>
            <form method="post" action="<?= site_url('notifications/read') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="h-9 rounded-full border border-brand bg-white px-4 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55">Mark all as read</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-3 gap-3" data-notification-summary>
        <div class="rounded-2xl bg-brand px-5 py-4 text-white shadow-md">
            <div class="text-3xl font-bold leading-tight"><?= $unread ?></div>
            <div class="text-sm opacity-85">Unread</div>
        </div>
        <div class="rounded-2xl bg-white px-5 py-4 shadow">
            <div class="text-3xl font-bold leading-tight text-brand"><?= $unreadMsgs ?></div>
            <div class="text-sm text-gray-500">New messages</div>
        </div>
        <div class="rounded-2xl bg-white px-5 py-4 shadow">
            <div class="text-3xl font-bold leading-tight <?= $unreadUrgent > 0 ? 'text-[#C33939]' : 'text-brand' ?>"><?= $unreadUrgent ?></div>
            <div class="text-sm text-gray-500">Urgent</div>
        </div>
    </div>

    <?php if ($noteRows === []): ?>
        <p class="rounded-2xl bg-white p-8 text-center text-gray-500 shadow">Nothing yet.</p>
    <?php endif; ?>

    <?php foreach ($groups as $label => $rows): ?>
        <section aria-label="<?= esc($label, 'attr') ?>">
            <h2 class="mb-3 text-xs font-semibold uppercase tracking-wider text-brand"><?= esc($label) ?></h2>

            <ul class="relative flex flex-col gap-3 pl-7">
                <li class="pointer-events-none absolute bottom-2 left-[9px] top-2 w-0.5 bg-[#c7d7f0]" aria-hidden="true"></li>

                <?php foreach ($rows as $row): ?>
                    <?php
                    $isUnread = $row['read_at'] === null;
                    $urgent   = (int) $row['urgent'] === 1;
                    $accent   = $urgent ? '#C33939' : ($row['type'] === 'message' ? '#0040A6' : '#6b7fa3');
                    ?>
                    <li
                        class="relative rounded-2xl p-4 <?= $isUnread ? 'bg-white shadow' : 'border border-[#e3e8f0] bg-[#f7f9fc]' ?>"
                        <?= $isUnread ? 'style="border-top: 3px solid ' . $accent . '"' : '' ?>
                        data-notification="<?= $isUnread ? 'unread' : 'read' ?>">
                        <span class="absolute -left-7 top-4 box-border h-5 w-5 rounded-full border-4 border-[#dbe9fb]" style="background: <?= $isUnread ? $accent : '#b8c4d9' ?>" aria-hidden="true"></span>

                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="flex items-center gap-2 text-sm <?= $isUnread ? 'font-semibold text-gray-900' : 'font-medium text-gray-600' ?>">
                                <?= esc($row['title']) ?>
                                <?php if ($urgent): ?><span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800">Urgent</span><?php endif; ?>
                            </h3>
                            <time class="text-xs text-gray-500" datetime="<?= esc((string) $row['created_at'], 'attr') ?>"><?= esc(substr((string) $row['created_at'], $label === 'Earlier' ? 5 : 11, $label === 'Earlier' ? 11 : 5)) ?></time>
                        </div>
                        <p class="mt-1 text-sm <?= $isUnread ? 'text-gray-700' : 'text-gray-500' ?>"><?= esc((string) $row['body']) ?></p>

                        <?php if ($row['link'] !== null || $isUnread): ?>
                            <div class="mt-3 flex items-center gap-4 text-sm font-semibold">
                                <?php if ($row['link'] !== null): ?><a class="text-brand hover:underline" href="<?= site_url((string) $row['link']) ?>">Open</a><?php endif; ?>
                                <?php if ($isUnread): ?>
                                    <form method="post" action="<?= site_url('notifications/read') ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= esc((string) $row['id']) ?>">
                                        <button type="submit" class="text-gray-500 hover:underline">Mark as read</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</div>

<?= $this->endSection() ?>
