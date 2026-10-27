<?php
/**
 * The Grades tab for a parent: the standing the student sees (percentage,
 * band, Final or So far, tag) and how each category stands. No item, manual
 * score or assessment is ever passed to this view.
 *
 * @var ?array{percentage: ?float, band: ?string, tag: string, is_final: bool, categories: list<array>, periods: list<array>} $standing null while the grades are not published
 */
helper('grade');

$category = static function (array $c): string {
    return view('pages/parent/course/partials/grade-category', ['category' => $c], ['saveData' => false]);
};
?>

<div class="p-10 flex flex-col gap-8">

    <?= view('components/page-header', ['icon' => 'grades', 'title' => 'Grades'], ['saveData' => false]) ?>

    <?php if ($standing === null): ?>

        <?= view('components/empty-state', [
            'icon'  => 'grades',
            'title' => 'Grades are not published yet',
            'text'  => 'Your child\'s grades for this course appear here once the instructor publishes them.',
        ], ['saveData' => false]) ?>

    <?php else: ?>

        <?php $look = grade_tag_look($standing['tag']); ?>

        <section class="flex flex-wrap items-center justify-between gap-6 rounded-2xl border border-[#A9A9A9]/50 bg-white px-6 py-5" data-parent-grade-summary>

            <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                <?php if ($standing['percentage'] !== null): ?>
                    <span class="font-display text-4xl font-bold text-brand"><?= esc(grade_percent($standing['percentage'])) ?></span>
                    <?php if ($standing['band'] !== null && $standing['band'] !== ''): ?>
                        <span class="text-lg font-semibold text-[#1A1C1E]"><?= esc((string) $standing['band']) ?></span>
                    <?php endif; ?>
                    <span class="text-sm text-[#646464]"><?= $standing['is_final'] ? 'Final' : 'So far' ?></span>
                <?php else: ?>
                    <span class="text-base text-[#646464]">Nothing graded yet</span>
                <?php endif; ?>
            </div>

            <?= view('components/status-badge', ['label' => $standing['tag'], 'tone' => $look['tone'], 'icon' => $look['icon']], ['saveData' => false]) ?>

        </section>

        <?php if ($standing['is_final']): ?>
            <div class="flex justify-end">
                <button type="button" data-report-path="mine" data-report-format="pdf" data-my-grade-report
                    class="inline-flex items-center gap-2 rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-brand transition hover:bg-brand/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">
                    <?= icon('grades', 'h-4 w-4 text-current') ?>
                    Download grade report
                </button>
            </div>
        <?php endif; ?>

        <?php if ($standing['periods'] !== []): ?>

            <div class="flex flex-col gap-5" data-grade-categories data-uses-periods="1">
                <span class="uppercase font-bold text-xs tracking-wide text-[#767676]">How the grade is made up</span>

                <?php foreach ($standing['periods'] as $period): ?>
                    <div class="flex flex-col gap-3">
                        <div class="flex items-center gap-3">
                            <span class="font-bold text-[#646464]"><?= esc($period['name']) ?></span>
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-600"><?= esc(grade_number($period['weight'])) ?>%</span>
                            <span class="ml-auto font-bold text-brand"><?= esc(grade_percent($period['percentage'])) ?></span>
                        </div>
                        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                            <?php foreach ($period['categories'] as $c): ?>
                                <?= $category($c) ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($standing['categories'] !== []): ?>

            <div class="flex flex-col gap-3" data-grade-categories>
                <span class="uppercase font-bold text-xs tracking-wide text-[#767676]">How the grade is made up</span>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <?php foreach ($standing['categories'] as $c): ?>
                        <?= $category($c) ?>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>
