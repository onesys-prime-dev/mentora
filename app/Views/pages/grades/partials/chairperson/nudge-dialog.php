<?php
/**
 * The Nudge dialog of the Program tab: a follow-up request to a section's instructor, sent to the existing
 * Follow-ups (chairperson/follow-ups). It is drawn empty: resources/js/pages/grades/nudge.js fills the title,
 * the message, the recipients and the reason from the section chosen in the table, then posts the form in place.
 * It is a [data-dialog], so the live search's swap leaves it where it is.
 *
 * @var string $area the area's first address segment
 */

use App\Services\Courses\FollowUpRules;

$field = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-[#1A1C1E] transition focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40';
$label = 'text-sm font-semibold text-[#1A1C1E]';
?>

<div id="nudge-dialog" data-dialog data-nudge-dialog hidden class="hidden fixed inset-0 z-50 items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="nudge-dialog-title">

    <div class="flex max-h-full w-full flex-col gap-4 overflow-y-auto rounded-t-2xl bg-white p-6 shadow-xl sm:max-w-lg sm:rounded-2xl" tabindex="-1">

        <div class="flex items-start justify-between gap-4">
            <h2 id="nudge-dialog-title" class="text-lg font-bold text-brand">Nudge an instructor</h2>

            <button type="button" data-dialog-close class="rounded p-1 text-[#A9A9A9] transition hover:text-[#646464] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <form action="<?= esc(site_url("{$area}/follow-ups"), 'attr') ?>" method="post" class="flex flex-col gap-4" data-nudge-form novalidate>

            <input type="hidden" name="offering_id" value="">

            <div class="flex flex-col gap-1.5">
                <label for="nudge-recipient" class="<?= $label ?>">To</label>
                <select id="nudge-recipient" name="recipient_id" class="<?= $field ?>" data-nudge-recipient></select>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="nudge-title" class="<?= $label ?>">Subject</label>
                <input id="nudge-title" name="title" type="text" maxlength="200" class="<?= $field ?>" data-nudge-title>
            </div>

            <div class="flex flex-col gap-4 sm:flex-row">
                <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                    <label for="nudge-reason" class="<?= $label ?>">Reason</label>
                    <select id="nudge-reason" name="reason" class="<?= $field ?>" data-nudge-reason>
                        <?php foreach (FollowUpRules::REASONS as $key => $name): ?>
                            <option value="<?= esc($key, 'attr') ?>"><?= esc($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                    <label for="nudge-due" class="<?= $label ?>">Due date <span class="font-normal text-[#646464]">(optional)</span></label>
                    <input id="nudge-due" name="due_date" type="date" class="<?= $field ?>">
                </div>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="nudge-message" class="<?= $label ?>">Message</label>
                <textarea id="nudge-message" name="message" rows="4" maxlength="2000" class="<?= $field ?>" data-nudge-message></textarea>
            </div>

            <p class="rounded-lg bg-[#FDEDEC] px-3 py-2 text-sm text-[#8E2424]" role="alert" data-form-error hidden></p>

            <div class="flex flex-wrap items-center justify-end gap-3">
                <button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50">Cancel</button>
                <button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark disabled:opacity-60" data-nudge-send>Send</button>
            </div>

        </form>

    </div>

</div>
