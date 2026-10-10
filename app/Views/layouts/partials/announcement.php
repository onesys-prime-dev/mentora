<?php
/** The platform-wide announcement line, when there is one. People can dismiss it for themselves. */
$announcementText = (string) (new App\Services\Admin\PlatformSettings())->get('announcement');

if ($announcementText === '') {
    return;
}
?>
<div class="flex items-center justify-between gap-3 bg-[#0040A6] px-6 py-2 text-sm text-white" role="status" data-announcement>
    <span data-announcement-text><?= esc($announcementText) ?></span>
    <button type="button" class="rounded px-2 py-0.5 text-xs font-semibold hover:bg-white/20" data-announcement-dismiss aria-label="Hide this announcement">Dismiss</button>
</div>
