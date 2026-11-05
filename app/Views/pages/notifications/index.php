<?php
/**
 * Notifications (design A, grouped feed in a card holder). One panel with the title, filter chips and the
 * notifications grouped by day. A click goes to that notification (and marks it read); a right click,
 * long press or Shift+F10 opens its menu: Mark as read, Delete. pages/notifications/notifications.js does
 * the menu, the filters and the requests; each row is a plain link, so it works without the script.
 *
 * @var list<array<string, mixed>> $noteRows
 * @var bool                       $noteIsAdmin
 */
use CodeIgniter\I18n\Time;

$unread = count(array_filter($noteRows, static fn (array $row): bool => $row['read_at'] === null));

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$groups    = [];

foreach ($noteRows as $row) {
    $day = substr((string) $row['created_at'], 0, 10);

    $groups[$day === $today ? 'Today' : ($day === $yesterday ? 'Yesterday' : 'Earlier')][] = $row;
}

$chip    = 'h-8 rounded-full border px-3.5 text-[13px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55';
$chipOn  = 'border-brand bg-brand font-semibold text-white';
$chipOff = 'border-gray-300 bg-white text-gray-700';
?>

<?= $this->extend($noteIsAdmin ? 'layouts/admin' : 'layouts/app') ?>

<?= $this->section('content') ?>

<div class="mx-auto mt-5 flex h-[calc(100dvh-8.3rem)] min-h-[440px] w-full max-w-3xl flex-col gap-4 rounded-[28px] border border-gray-200 bg-white p-5 shadow-[0_8px_30px_rgba(0,40,120,0.12)] sm:p-7"
     data-notifications-page
     data-read-url="<?= esc(site_url('notifications/read'), 'attr') ?>"
     data-delete-url="<?= esc(site_url('notifications/delete'), 'attr') ?>">

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-brand">Notifications</h1>
            <p class="mt-0.5 text-sm text-gray-500" data-role="summary"></p>
        </div>
        <form method="post" action="<?= site_url('notifications/read') ?>" data-role="mark-all" <?= $unread > 0 ? '' : 'hidden' ?>>
            <?= csrf_field() ?>
            <button type="submit" class="h-9 rounded-full border border-brand bg-white px-4 text-[13px] font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55">Mark all as read</button>
        </form>
    </div>

    <div class="flex flex-wrap gap-2" role="group" aria-label="Filter notifications">
        <button type="button" data-filter="all" aria-pressed="true" data-on="<?= $chipOn ?>" data-off="<?= $chipOff ?>" class="<?= $chip ?> <?= $chipOn ?>">All</button>
        <button type="button" data-filter="unread" aria-pressed="false" data-on="<?= $chipOn ?>" data-off="<?= $chipOff ?>" class="<?= $chip ?> <?= $chipOff ?>">Unread · <span data-role="unread-count"><?= $unread ?></span></button>
        <button type="button" data-filter="message" aria-pressed="false" data-on="<?= $chipOn ?>" data-off="<?= $chipOff ?>" class="<?= $chip ?> <?= $chipOff ?>">Messages</button>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto pr-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" data-role="list">
        <?php foreach ($groups as $label => $rows): ?>
            <section class="mb-4 overflow-hidden rounded-2xl bg-white shadow-sm" data-group aria-label="<?= esc($label, 'attr') ?>">
                <h2 class="px-5 pb-1.5 pt-3.5 text-xs font-semibold uppercase tracking-wider text-gray-500"><?= esc($label) ?></h2>
                <ul>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $isUnread = $row['read_at'] === null;
                        $urgent   = (int) $row['urgent'] === 1;
                        $href     = $row['link'] !== null ? site_url((string) $row['link']) : null;
                        $tag      = $href !== null ? 'a' : 'div';
                        ?>
                        <li data-id="<?= (int) $row['id'] ?>" data-notification="<?= $isUnread ? 'unread' : 'read' ?>" data-type="<?= esc((string) $row['type'], 'attr') ?>" class="border-t border-[#eef2f7]">
                            <<?= $tag ?> <?= $href !== null ? 'href="' . esc($href, 'attr') . '"' : 'tabindex="0"' ?> data-role="row"
                                class="flex gap-3.5 px-5 py-3.5 transition hover:bg-[#e8f0fd] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/55 <?= $isUnread ? 'bg-[#F2F7FF]' : '' ?>">
                                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full <?= $isUnread ? 'bg-brand text-white' : 'bg-gray-100 text-gray-600' ?>" data-role="badge" aria-hidden="true">
                                    <?php if ($row['type'] === 'message'): ?>
                                        <svg viewBox="0 0 20 20" class="h-[18px] w-[18px]" fill="currentColor"><path d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7z"/></svg>
                                    <?php else: ?>
                                        <svg viewBox="0 0 20 20" class="h-[18px] w-[18px]" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
                                    <?php endif; ?>
                                </span>
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span class="flex items-center gap-2">
                                        <span class="text-[15px] <?= $isUnread ? 'font-semibold text-gray-900' : 'font-medium text-gray-700' ?>" data-role="title"><?= esc($row['title']) ?></span>
                                        <?php if ($urgent): ?><span class="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-800">Urgent</span><?php endif; ?>
                                    </span>
                                    <span class="text-sm text-gray-600"><?= esc((string) $row['body']) ?></span>
                                    <span class="mt-1 text-xs text-gray-500"><?= esc(Time::parse((string) $row['created_at'])->humanize()) ?> · <?= esc(date('g:i A', (int) strtotime((string) $row['created_at']))) ?></span>
                                </span>
                                <span class="mt-2 h-2.5 w-2.5 shrink-0 rounded-full bg-brand <?= $isUnread ? '' : 'invisible' ?>" data-role="dot" aria-hidden="true"></span>
                            </<?= $tag ?>>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>

        <p class="py-10 text-center text-sm text-gray-500 <?= $noteRows === [] ? '' : 'hidden' ?>" data-role="empty">Nothing yet.</p>
        <p class="hidden py-10 text-center text-sm text-gray-500" data-role="filter-empty">Nothing here.</p>
    </div>

    <p class="text-center text-xs text-gray-500">Click a notification to open it. Right-click it to mark it as read or delete it.</p>
</div>

<?= $this->endSection() ?>
