<?php
/**
 * Notifications: a list on the left, the chosen one on the right (design B). Each notification is a
 * button in the list and a hidden panel beside it; pages/notifications/notifications.js shows the
 * panel, marks it read and filters the list. Without the script the first one stays open.
 *
 * @var list<array<string, mixed>> $noteRows
 * @var bool                       $noteIsAdmin
 */
$unread = count(array_filter($noteRows, static fn (array $row): bool => $row['read_at'] === null));

// The first unread one opens first, else the newest.
$firstId = $noteRows === [] ? 0 : (int) $noteRows[0]['id'];

foreach ($noteRows as $row) {
    if ($row['read_at'] === null) {
        $firstId = (int) $row['id'];
        break;
    }
}

$when = static function (array $row): string {
    return \CodeIgniter\I18n\Time::parse((string) $row['created_at'])->humanize();
};
?>

<?= $this->extend($noteIsAdmin ? 'layouts/admin' : 'layouts/app') ?>

<?= $this->section('content') ?>

<div class="mx-auto grid w-full max-w-6xl gap-4 md:grid-cols-[400px_1fr]" data-notifications-page data-read-url="<?= esc(site_url('notifications/read'), 'attr') ?>">

    <section class="flex min-h-0 flex-col overflow-hidden rounded-2xl bg-white shadow-sm md:max-h-[calc(100dvh-9rem)]" aria-label="Notifications">
        <div class="flex items-center justify-between gap-2 px-5 pb-2 pt-4">
            <h1 class="text-xl font-bold text-brand">Notifications</h1>
            <form method="post" action="<?= site_url('notifications/read') ?>" data-role="mark-all" <?= $unread > 0 ? '' : 'hidden' ?>>
                <?= csrf_field() ?>
                <button type="submit" class="h-8 rounded-full border border-gray-300 bg-white px-3 text-xs font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55">Mark all read</button>
            </form>
        </div>

        <div class="flex gap-2 px-5 pb-3" role="group" aria-label="Filter notifications">
            <button type="button" data-filter="all" aria-pressed="true" class="h-8 rounded-full border border-brand bg-brand px-3 text-xs font-semibold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55">All</button>
            <button type="button" data-filter="unread" aria-pressed="false" class="h-8 rounded-full border border-gray-300 bg-white px-3 text-xs text-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55">Unread · <span data-role="unread-count"><?= $unread ?></span></button>
            <button type="button" data-filter="message" aria-pressed="false" class="h-8 rounded-full border border-gray-300 bg-white px-3 text-xs text-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55">Messages</button>
        </div>

        <?php if ($noteRows === []): ?>
            <p class="px-5 py-10 text-center text-sm text-gray-500">Nothing yet.</p>
        <?php endif; ?>

        <ul class="min-h-0 flex-1 overflow-y-auto" data-role="list">
            <?php foreach ($noteRows as $row): ?>
                <?php
                $isUnread = $row['read_at'] === null;
                $isFirst  = (int) $row['id'] === $firstId;
                ?>
                <li data-notification="<?= $isUnread ? 'unread' : 'read' ?>" data-type="<?= esc((string) $row['type'], 'attr') ?>" class="border-t border-[#eef2f7] first:border-t-0">
                    <button type="button" data-open="<?= (int) $row['id'] ?>" aria-controls="notification-<?= (int) $row['id'] ?>" aria-current="<?= $isFirst ? 'true' : 'false' ?>"
                        class="flex w-full gap-3 border-l-4 border-transparent px-5 py-3 text-left transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/55 aria-[current=true]:border-brand aria-[current=true]:bg-brand-tint">
                        <span class="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-full <?= $isUnread ? 'bg-brand text-white' : 'bg-gray-100 text-gray-600' ?>" data-role="badge" aria-hidden="true">
                            <?php if ($row['type'] === 'message'): ?>
                                <svg viewBox="0 0 20 20" class="h-4 w-4" fill="currentColor"><path d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7z"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 20 20" class="h-4 w-4" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
                            <?php endif; ?>
                        </span>
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="text-sm <?= $isUnread ? 'font-semibold text-gray-900' : 'font-medium text-gray-600' ?>" data-role="title"><?= esc($row['title']) ?></span>
                            <span class="truncate text-[13px] text-gray-600"><?= esc((string) $row['body']) ?></span>
                            <span class="text-xs text-gray-500"><?= esc($when($row)) ?></span>
                        </span>
                        <span class="mt-2 h-2 w-2 shrink-0 rounded-full bg-brand <?= $isUnread ? '' : 'invisible' ?>" data-role="dot" aria-hidden="true"></span>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="hidden px-5 py-8 text-center text-sm text-gray-500" data-role="filter-empty">Nothing here.</p>
    </section>

    <section class="rounded-2xl bg-white p-6 shadow-sm md:p-8" aria-label="Notification">
        <?php foreach ($noteRows as $row): ?>
            <?php $urgent = (int) $row['urgent'] === 1; ?>
            <article id="notification-<?= (int) $row['id'] ?>" data-detail="<?= (int) $row['id'] ?>" class="flex flex-col gap-4" <?= (int) $row['id'] === $firstId ? '' : 'hidden' ?>>
                <div class="flex items-center gap-3">
                    <span class="grid h-12 w-12 shrink-0 place-items-center rounded-full <?= $urgent ? 'bg-[#C33939]' : 'bg-brand' ?> text-white" aria-hidden="true">
                        <svg viewBox="0 0 20 20" class="h-5 w-5" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
                    </span>
                    <div>
                        <h2 class="flex items-center gap-2 text-xl font-semibold text-gray-900">
                            <?= esc($row['title']) ?>
                            <?php if ($urgent): ?><span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800">Urgent</span><?php endif; ?>
                        </h2>
                        <p class="text-sm text-gray-500"><?= esc(substr((string) $row['created_at'], 0, 16)) ?> · <?= esc($when($row)) ?></p>
                    </div>
                </div>
                <p class="text-base leading-relaxed text-gray-700"><?= esc((string) $row['body']) ?></p>
                <div class="flex flex-wrap gap-2">
                    <?php if ($row['link'] !== null): ?>
                        <a href="<?= site_url((string) $row['link']) ?>" class="inline-flex h-10 items-center rounded-full bg-brand px-5 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55 focus-visible:ring-offset-2">Open</a>
                    <?php endif; ?>
                    <form method="post" action="<?= site_url('notifications/read') ?>" data-role="read-form" <?= $row['read_at'] === null ? '' : 'hidden' ?>>
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= esc((string) $row['id']) ?>">
                        <button type="submit" class="h-10 rounded-full border border-gray-300 bg-white px-5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55">Mark as read</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>

        <?php if ($noteRows === []): ?>
            <p class="py-16 text-center text-gray-500">Nothing yet.</p>
        <?php endif; ?>
    </section>
</div>

<?= $this->endSection() ?>
