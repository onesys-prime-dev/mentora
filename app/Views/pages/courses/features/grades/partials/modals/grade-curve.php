<!--
    Grade on a curve. Pick what to curve (one item, or the final grade) and how,
    look at the preview (who goes up, who passes, the new average), then apply.
    Entered scores are never changed; "Remove" puts grades back as they were.
    Script: curve/curve-dialog.js (GradeAdjustmentController).
-->
<div
    id="grade-curve-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4 backdrop-blur-sm">

    <div class="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-2xl bg-white shadow-2xl">

        <div class="flex items-center justify-between border-b border-gray-200 px-8 py-6">
            <h2 class="text-xl font-bold text-brand">Grade on a curve</h2>
            <button type="button" class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100" aria-label="Close" data-close-grade-curve-modal>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex flex-col gap-6 overflow-y-auto p-8">

            <div class="hidden flex-col gap-2" data-curve-live>
                <p class="text-sm font-semibold text-gray-700">Curves in force</p>
                <ul class="flex flex-col divide-y divide-gray-100 rounded-xl border border-gray-200" data-curve-live-list></ul>
            </div>

            <form id="grade-curve-form" class="flex flex-col gap-5" novalidate>

                <div class="grid gap-4 sm:grid-cols-2">

                    <label class="flex flex-col gap-1.5">
                        <span class="text-sm font-semibold text-gray-700">Curve</span>
                        <select data-curve-target class="rounded-xl border border-gray-300 px-3 py-2.5 text-sm outline-none transition focus:border-brand"></select>
                    </label>

                    <label class="flex flex-col gap-1.5">
                        <span class="text-sm font-semibold text-gray-700">How</span>
                        <select data-curve-method class="rounded-xl border border-gray-300 px-3 py-2.5 text-sm outline-none transition focus:border-brand">
                            <option value="add_points">Add points for everyone</option>
                            <option value="scale_to_max">Scale so the top score is full marks</option>
                            <option value="linear">Stretch the class range</option>
                            <option value="set_mean">Set the class average</option>
                        </select>
                    </label>

                </div>

                <div class="grid gap-4 sm:grid-cols-2" data-curve-inputs>

                    <label class="flex flex-col gap-1.5" data-curve-input="add_points">
                        <span class="text-sm font-semibold text-gray-700">Points to add <span class="font-normal text-gray-500">(negative takes off)</span></span>
                        <input type="number" step="0.5" data-curve-field="points" class="rounded-xl border border-gray-300 px-3 py-2.5 text-sm outline-none transition focus:border-brand">
                    </label>

                    <label class="hidden flex-col gap-1.5" data-curve-input="linear">
                        <span class="text-sm font-semibold text-gray-700">Lowest score becomes</span>
                        <input type="number" step="0.5" min="0" data-curve-field="new_low" class="rounded-xl border border-gray-300 px-3 py-2.5 text-sm outline-none transition focus:border-brand">
                    </label>

                    <label class="hidden flex-col gap-1.5" data-curve-input="linear">
                        <span class="text-sm font-semibold text-gray-700">Highest score becomes</span>
                        <input type="number" step="0.5" min="0" data-curve-field="new_high" class="rounded-xl border border-gray-300 px-3 py-2.5 text-sm outline-none transition focus:border-brand">
                    </label>

                    <label class="hidden flex-col gap-1.5" data-curve-input="set_mean">
                        <span class="text-sm font-semibold text-gray-700">Class average becomes</span>
                        <input type="number" step="0.5" min="0" data-curve-field="target_mean" class="rounded-xl border border-gray-300 px-3 py-2.5 text-sm outline-none transition focus:border-brand">
                    </label>

                    <p class="hidden text-sm text-gray-500 sm:col-span-2" data-curve-input="scale_to_max">
                        Everyone's score is multiplied by the same number, worked out so the class's highest score reaches full marks.
                    </p>

                </div>

                <label class="flex flex-col gap-1.5">
                    <span class="text-sm font-semibold text-gray-700">Note <span class="font-normal text-gray-500">(optional, kept in the audit log)</span></span>
                    <input type="text" maxlength="200" data-curve-note placeholder="e.g. The midterm ran long" class="rounded-xl border border-gray-300 px-3 py-2.5 text-sm outline-none transition focus:border-brand">
                </label>

                <div class="flex items-center gap-3">
                    <button type="submit" class="rounded-xl border border-brand px-5 py-2.5 text-sm font-semibold text-brand transition hover:bg-brand/5" data-curve-preview>Preview</button>
                    <span class="text-xs text-gray-500">Nothing changes until you apply it.</span>
                </div>

            </form>

            <div class="hidden flex-col gap-3" data-curve-result aria-live="polite"></div>

            <div data-curve-error class="hidden rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600" role="alert"></div>

        </div>

        <div class="flex justify-end gap-3 border-t border-gray-200 px-8 py-6">
            <button type="button" class="rounded-xl border border-gray-300 px-6 py-3 font-medium transition hover:bg-gray-100" data-close-grade-curve-modal>Close</button>
            <button type="button" class="hidden rounded-xl bg-brand px-6 py-3 font-semibold text-white transition hover:bg-brand-dark disabled:opacity-50" data-curve-apply>Apply curve</button>
        </div>

    </div>

</div>
