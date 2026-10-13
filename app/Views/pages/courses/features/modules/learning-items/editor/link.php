<?php

$content = is_array($currentItem['content'] ?? null) ? $currentItem['content'] : [];
?>

<div
    data-link-editor
    data-learning-item-id="<?= esc($currentItem['id']) ?>"
    class="flex flex-col gap-5 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">

    <div class="flex flex-col gap-2">

        <label class="text-sm font-semibold text-gray-700" for="link-editor-url">
            Link
        </label>

        <input
            id="link-editor-url"
            type="url"
            data-link-url
            value="<?= esc($content['url'] ?? '') ?>"
            placeholder="https://..."
            autocomplete="off"
            class="rounded-xl border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-brand">

        <p data-link-error class="hidden text-xs font-semibold text-red-500"></p>

    </div>

    <div class="flex flex-col gap-2">

        <label class="text-sm font-semibold text-gray-700" for="link-editor-description">
            Description <span class="font-normal text-[#767676]">(optional)</span>
        </label>

        <textarea
            id="link-editor-description"
            data-link-description
            rows="4"
            placeholder="What will students find at this link?"
            class="resize-y rounded-xl border border-gray-300 px-4 py-3 text-sm outline-none transition focus:border-brand"><?= esc($content['description'] ?? '') ?></textarea>

    </div>

    <span class="text-xs text-[#767676]">
        Students open the link in a new tab. Changes save automatically.
    </span>

</div>
