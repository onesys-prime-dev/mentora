<?php
/*
|--------------------------------------------------------------------------
| One row of the bell's notifications panel.
| Param: row (a notifications table row).
|--------------------------------------------------------------------------
*/

use CodeIgniter\I18n\Time;

$unread = $row['read_at'] === null;
$when = Time::parse((string) $row['created_at'])->humanize();
$tag = $row['link'] !== null ? 'a' : 'div';
?>
<li data-notification-item data-notification-id="<?= (int) $row['id'] ?>" data-notification="<?= $unread ? 'unread' : 'read' ?>">
    <<?= $tag ?>
        <?php if ($row['link'] !== null): ?>href="<?= esc(site_url((string) $row['link']), 'attr') ?>"<?php endif; ?>
        class="flex gap-3 px-5 py-3 transition <?= $row['link'] !== null ? 'hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand/55' : '' ?> <?= $unread ? 'bg-brand/5' : '' ?>">

        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full <?= $unread ? 'bg-brand' : 'bg-transparent' ?>" aria-hidden="true"></span>

        <span class="flex min-w-0 flex-col gap-0.5">
            <span class="text-sm <?= $unread ? 'font-bold' : 'font-medium' ?> text-[#1A1C1E]">
                <?= esc($row['title']) ?>
                <?php if ((int) $row['urgent'] === 1): ?>
                    <span class="ml-1 rounded-full bg-[#FDEDEC] px-2 py-0.5 text-[10px] font-bold text-[#C33939]">Urgent</span>
                <?php endif; ?>
            </span>
            <span class="line-clamp-2 text-xs text-[#646464]"><?= esc((string) $row['body']) ?></span>
            <span class="text-[11px] text-[#767676]"><?= esc($when) ?></span>
        </span>

    </<?= $tag ?>>
</li>
