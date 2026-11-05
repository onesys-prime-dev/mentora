<?php
/*
|--------------------------------------------------------------------------
| One row of the bell's notifications panel (design A): a round icon by type, the
| title and line, the time as "1h", and two buttons shown on hover: mark as read
| and delete (shared/notifications-menu.js does both). The row itself is the link.
| Param: row (a notifications table row).
|--------------------------------------------------------------------------
*/

$unread = $row['read_at'] === null;
$tag    = $row['link'] !== null ? 'a' : 'div';

// "now", "5m", "3h", "2d", "3w": the same short form as the chat list.
$minutes = max(0, (int) floor((time() - (int) strtotime((string) $row['created_at'] . ' UTC')) / 60));
$ago     = $minutes < 1 ? 'now' : ($minutes < 60 ? $minutes . 'm' : ($minutes < 1440 ? intdiv($minutes, 60) . 'h' : ($minutes < 10080 ? intdiv($minutes, 1440) . 'd' : intdiv($minutes, 10080) . 'w')));
$isMsg   = $row['type'] === 'message';
$isReact = $isMsg && str_contains((string) $row['title'], 'reaction');
?>
<li class="group/note relative" data-notification-item data-notification-id="<?= (int) $row['id'] ?>" data-type="<?= esc((string) $row['type'], 'attr') ?>" data-notification="<?= $unread ? 'unread' : 'read' ?>">
    <<?= $tag ?>
        <?php if ($row['link'] !== null): ?>href="<?= esc(site_url((string) $row['link']), 'attr') ?>"<?php endif; ?>
        data-role="note-row"
        class="flex gap-3 px-5 py-3 pr-[5rem] transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/55 <?= $unread ? 'bg-brand/5' : '' ?>">

        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full <?= $isReact ? 'bg-[#ffe4ec] text-[#e11d48]' : ($unread ? 'bg-brand text-white' : 'bg-gray-100 text-gray-600') ?>" data-role="note-icon" aria-hidden="true">
            <?php if ($isReact): ?>
                <span class="text-xl leading-none">&#9829;</span>
            <?php elseif ($isMsg): ?>
                <svg viewBox="0 0 20 20" class="h-5 w-5" fill="currentColor"><path d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7z"/></svg>
            <?php else: ?>
                <svg viewBox="0 0 20 20" class="h-5 w-5" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
            <?php endif; ?>
        </span>

        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="text-[15px] <?= $unread ? 'font-bold' : 'font-medium' ?> text-[#1A1C1E]" data-role="note-title">
                <?= esc($row['title']) ?>
                <?php if ((int) $row['urgent'] === 1): ?>
                    <span class="ml-1 rounded-full bg-[#FDEDEC] px-2 py-0.5 text-[10px] font-bold text-[#C33939]">Urgent</span>
                <?php endif; ?>
            </span>
            <span class="line-clamp-2 text-[13px] text-[#646464]"><?= esc((string) $row['body']) ?></span>
            <span class="text-xs font-semibold <?= $unread ? 'text-brand' : 'text-[#767676]' ?>" data-role="note-time"><?= esc($ago) ?></span>
        </span>

        <span class="pointer-events-none absolute right-5 top-5 h-2 w-2 rounded-full bg-brand group-hover/note:invisible <?= $unread ? '' : 'invisible' ?>" data-role="note-dot" aria-hidden="true"></span>
    </<?= $tag ?>>

    <span class="absolute right-3 top-1/2 flex -translate-y-1/2 gap-1 opacity-0 transition group-hover/note:opacity-100 group-focus-within/note:opacity-100 [@media(hover:none)]:opacity-100">
        <button type="button" data-action="read" aria-label="Mark as read" title="Mark as read" <?= $unread ? '' : 'hidden' ?>
            class="grid h-8 w-8 place-items-center rounded-full bg-white text-brand shadow ring-1 ring-black/5 hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55">
            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
        </button>
        <button type="button" data-action="delete" aria-label="Delete" title="Delete"
            class="grid h-8 w-8 place-items-center rounded-full bg-white text-[#b91c1c] shadow ring-1 ring-black/5 hover:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/55">
            <svg viewBox="0 0 20 20" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h12M8 6V4h4v2M6 6l1 10h6l1-10"/></svg>
        </button>
    </span>
</li>
