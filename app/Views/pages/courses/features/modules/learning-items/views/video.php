<?php

$resource = $currentItem['resource'] ?? null;
$file = $resource['file'] ?? null;

?>

<div class="w-full aspect-video overflow-hidden rounded-xl bg-neutral-900">

    <?php if ($file): ?>

        <video
            class="h-full w-full object-cover"
            controls
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
            src="<?= esc($resource['embed_url']) ?>"
            title="<?= esc($currentItem['title']) ?>"
            allowfullscreen
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture">
        </iframe>

    <?php else: ?>

        <div class="flex h-full items-center justify-center text-white">
            Video unavailable.
        </div>

    <?php endif; ?>

</div>