<?php
$content = $currentItem['content'] ?? [];
$groups = $content['groups'] ?? [];
?>

<div class="flex flex-col">

    <?php foreach ($groups as $group): ?>

        <div class="flex flex-col gap-2 py-3">

            <?php if (! empty($group['heading'])): ?>

                <h2 class="text-xl font-bold text-[#1A1C1E]">
                    <?= esc($group['heading']) ?>
                </h2>

            <?php endif; ?>

            <?php if (! empty($group['text'])): ?>

                <p class="text-sm leading-7 text-justify text-[#565656]">
                    <?= esc($group['text']) ?>
                </p>

            <?php endif; ?>

        </div>

    <?php endforeach; ?>

</div>