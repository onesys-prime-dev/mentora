<div
    id="assessment-create-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 backdrop-blur-sm">

    <div class="w-full max-w-2xl rounded-2xl bg-white shadow-2xl">

        <!-- Header -->
        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">

            <div class="flex flex-col">

                <h2
                    class="text-2xl font-bold text-[#0040A6]"
                    data-assessment-create-title>

                    Add Assessment

                </h2>

                <p
                    class="mt-1 text-sm text-gray-500"
                    data-assessment-create-subtitle>

                    Create a new Assessment for the selected module and unit.

                </p>

            </div>

            <button
                type="button"
                class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100"
                data-close-assessment-modal>

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    class="h-6 w-6"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor">

                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M6 18L18 6M6 6l12 12"/>

                </svg>

            </button>

        </div>

        <!-- Body -->
        <form
            id="assessment-create-form"
            class="flex flex-col gap-6 p-8">

            <?= csrf_field() ?>

            <div class="grid grid-cols-2 gap-4">

                <div class="flex flex-col gap-2">

                    <label
                        class="text-sm font-semibold text-gray-700">

                        Module

                    </label>

                    <select
                        name="module_id"
                        data-assessment-module-select
                        class="rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-[#0040A6]"
                        required>

                        <option value="" disabled selected>Select a module</option>

                        <?php foreach (($modules ?? []) as $module): ?>

                            <option value="<?= esc($module['id']) ?>">
                                <?= esc($module['title']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="flex flex-col gap-2">

                    <label
                        class="text-sm font-semibold text-gray-700">

                        Learning Unit

                    </label>

                    <select
                        name="learning_unit_id"
                        data-assessment-learning-unit-select
                        class="rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-[#0040A6]"
                        required
                        disabled>

                        <option value="" disabled selected>Select a module first</option>

                        <?php foreach (($learningUnits ?? []) as $learningUnit): ?>

                            <option
                                value="<?= esc($learningUnit['id']) ?>"
                                data-module-id="<?= esc($learningUnit['module_id']) ?>"
                                hidden>

                                <?= esc($learningUnit['title']) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

            </div>

            <div class="flex flex-col gap-2">

                <label
                    class="text-sm font-semibold text-gray-700">

                    Assessment Title

                </label>

                <input
                    type="text"
                    name="title"
                    autocomplete="off"
                    placeholder="e.g. Introduction to Programming"
                    class="rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-[#0040A6]"
                    required>

            </div>

            <div class="flex flex-col gap-2">

                <label
                    class="text-sm font-semibold text-gray-700">

                    Description

                </label>

                <textarea
                    name="description"
                    autocomplete="off"
                    rows="5"
                    placeholder="Write a short description for this assessment..."
                    class="resize-none rounded-xl border border-gray-300 px-4 py-3 outline-none transition focus:border-[#0040A6]"></textarea>

            </div>

            <div class="flex flex-col gap-2">

                <label
                    class="text-sm font-semibold text-gray-700">

                    Assessment Type

                </label>

                <div class="grid grid-cols-3 gap-3">

                    <?php foreach (($assessmentTypes ?? []) as $index => $assessmentType): ?>

                        <label class="cursor-pointer">
                            <input
                                type="radio"
                                name="assessment_type_id"
                                value="<?= esc($assessmentType['id']) ?>"
                                class="peer hidden"
                                <?= $index === 0 ? 'checked' : '' ?>
                                required>

                            <div class="rounded-xl border border-gray-300 px-4 py-3 text-center transition peer-checked:border-[#0040A6] peer-checked:bg-[#0040A6] peer-checked:text-white">
                                <?= esc($assessmentType['name']) ?>
                            </div>
                        </label>

                    <?php endforeach; ?>

                </div>

            </div>

        </form>

        <!-- Footer -->
        <div class="flex justify-end gap-3 border-t border-gray-200 px-8 py-6">

            <button
                type="button"
                class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100"
                data-close-assessment-modal>

                Cancel

            </button>

            <button
                type="submit"
                form="assessment-create-form"
                data-assessment-create-submit
                class="rounded-xl bg-[#0040A6] px-6 py-3 font-semibold text-white transition hover:bg-[#002068]">

                Create Assessment

            </button>

        </div>

    </div>

</div>