<?php
/**
 * The Assessments tab for a parent: what was given, when it is due and where
 * it stands, by type. A status word only: no score, no attempt, nothing to
 * open.
 *
 * @var array<string, list<array{title: string, due: string, status: string, tone: string}>> $groups by assessment type
 */
$tones = ['success' => 'success', 'warning' => 'warning', 'danger' => 'danger', 'info' => 'info'];
?>

<div class="p-10 flex flex-col gap-8">

    <?= view('components/page-header', ['icon' => 'assessments', 'title' => 'Assessments'], ['saveData' => false]) ?>

    <?php if ($groups === []): ?>

        <?= view('components/empty-state', [
            'icon'  => 'assessments',
            'title' => 'No assessments yet',
            'text'  => 'Quizzes and assignments appear here once the instructor publishes them.',
        ], ['saveData' => false]) ?>

    <?php else: ?>

        <?php foreach ($groups as $type => $rows): ?>
            <section class="flex flex-col gap-3" aria-label="<?= esc($type) ?>" data-parent-assessments="<?= esc($type) ?>">
                <h3 class="text-sm font-bold text-brand"><?= esc($type) ?></h3>

                <ul class="flex flex-col divide-y divide-gray-100 rounded-2xl border border-[#A9A9A9]/50 bg-white">
                    <?php foreach ($rows as $row): ?>
                        <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3">
                            <span class="min-w-0 flex-1 font-semibold text-[#1A1C1E]"><?= esc($row['title']) ?></span>
                            <span class="text-sm text-[#646464]"><?= esc($row['due']) ?></span>
                            <?= view('components/status-badge', ['label' => $row['status'], 'tone' => $tones[$row['tone']] ?? 'neutral'], ['saveData' => false]) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>

    <?php endif; ?>

</div>
