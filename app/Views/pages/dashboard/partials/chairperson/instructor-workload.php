<?php
/** Data: DashboardService::getInstructorWorkloadData() — sections per instructor, heaviest first. */
$instructors = $data['instructors'] ?? [];
$unassigned = (int) ($data['unassigned'] ?? 0);
$max = max(1, ...array_column($instructors, 'sections') ?: [1]);
?>

<!-- instructor workload (chairperson) -->
<div class="flex flex-col gap-3 bg-white rounded-3xl p-5 shadow-md ring-1 ring-black/5 border-t-4 border-t-[#1E8A4C] h-full min-h-0">

    <span id="instructor-workload-heading" class="text-xs font-bold uppercase tracking-wide text-[#4A5468]">Instructor workload</span>

    <?php if ($instructors === []): ?>

        <span class="text-xs text-[#6B7488] py-2">No instructors assigned to your program's sections yet.</span>

    <?php else: ?>

        <?php // From lg up the list scrolls inside the card, so the region itself takes the focus (keyboard scrolling); only the avatars of people the viewer may see are buttons. ?>
        <div class="flex flex-col gap-3 flex-1 min-h-0 overflow-y-auto scroll-area focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" tabindex="0" role="region" aria-labelledby="instructor-workload-heading">

            <?php foreach ($instructors as $instructor): ?>

                <div class="flex flex-col gap-1.5">
                    <div class="flex items-center justify-between gap-3">
                        <span class="flex min-w-0 items-center gap-2">
                            <?php if (($instructor['avatar'] ?? null) !== null): ?>
                                <?= view('components/avatar', $instructor['avatar'] + ['size' => 'xs', 'decorative' => true], ['saveData' => false]) ?>
                            <?php endif; ?>
                            <span class="text-sm text-[#2A3142] truncate" data-workload-name><?= esc($instructor['name']) ?></span>
                        </span>
                        <span class="text-xs font-bold text-[#0A0F1E] shrink-0">
                            <?= (int) $instructor['sections'] ?> <?= $instructor['sections'] === 1 ? 'section' : 'sections' ?>
                        </span>
                    </div>
                    <div class="h-1.5 rounded-full bg-[#F1F3F8] overflow-hidden">
                        <div class="h-full rounded-full bg-[#0040A6]" style="width: <?= round($instructor['sections'] / $max * 100) ?>%"></div>
                    </div>
                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    <?php if ($unassigned > 0): ?>
        <span class="text-xs font-semibold text-[#946200] bg-[#FFF1D6] rounded-full px-2.5 py-1 self-start">
            <?= $unassigned ?> unassigned <?= $unassigned === 1 ? 'section' : 'sections' ?>
        </span>
    <?php endif; ?>

</div>
