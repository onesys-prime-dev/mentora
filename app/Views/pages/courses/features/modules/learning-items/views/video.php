<?php

$resource = $currentItem['resource'] ?? null;
$file = $resource['file'] ?? null;

/*
|--------------------------------------------------------------------------
| Students' watch progress is tracked for uploaded videos and YouTube
| (modules/completion/video-progress.js); "Mark as Completed" unlocks at
| 90%. Other embeds can't report progress, so they aren't tracked.
|--------------------------------------------------------------------------
*/

$trackProgress = $user->inGroup('student');

$progressAttributes = $trackProgress
    ? 'data-video-progress data-learning-item-id="' . esc($currentItem['id']) . '" data-progress="' . (int) ($currentItem['progress_percent'] ?? 0) . '"'
    : '';

?>

<div class="w-full aspect-video overflow-hidden rounded-xl bg-neutral-900">

    <?php if ($file): ?>

        <video
            class="h-full w-full object-cover"
            controls
            preload="metadata"
            <?= $progressAttributes ?>
            <?= ! empty($currentItem['content']['autoplay']) ? 'autoplay' : '' ?>
        >
            <source
                src="<?= esc($file['url']) ?>"
                type="<?= esc($file['mime_type']) ?>"
            >

            Your browser does not support the video tag.
        </video>

    <?php elseif (! empty($resource['embed_url'])): ?>

        <iframe
            class="h-full w-full"
            id="learning-video-<?= esc($currentItem['id']) ?>"
            src="<?= esc($resource['embed_url']) ?>"
            title="<?= esc($currentItem['title']) ?>"
            <?= ! empty($resource['youtube_id']) ? $progressAttributes . ' data-youtube-id="' . esc($resource['youtube_id']) . '"' : '' ?>
            allowfullscreen
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture">
        </iframe>

    <?php else: ?>

        <div class="flex h-full items-center justify-center text-white">
            Video unavailable.
        </div>

    <?php endif; ?>

</div>

<?php if ($trackProgress && ($file || ! empty($resource['youtube_id'])) && empty($currentItem['is_completed'])): ?>

    <div class="flex items-center gap-3 text-xs text-[#646464]">

        <div class="h-1.5 w-40 overflow-hidden rounded-full bg-[#E7E6E6]">
            <div
                data-video-progress-bar
                class="h-full rounded-full bg-brand transition-all duration-500"
                style="width: <?= (int) ($currentItem['progress_percent'] ?? 0) ?>%;"></div>
        </div>

        <span data-video-progress-label>
            <?= (int) ($currentItem['progress_percent'] ?? 0) ?>% watched
        </span>

        <span class="text-[#767676]">· watch 90% to complete · skipping ahead doesn't count</span>

    </div>

<?php endif; ?>
