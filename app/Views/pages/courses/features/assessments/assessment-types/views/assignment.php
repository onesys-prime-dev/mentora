<div class="flex flex-col gap-8">

    <!-- Instructions -->
    <div class="flex flex-col gap-3">

        <div class="flex items-center gap-3">
            <?= icon('info', 'h-5 w-5') ?>

            <span class="font-bold text-lg text-brand">
                Instructions
            </span>
        </div>

        <?= $this->setData(['instructionsEmptyText' => 'No instructions were provided for this assignment.'])
            ->include('pages/courses/features/assessments/partials/instructions-paragraph') ?>

    </div>

    <?php if (!empty($currentRubric['criteria'])): ?>

        <!-- Grading Rubrics -->
        <div class="flex flex-col gap-3">

            <div class="flex items-center gap-3">
                <?= icon('info', 'h-5 w-5') ?>

                <span class="font-bold text-lg text-brand">
                    Grading Rubrics
                </span>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">

                <?php foreach ($currentRubric['criteria'] as $criterion): ?>

                    <div class="p-4 flex flex-col gap-2 rounded-xl bg-[#F2F2F2]">

                        <span class="font-bold text-md text-brand">
                            <?= esc((int) $criterion['max_points']) ?> pts
                        </span>

                        <span class="font-bold text-sm text-[#646464]">
                            <?= esc($criterion['criteria_title']) ?>
                        </span>

                        <span class="text-sm text-[#646464]">
                            <?= esc($criterion['description']) ?>
                        </span>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

    <?php endif; ?>

    <?= $this->include('pages/courses/features/assessments/partials/assignment-work') ?>

</div>
