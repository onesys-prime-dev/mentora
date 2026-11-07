<?php

/*
|--------------------------------------------------------------------------
| Publish or draft, and schedule a publish: shared by the Add / Edit
| modals of a module, a learning unit and a learning item
| (resources/js/.../modals/publish-fields.js fills and shows it).
|
| Params: idPrefix (unique per modal, for the labels)
|
| Sends: status (draft|published), schedule_publish (1), scheduled_publish_at.
|--------------------------------------------------------------------------
*/

$idPrefix ??= 'publish';
?>

<div data-publish-fields class="flex flex-col gap-3">

    <div class="flex flex-col gap-2">

        <span class="text-sm font-semibold text-gray-700">Visibility</span>

        <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Visibility">

            <label class="cursor-pointer">
                <input type="radio" name="status" value="draft" data-publish-status class="peer sr-only" checked>
                <span class="block rounded-xl border border-gray-300 px-4 py-2.5 text-center text-sm font-medium text-gray-700 transition peer-checked:border-brand peer-checked:bg-brand peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand/50">
                    Draft
                </span>
            </label>

            <label class="cursor-pointer">
                <input type="radio" name="status" value="published" data-publish-status class="peer sr-only">
                <span class="block rounded-xl border border-gray-300 px-4 py-2.5 text-center text-sm font-medium text-gray-700 transition peer-checked:border-brand peer-checked:bg-brand peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand/50 peer-disabled:cursor-not-allowed peer-disabled:opacity-50">
                    Publish
                </span>
            </label>

        </div>

        <p data-publish-lock role="status" class="hidden text-xs text-[#946200]"></p>

        <span
            data-hidden-badge
            class="hidden w-fit items-center rounded-full bg-[#FFF1D6] px-3 py-1 text-xs font-medium text-[#946200]"></span>

    </div>

    <div data-schedule-block class="flex flex-col gap-3">

        <div class="flex items-center justify-between gap-3">

            <label for="<?= esc($idPrefix) ?>-schedule" class="flex flex-col gap-0.5">
                <span class="text-sm font-medium text-gray-700">Schedule publish</span>
                <span class="text-xs text-[#767676]">It stays a draft until then, and publishes itself.</span>
            </label>

            <label class="relative inline-flex shrink-0 cursor-pointer items-center">

                <input
                    id="<?= esc($idPrefix) ?>-schedule"
                    type="checkbox"
                    name="schedule_publish"
                    value="1"
                    data-schedule-toggle
                    class="peer sr-only">

                <div class="h-6 w-11 rounded-full bg-gray-300 transition-colors peer-checked:bg-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand/50"></div>

                <div class="absolute left-1 top-1 h-4 w-4 rounded-full bg-white transition-transform peer-checked:translate-x-5"></div>

            </label>

        </div>

        <div data-schedule-when class="hidden flex-col gap-1.5">

            <label for="<?= esc($idPrefix) ?>-when" class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                Publish on
            </label>

            <input
                id="<?= esc($idPrefix) ?>-when"
                type="datetime-local"
                name="scheduled_publish_at"
                data-schedule-input
                class="rounded-xl border border-gray-300 px-4 py-2.5 text-sm outline-none transition focus:border-brand">

        </div>

    </div>

</div>
