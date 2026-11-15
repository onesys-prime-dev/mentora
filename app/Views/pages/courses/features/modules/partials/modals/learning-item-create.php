<?php

/*
|--------------------------------------------------------------------------
| Add a learning item to a learning unit (modals/learning-items.js). The dialog
| component's auto rule makes it a full-screen form below sm (its form holds a
| visible field); its footer is the sticky bar there.
|--------------------------------------------------------------------------
*/

ob_start(); ?>

<p class="text-sm text-[#646464]" data-learning-item-create-subtitle>
    Create a new learning item for this learning unit.
</p>

<form
    id="learning-item-create-form"
    class="flex flex-col gap-6">

    <?= csrf_field() ?>

    <input
        type="hidden"
        name="module_id">

    <input
        type="hidden"
        name="learning_unit_id">

    <div class="flex flex-col gap-2">

        <label
            for="learning-item-create-title-input"
            class="text-sm font-semibold text-gray-700">

            Learning item Title

        </label>

        <input
            id="learning-item-create-title-input"
            type="text"
            name="title"
            autocomplete="off"
            placeholder="e.g. Introduction to Programming"
            class="w-full min-w-0 rounded-xl border border-gray-300 px-4 py-3 text-base outline-none transition focus:border-brand sm:text-sm"
            required>

    </div>

    <fieldset class="flex flex-col gap-2">

        <legend class="mb-2 text-sm font-semibold text-gray-700">
            Type
        </legend>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">

            <label class="cursor-pointer">
                <input
                    type="radio"
                    name="item_type"
                    value="reading"
                    class="peer sr-only"
                    checked>

                <div class="flex min-h-11 items-center justify-center rounded-xl border border-gray-300 px-4 py-3 text-center transition peer-checked:border-brand peer-checked:bg-brand peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand/50">
                    Reading
                </div>
            </label>

            <label class="cursor-pointer">
                <input
                    type="radio"
                    name="item_type"
                    value="video"
                    class="peer sr-only">

                <div class="flex min-h-11 items-center justify-center rounded-xl border border-gray-300 px-4 py-3 text-center transition peer-checked:border-brand peer-checked:bg-brand peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand/50">
                    Video
                </div>
            </label>

            <label class="cursor-pointer">
                <input
                    type="radio"
                    name="item_type"
                    value="pdf"
                    class="peer sr-only">

                <div class="flex min-h-11 items-center justify-center rounded-xl border border-gray-300 px-4 py-3 text-center transition peer-checked:border-brand peer-checked:bg-brand peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand/50">
                    PDF
                </div>
            </label>

            <label class="cursor-pointer">
                <input
                    type="radio"
                    name="item_type"
                    value="link"
                    class="peer sr-only">

                <div class="flex min-h-11 items-center justify-center rounded-xl border border-gray-300 px-4 py-3 text-center transition peer-checked:border-brand peer-checked:bg-brand peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand/50">
                    Link
                </div>
            </label>

            <label class="cursor-pointer">
                <input
                    type="radio"
                    name="item_type"
                    value="assignment"
                    class="peer sr-only">

                <div class="flex min-h-11 items-center justify-center rounded-xl border border-gray-300 px-4 py-3 text-center transition peer-checked:border-brand peer-checked:bg-brand peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand/50">
                    Assignment
                </div>
            </label>

            <label class="cursor-pointer">
                <input
                    type="radio"
                    name="item_type"
                    value="quiz"
                    class="peer sr-only">

                <div class="flex min-h-11 items-center justify-center rounded-xl border border-gray-300 px-4 py-3 text-center transition peer-checked:border-brand peer-checked:bg-brand peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand/50">
                    Quiz
                </div>
            </label>

            <label class="cursor-pointer">
                <input
                    type="radio"
                    name="item_type"
                    value="discussion"
                    class="peer sr-only">

                <div class="flex min-h-11 items-center justify-center rounded-xl border border-gray-300 px-4 py-3 text-center transition peer-checked:border-brand peer-checked:bg-brand peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand/50">
                    Discussion
                </div>
            </label>

        </div>

    </fieldset>

    <?= view('pages/courses/features/modules/partials/modals/publish-fields', ['idPrefix' => 'item'], ['saveData' => false]) ?>

</form>

<?php $body = ob_get_clean();

ob_start(); ?>

<button
    type="button"
    class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100"
    data-dialog-close
    data-close-learning-item-modal>

    Cancel

</button>

<button
    type="submit"
    form="learning-item-create-form"
    data-learning-item-create-submit
    class="rounded-xl bg-brand px-6 py-3 font-semibold text-white transition hover:bg-brand-dark max-sm:min-h-11 max-sm:w-full">

    Create Learning item

</button>

<?php $footer = ob_get_clean(); ?>

<?= view('components/dialog', [
    'id'         => 'learning-item-create-modal',
    'title'      => 'Add Learning item',
    'titleAttrs' => 'data-learning-item-create-title',
    'size'       => 'lg',
    'body'       => $body,
    'footer'     => $footer,
], ['saveData' => false]) ?>
