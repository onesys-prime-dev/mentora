<?php
/**
 * Grading scheme editor (instructor). Data: GradingSchemeService::describe().
 * Fields autosave (resources/js/pages/courses/features/grades/scheme/scheme.js);
 * anything that changes how grades are computed flags the scheme for an
 * explicit Recalculate.
 */
$isPublished = $scheme['status'] === 'Published';
$weightOk = abs($total_weight - 100) < 0.001;

$typeBadge = static fn (array $item) => match (true) {
    $item['is_manual'] => ['Manual', 'bg-gray-100 text-gray-600'],
    strtolower((string) $item['assessment_type']) === 'quiz' => ['Quiz', 'bg-[#0040A6]/10 text-[#0040A6]'],
    default => ['Assignment', 'bg-emerald-50 text-emerald-700'],
};

$points = static fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

$categoryOptions = static function (?int $selected) use ($categories): string {
    $html = '<option value="">Uncategorized (doesn\'t count)</option>';
    foreach ($categories as $category) {
        $html .= '<option value="' . (int) $category['id'] . '"' . ((int) $category['id'] === $selected ? ' selected' : '') . '>'
            . esc($category['name']) . '</option>';
    }
    return $html;
};

$renderItem = static function (array $item) use ($typeBadge, $points, $categoryOptions): string {
    [$label, $badgeClass] = $typeBadge($item);
    ob_start(); ?>
    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 px-4 py-3" data-grade-item="<?= (int) $item['id'] ?>">

        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold <?= $badgeClass ?>"><?= $label ?></span>

        <?php if ($item['is_manual']): ?>
            <input type="text" value="<?= esc($item['name'], 'attr') ?>" data-grade-item-name
                class="min-w-0 flex-1 rounded-lg border border-transparent px-2 py-1 text-sm font-semibold text-[#646464] outline-none transition hover:border-gray-200 focus:border-[#0040A6]">
            <label class="flex items-center gap-1 text-xs text-gray-500">
                <input type="number" min="0.01" step="0.5" value="<?= $points($item['max_points']) ?>" data-grade-item-max
                    class="w-20 rounded-lg border border-gray-300 px-2 py-1 text-sm outline-none focus:border-[#0040A6]">
                pts
            </label>
        <?php else: ?>
            <span class="min-w-0 flex-1 truncate text-sm font-semibold text-[#646464]"><?= esc($item['display_name']) ?></span>
            <span class="text-xs text-gray-500"><?= $points($item['max_points']) ?> pts</span>
            <?php if ($item['is_out_of_sync']): ?>
                <span class="inline-flex items-center gap-2 rounded-full bg-[#FFF1D6] px-3 py-1 text-[11px] font-semibold text-[#946200]" data-out-of-sync>
                    Out of sync — now <?= $points($item['current_points']) ?> pts
                    <button type="button" data-resync-grade-item class="underline">Resync</button>
                </span>
            <?php endif; ?>
        <?php endif; ?>

        <select data-grade-item-category class="rounded-lg border border-gray-300 px-2 py-1 text-xs outline-none focus:border-[#0040A6]" title="Category">
            <?= $categoryOptions($item['category_id'] !== null ? (int) $item['category_id'] : null) ?>
        </select>

        <button type="button" data-remove-grade-item title="<?= $item['is_manual'] ? 'Remove item' : 'Don\'t count this in the grade' ?>"
            class="rounded-lg p-1.5 text-gray-400 transition hover:bg-red-50 hover:text-red-500">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>

    </div>
    <?php return (string) ob_get_clean();
};
?>

