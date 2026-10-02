<?php
/*
|--------------------------------------------------------------------------
| Needs Your Attention (instructor)
|--------------------------------------------------------------------------
| Everything waiting on the instructor, in one place. Three lists from
| OverviewService::attention(), each row shaped as
|   title, detail, tone, action [, id]
| and `action` says where a click goes (bound in overview/index.js):
|   grade   → Grade Work for that assessment   edit    → its editor
|   modules → the Modules tab                  none    → not clickable
*/

$lists = [
    ['key' => 'grading', 'label' => 'To grade',       'icon' => 'tasks',     'total' => $attention['grading_total']],
    ['key' => 'behind',  'label' => 'Falling behind', 'icon' => 'attention', 'total' => $attention['behind_total']],
    ['key' => 'drafts',  'label' => 'Unpublished',    'icon' => 'drafts',    'total' => $attention['drafts_total']],
];

$bubble = static fn (string $tone) => match ($tone) {
    'danger' => 'bg-[#FFDAD6]',
    'warning' => 'bg-[#FFF1D6]',
    default => 'bg-[#EEEEEE]',
};

/** Attributes that make a row clickable; empty for a row that goes nowhere. */
$attributes = static fn (array $row) => match ($row['action']) {
    'grade' => 'data-overview-open="' . (int) $row['id'] . '"',
    'edit' => 'data-overview-edit="' . (int) $row['id'] . '"',
    'modules' => 'data-course-feature="modules"',
    default => '',
};

$isEmpty = $attention['grading'] === [] && $attention['behind'] === [] && $attention['drafts'] === [];
?>

<div class="flex flex-col gap-6 p-6 pb-8 border border-[#A9A9A9]/50 rounded-2xl">

    <span class="text-2xl font-bold text-[#0040A6]">
        Needs Your Attention
    </span>

    <?php if ($isEmpty): ?>

        <div class="flex flex-col items-center justify-center gap-1 py-6 text-center">

            <span class="text-sm font-bold text-[#646464]">You're all caught up</span>

            <span class="text-xs text-[#767676]">
                Ungraded work, students falling behind and unpublished content will show up here.
            </span>

        </div>

    <?php else: ?>

        <div class="flex flex-col gap-6">

            <?php foreach ($lists as $list): ?>

                <?php $rows = $attention[$list['key']]; ?>

                <?php if ($rows === []): ?>
                    <?php continue; ?>
                <?php endif; ?>

                <div class="flex flex-col gap-2">

                    <div class="flex items-center justify-between">

                        <span class="uppercase text-xs font-bold tracking-wide text-[#767676]">
                            <?= esc($list['label']) ?>
                        </span>

                        <?php if ($list['total'] > count($rows)): ?>
                            <span class="text-xs text-[#767676]">
                                Showing <?= count($rows) ?> of <?= esc($list['total']) ?>
                            </span>
                        <?php endif; ?>

                    </div>

                    <div class="flex flex-col">

                        <?php foreach ($rows as $row): ?>

                            <?php $clickable = $row['action'] !== 'none'; ?>

                            <div
                                class="group flex items-center gap-3 py-3 px-3 -mx-3 rounded-2xl transition-colors duration-150 <?= $clickable ? 'cursor-pointer hover:bg-[#0040A6]/5' : '' ?>"
                                <?= $attributes($row) ?>>

                                <div class="w-10 h-10 shrink-0 flex justify-center items-center rounded-full <?= $bubble($row['tone']) ?>">
                                    <?= icon($list['icon'], 'h-5 w-5') ?>
                                </div>

                                <div class="flex min-w-0 flex-1 flex-col">

                                    <span class="truncate text-md font-bold text-black/70 <?= $clickable ? 'transition-colors duration-150 group-hover:text-[#0040A6]' : '' ?>">
                                        <?= esc($row['title']) ?>
                                    </span>

                                    <span class="truncate text-sm text-black/50">
                                        <?= esc($row['detail']) ?>
                                    </span>

                                </div>

                                <?php if ($clickable): ?>
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-5 w-5 shrink-0 text-[#A9A9A9] transition-transform duration-200 group-hover:translate-x-1 group-hover:text-[#0040A6]"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                    </svg>
                                <?php endif; ?>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>
