<?php

/*
|--------------------------------------------------------------------------
| Metric card — the one KPI/summary card for the Course Workspace
|--------------------------------------------------------------------------
| White card, thin border. Neutral metrics carry no colour beyond the brand
| blue value; warning / danger / success add a thin left bar and take the
| tone colour for the value and icon — no background flood. Status never
| relies on colour alone: the icon and the detail line carry it too, and a
| screen-reader-only status word is added for non-neutral tones.
|
| Params:
|   label        string
|   value        string            plain text (escaped here), or…
|   value_html   string            …pre-escaped markup (e.g. a grade pill)
|   detail       string            supporting line
|   detail_attrs string            extra attributes for the detail line (JS hooks)
|   icon         string            registry name from icon_helper
|   tone         neutral|warning|danger|success
|   size         default|prominent (Grades uses prominent)
|   feature      string            course tab to open on click (data-course-feature)
|   filter       string            Assessments list filter to apply on click
|                                  (data-assessments-filter-set)
| With neither feature nor filter the card is a plain, non-interactive div.
|--------------------------------------------------------------------------
*/

$tone = $tone ?? 'neutral';
$size = $size ?? 'default';
$feature = $feature ?? null;
$filter = $filter ?? null;
$detail = $detail ?? '';
$detail_attrs = $detail_attrs ?? '';

$colors = match ($tone) {
    'danger'  => ['accent' => 'bg-[#C33939]', 'ink' => 'text-[#C33939]', 'bubble' => 'bg-[#FDEDEC]', 'sr' => 'Needs attention'],
    'warning' => ['accent' => 'bg-[#946200]', 'ink' => 'text-[#946200]', 'bubble' => 'bg-[#FFF8E8]', 'sr' => 'Warning'],
    'success' => ['accent' => 'bg-[#2E7D4F]', 'ink' => 'text-[#2E7D4F]', 'bubble' => 'bg-[#E8F5EC]', 'sr' => 'On track'],
    default   => ['accent' => null,           'ink' => 'text-[#0040A6]', 'bubble' => 'bg-[#EEF1F8]', 'sr' => null],
};

$prominent = $size === 'prominent';

$interactive = $feature !== null || $filter !== null;

$tag = $interactive ? 'button' : 'div';

$attrs = $interactive ? 'type="button"' : '';

if ($feature !== null) {
    $attrs .= ' data-course-feature="' . esc($feature, 'attr') . '"';
}

if ($filter !== null) {
    $attrs .= ' data-assessments-filter-set="' . esc($filter, 'attr') . '"';
}

$surface = 'relative flex w-full min-w-0 flex-col overflow-hidden rounded-2xl border border-[#A9A9A9]/50 bg-white text-left '
    . ($prominent ? 'gap-2 px-6 py-6' : 'gap-2 px-5 py-5')
    . ($interactive
        ? ' cursor-pointer transition hover:border-[#0040A6]/50 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#0040A6]/50'
        : '');
?>

<<?= $tag ?> class="<?= $surface ?>" <?= $attrs ?>>

    <?php if ($colors['accent'] !== null): ?>
        <span class="absolute inset-y-0 left-0 w-1 <?= $colors['accent'] ?>" aria-hidden="true"></span>
    <?php endif; ?>

    <div class="flex items-start justify-between gap-3">

        <div class="flex min-w-0 flex-col gap-1">

            <span class="text-xs font-bold uppercase tracking-wide text-[#646464]/80">
                <?= esc($label) ?>
            </span>

            <span class="<?= $prominent ? 'text-3xl' : 'text-2xl' ?> font-black <?= $colors['ink'] ?>">
                <?php if ($colors['sr'] !== null): ?><span class="sr-only"><?= esc($colors['sr']) ?>: </span><?php endif; ?>
                <?= isset($value_html) ? $value_html : esc($value ?? '') ?>
            </span>

        </div>

        <?php if ($prominent): ?>
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full <?= $colors['bubble'] ?>">
                <?= icon($icon, 'h-5 w-5 ' . $colors['ink']) ?>
            </span>
        <?php else: ?>
            <?= icon($icon, 'h-6 w-6 shrink-0 ' . $colors['ink'] . ' opacity-70') ?>
        <?php endif; ?>

    </div>

    <?php if ($detail !== ''): ?>
        <p class="text-xs leading-snug text-[#646464] wrap-break-word" <?= $detail_attrs ?>>
            <?= esc($detail) ?>
        </p>
    <?php endif; ?>

</<?= $tag ?>>