<div class="p-10 flex flex-col gap-8" data-grading-scheme-editor data-scheme-status="<?= esc($scheme['status'], 'attr') ?>">

    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 pb-4">

        <div class="flex items-center gap-3">

            <button type="button" data-grading-scheme-back class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100" title="Back to Grades">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
            </button>

            <?= icon('settings', 'h-6 w-6') ?>

            <span class="text-xl font-bold text-[#0040A6]">Grading Scheme</span>

            <span class="rounded-full px-3 py-1 text-xs font-bold <?= $isPublished ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' ?>" data-scheme-status-chip>
                <?= $isPublished ? 'Published' : 'Draft' ?>
            </span>

        </div>

        <div class="flex items-center gap-4">

            <span class="flex items-center gap-2 text-sm text-gray-500" data-scheme-save-status>
                <span class="h-2 w-2 rounded-full bg-emerald-500" data-scheme-save-dot></span>
                <span data-scheme-save-message>All changes saved</span>
            </span>

            <button type="button" data-scheme-toggle-publish
                data-target-status="<?= $isPublished ? 'Draft' : 'Published' ?>"
                class="rounded-xl px-5 py-2.5 text-sm font-semibold transition <?= $isPublished
                    ? 'border border-gray-300 text-gray-600 hover:bg-gray-100'
                    : 'bg-[#0040A6] text-white hover:bg-[#002068]' ?>">
                <?= $isPublished ? 'Unpublish' : 'Publish' ?>
            </button>

        </div>

    </div>

    <div data-scheme-error class="hidden rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600"></div>

    <?php if (!empty($scheme['needs_recalculation'])): ?>
        <div class="flex items-center justify-between gap-4 rounded-2xl border border-[#FFE3A3] bg-[#FFF8E6] px-5 py-3 text-sm text-[#946200]">
            <span><strong>Grades may be out of date.</strong> Changes here don't affect anyone's grade until you recalculate.</span>
            <button type="button" data-recalculate-grades <?= $weightOk ? '' : 'disabled' ?>
                class="shrink-0 rounded-lg bg-[#946200] px-4 py-2 text-xs font-semibold text-white transition hover:bg-[#7a5100] disabled:cursor-not-allowed disabled:opacity-50">
                Recalculate
            </button>
        </div>
    <?php endif; ?>

    <!-- Settings -->
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">

        <label class="flex flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Scheme name</span>
            <input type="text" value="<?= esc($scheme['name'], 'attr') ?>" data-scheme-name
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-[#0040A6]">
        </label>

        <label class="flex flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Passing mark (%)</span>
            <input type="number" min="0" max="100" step="0.5" value="<?= $points($scheme['passing_percentage']) ?>" data-scheme-passing
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-[#0040A6]">
        </label>

        <label class="flex flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Grade scale</span>
            <select data-scheme-scale class="rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none transition focus:border-[#0040A6]">
                <option value="">None (percentage only)</option>
                <?php foreach ($scales as $scale): ?>
                    <option value="<?= (int) $scale['id'] ?>" <?= (int) $scale['id'] === (int) ($scheme['grade_scale_id'] ?? 0) ? 'selected' : '' ?>><?= esc($scale['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

    </div>

    <?php if ($scale_items !== []): ?>
        <div class="flex flex-wrap gap-2 text-[11px] text-gray-500">
            <?php foreach ($scale_items as $band): ?>
                <span class="rounded-full bg-gray-100 px-2 py-0.5">
                    <strong><?= esc($band['name']) ?></strong> <?= $points($band['minimum_percentage']) ?>–<?= $points($band['maximum_percentage']) ?>%
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Categories -->
    <div class="flex flex-col gap-4">

        <div class="flex items-center justify-between">
            <span class="text-lg font-bold text-[#0040A6]">Categories</span>
            <span class="rounded-full px-3 py-1 text-xs font-bold <?= $weightOk ? 'bg-emerald-100 text-emerald-700' : 'bg-[#FFDAD6] text-[#B3261E]' ?>" data-scheme-weight-total>
                Total: <?= esc($points($total_weight)) ?>%<?= $weightOk ? '' : ' — must be 100%' ?>
            </span>
        </div>

        <?php foreach ($categories as $category): ?>

            <div class="flex flex-col gap-3 rounded-2xl border border-[#A9A9A9]/50 p-5" data-grade-category="<?= (int) $category['id'] ?>">

                <div class="flex flex-wrap items-center gap-3">

                    <input type="text" value="<?= esc($category['name'], 'attr') ?>" data-grade-category-name
                        class="min-w-0 flex-1 rounded-lg border border-transparent px-2 py-1 font-bold text-[#646464] outline-none transition hover:border-gray-200 focus:border-[#0040A6]">

                    <label class="flex items-center gap-1 text-sm text-gray-500">
                        Weight
                        <input type="number" min="0" max="100" step="0.5" value="<?= $points($category['weight_percentage']) ?>" data-grade-category-weight
                            class="w-20 rounded-lg border border-gray-300 px-2 py-1 text-sm outline-none focus:border-[#0040A6]">
                        %
                    </label>

                    <button type="button" data-delete-grade-category title="Remove category"
                        class="rounded-lg p-1.5 text-gray-400 transition hover:bg-red-50 hover:text-red-500">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                    </button>

                </div>

                <div class="flex flex-col gap-2">
                    <?php if ($category['items'] === []): ?>
                        <span class="text-xs text-gray-400">No items yet — published quizzes/assignments land here automatically, or add a manual item.</span>
                    <?php endif; ?>
                    <?php foreach ($category['items'] as $item): ?>
                        <?= $renderItem($item) ?>
                    <?php endforeach; ?>
                </div>

                <button type="button" data-add-grade-item data-category-id="<?= (int) $category['id'] ?>"
                    class="self-start text-xs font-semibold text-[#0040A6] transition hover:underline">
                    + Add manual item (Attendance, Participation…)
                </button>

            </div>

        <?php endforeach; ?>

        <button type="button" data-add-grade-category
            class="self-start rounded-xl border border-dashed border-gray-300 px-4 py-2 text-sm font-semibold text-[#0040A6] transition hover:bg-[#0040A6]/5">
            + Add category
        </button>

    </div>

    <?php if ($uncategorized !== []): ?>
        <div class="flex flex-col gap-3 rounded-2xl border border-dashed border-gray-300 p-5" data-uncategorized-items>
            <span class="font-bold text-[#646464]">Uncategorized</span>
            <span class="text-xs text-gray-400">These don't count toward the grade until you put them in a category.</span>
            <?php foreach ($uncategorized as $item): ?>
                <?= $renderItem($item) ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<?= view('pages/courses/features/grades/partials/modals/grade-item-create', [], ['saveData' => false]) ?>
