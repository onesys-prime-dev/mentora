<?php
/**
 * @var list<array<string, mixed>> $noteRows
 * @var bool                       $noteIsAdmin
 */
$unread = count(array_filter($noteRows, static fn (array $row): bool => $row['read_at'] === null));
?>

<?= $this->extend($noteIsAdmin ? 'layouts/admin' : 'layouts/app') ?>

<?= $this->section('content') ?>

<div class="mx-auto max-w-3xl">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-brand">Notifications</h1>
        <?php if ($unread > 0): ?>
            <form method="post" action="<?= site_url('notifications/read') ?>">
                <?= csrf_field() ?>
                <button type="submit" class="rounded border border-brand px-3 py-1 text-xs font-semibold text-brand hover:bg-brand/10">Mark all as read</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($noteRows === []): ?>
        <p class="mt-6 rounded-lg bg-white p-6 text-center text-gray-500 shadow">Nothing yet.</p>
    <?php endif; ?>

    <ul class="mt-6 flex flex-col gap-3">
        <?php foreach ($noteRows as $row): ?>
            <li class="rounded-lg bg-white p-4 shadow <?= $row['read_at'] === null ? 'border-l-4 border-brand' : '' ?>" data-notification="<?= $row['read_at'] === null ? 'unread' : 'read' ?>">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <h2 class="text-sm font-semibold text-gray-900">
                        <?= esc($row['title']) ?>
                        <?php if ((int) $row['urgent'] === 1): ?><span class="ml-2 rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800">Urgent</span><?php endif; ?>
                    </h2>
                    <span class="text-xs text-gray-500"><?= esc(substr((string) $row['created_at'], 0, 16)) ?></span>
                </div>
                <p class="mt-1 text-sm text-gray-700"><?= esc((string) $row['body']) ?></p>
                <div class="mt-2 flex items-center gap-3 text-xs">
                    <?php if ($row['link'] !== null): ?><a class="font-semibold text-brand hover:underline" href="<?= site_url((string) $row['link']) ?>">Open</a><?php endif; ?>
                    <?php if ($row['read_at'] === null): ?>
                        <form method="post" action="<?= site_url('notifications/read') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= esc((string) $row['id']) ?>">
                            <button type="submit" class="font-semibold text-gray-600 hover:underline">Mark as read</button>
                        </form>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<?= $this->endSection() ?>
