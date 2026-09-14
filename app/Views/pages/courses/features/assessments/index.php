<?php 
$userRole = esc($user->getGroups()[0]);
?>

<div class="p-10 flex flex-col gap-10">

    <!-- Key metric cards -->
    <div class="flex flex-1 gap-10">

        <div class="p-6 flex w-full items-center gap-5 border-[1px] border-[#A9A9A9]/50 rounded-2xl">

            <div class="h-12 w-12 rounded-[100%] bg-[#EEEEEE] flex justify-center items-center">

                <img 
                    class="h-6 w-6"
                    src="<?= base_url('assets/images/icons/points.png') ?>" alt="">

            </div>

            <div class="flex flex-col gap-1">

                <span class="uppercase font-bold text-sm text-[#878787]">
                    TOTAL POINTS EARNED
                </span>

                <span class="font-bold text-xl text-[#0040A6]">
                    420/500
                </span>

            </div>

        </div>

        <div class="p-6 flex w-full items-center gap-4 border-[1px] border-[#A9A9A9]/50 rounded-2xl">

            <div class="h-12 w-12 rounded-[100%] bg-[#EEEEEE] flex justify-center items-center">

                <img 
                    class="h-6 w-6"
                    src="<?= base_url('assets/images/icons/schedule.png') ?>" alt="">

            </div>

            <div class="flex flex-col gap-1">

                <span class="uppercase font-bold text-sm text-[#878787]">
                    UPCOMING TASKS
                </span>

                <span class="font-bold text-xl text-[#0040A6]">
                    3 Tasks
                </span>

            </div>

        </div>

        <div class="p-6 flex w-full items-center gap-4 border-[1px] border-[#A9A9A9]/50 rounded-2xl">

            <div class="h-12 w-12 rounded-[100%] bg-[#FFDAD6] flex justify-center items-center">

                <img 
                    class="h-6 w-6"
                    src="<?= base_url('assets/images/icons/attention.png') ?>" alt="">

            </div>

            <div class="flex flex-col gap-1">

                <span class="uppercase font-bold text-sm text-[#878787]">
                    NEEDS ATTENTION
                </span>

                <span class="font-bold text-xl text-[#0040A6]">
                    2 Overdue
                </span>

            </div>

        </div>

    </div>

    <!-- Assessment Wrapper -->
    <div class="flex flex-1 gap-10">

        <!-- Assemssment Lists -->
        <div class="flex flex-col gap-5 w-full">

            <!-- Header -->
            <div class="flex justify-between items-center">

                <div class="flex items-center gap-4">

                    <img 
                        class=""
                        src="<?= base_url('assets/images/icons/assessments.png') ?>" alt="">

                    <span class="font-bold text-2xl text-[#0040A6]">
                        Assessments
                    </span>

                </div>

                <div class="flex gap-4">

                    <select
                        name="filter"
                        class="h-10 w-48 appearance-none rounded-full border border-gray-300 bg-white px-4 pr-10 text-sm shadow outline-none focus:border-blue-500">

                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="archived">Archived</option>

                    </select>

                    <?= $this->include('components/search') ?>

                    <button
                        class="inline-flex items-center gap-2 rounded-4xl bg-[#0040A6] px-5 py-2 text-sm font-semibold text-white shadow transition-colors duration-300 hover:bg-[#002068]"
                        data-create-assessment-button>

                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-5 w-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2">

                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M12 5v14M5 12h14" />

                        </svg>

                        <span>Add Assessment</span>

                    </button>

                </div>

            </div>

            <!-- assessment lists -->
            <div class="flex flex-col gap-3">

                <?php foreach (($assessments ?? []) as $assessment): ?>

                    <!-- list -->
                    <div 
                        class="px-8 py-6 flex justify-between items-center rounded-2xl border-[1px] border-[#A9A9A9]/50
                                transition-all duration-200
                                hover:border-[#0040A6]/50
                                hover:bg-[#0040A6]/5
                                hover:shadow-sm
                                cursor-pointer"
                        data-assessment="<?= esc($assessment['id']) ?>">

                        <div class="flex items-center gap-5">

                            <div class="h-10 w-10 flex items-center justify-center border-3 border-[#0040A6] rounded-[100%]">
                                <img
                                    class="h-5 w-5"
                                    src="<?= base_url('assets/images/icons/quiz.png') ?>"
                                    alt="">
                            </div>

                            <div class="flex flex-col">

                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-lg text-[#646464]">
                                        <?= esc($assessment['title']) ?>
                                    </span>

                                    <?php
                                        $statusBadgeClasses = match ($assessment['status']) {
                                            'Published' => 'bg-[#E0E5F2] text-[#002068]',
                                            'Closed' => 'bg-[#FFF1D6] text-[#946200]',
                                            'Archived' => 'bg-gray-100 text-gray-500',
                                            default => 'bg-gray-100 text-gray-600',
                                        };
                                    ?>

                                    <span class="rounded-full px-3 py-1 text-xs font-black <?= $statusBadgeClasses ?>">
                                        <?= esc($assessment['status']) ?>
                                    </span>
                                </div>

                                <span class="text-sm text-[#878787]">
                                    <?= esc($assessment['assessment_type']) ?> | Due: <?= esc(date('F j, Y', strtotime($assessment['due_date']))) ?> | <?= esc($assessment['question_count']) ?> Questions | <?= esc((int) $assessment['total_points']) ?> Points
                                </span>

                            </div>

                        </div>

                        <div class="flex items-center gap-5">

                            <button
                                class=""
                                data-edit-assessment-button
                                data-assessment="<?= esc($assessment['id']) ?>">

                                <svg 
                                    xmlns="http://www.w3.org/2000/svg" 
                                    class="h-6 w-6 transition-transform duration-200 hover:scale-110" 
                                    fill="none" 
                                    viewBox="0 0 24 24" 
                                    stroke="currentColor" 
                                    stroke-width="2">

                                    <path 
                                        stroke-linecap="round" 
                                        stroke-linejoin="round" 
                                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5M16.5 3.5a2.121 2.121 0 113 3L12 14l-4 1 1-4 7.5-7.5z">
                                    </path>

                                </svg>

                            </button>

                        <?php if ($assessment['submission_status'] === 'Submitted'): ?>

                            <div class="flex flex-col justify-center items-center gap-1">

                                <span class="rounded-full bg-[#0040A6]/10 px-3 py-1 text-xs font-bold text-[#0040A6]">
                                    SUBMITTED
                                </span>

                                <span class="text-sm font-semibold text-[#646464]">
                                    <?= esc((int) ($assessment['submission_score'] ?? 0)) ?>/<?= esc((int) $assessment['total_points']) ?>
                                </span>

                            </div>

                        <?php else: ?>

                            <div class="flex flex-col justify-center items-center">
                                <img
                                    class="w-7 h-7 transition-transform duration-200 group-hover:translate-x-1"
                                    src="<?= base_url('assets/images/icons/start.png') ?>"
                                    alt="">
                            </div>

                        <?php endif; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

        <!-- Assessment Right Sidebar -->
        <div class="flex flex-col gap-10 w-2/5 h-full">

            <!-- Drafts -->
            <div class="flex flex-col gap-3">

                <div class="flex flex-col gap-4">

                    <div class="flex items-center gap-3">

                        <img
                            class="h-5 w-5"
                            src="<?= base_url('assets/images/icons/drafts.png') ?>" alt="">

                        <span class="font-bold text-xl text-[#0040A6]">
                            Drafts
                        </span>

                    </div>

                    <div class="flex flex-col gap-2">

                        <!-- list -->
                        <div class="px-6 py-4 flex justify-between items-center rounded-2xl border-[1px] border-[#A9A9A9]/50
                                    transition-all duration-200
                                    hover:border-[#0040A6]/50
                                    hover:bg-[#0040A6]/5
                                    hover:shadow-sm
                                    cursor-pointer">

                            <div class="flex items-center gap-3">

                                <div class="h-8 w-8 flex items-center justify-center border-2 border-[#0040A6] rounded-[100%]">
                                    <img 
                                        class="h-4 w-4"
                                        src="<?= base_url('assets/images/icons/quiz.png') ?>"
                                        alt="">
                                </div>

                                <div class="flex flex-col">

                                    <span class="font-bold text-md text-[#646464]">
                                        Assessment 1
                                    </span>

                                    <span class="text-xs text-[#878787]">
                                        Due: July 20, 2026 | 10 Questions | 100 Points
                                    </span>

                                </div>

                            </div>

                            <div class="flex justify-center items-center">
                                <img 
                                    class="w-6 h-6 transition-transform duration-200 group-hover:translate-x-1"
                                    src="<?= base_url('assets/images/icons/start.png') ?>"
                                    alt="">
                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- Feedbacks -->
            <div class="flex flex-col gap-3">

                <div class="flex flex-col gap-4">

                    <div class="flex items-center gap-3">

                        <img
                            class="h-5 w-5"
                            src="<?= base_url('assets/images/icons/feedbacks.png') ?>" alt="">

                        <span class="font-bold text-xl text-[#0040A6]">
                            Feedbacks
                        </span>

                    </div>

                    <div class="flex flex-col gap-2">

                        <!-- list -->
                        <div class="px-6 py-4 flex flex-col gap-2 rounded-2xl border-[1px] border-t-16 border-[#A9A9A9]/50
                                    transition-all duration-200
                                    hover:border-[#0040A6]/50
                                    hover:bg-[#0040A6]/5
                                    hover:shadow-sm
                                    cursor-pointer">

                            <div class="flex items-center gap-3">

                                <div class="h-8 w-8 flex items-center justify-center border-2 border-[#0040A6] rounded-[100%]">
                                    <img 
                                        class="h-4 w-4"
                                        src="<?= base_url('assets/images/icons/quiz.png') ?>"
                                        alt="">
                                </div>

                                <span class="font-bold text-md text-[#646464]">
                                    Assessment 1
                                </span>

                            </div>

                            <div class="flex py-2">

                                <p class="text-md text-[#878787]">Good job, keep it up!</p>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</div>

<!-- modals -->
<?= $this->include('pages/courses/features/assessments/partials/modals/assessment-create') ?>
<?= $this->include('pages/courses/features/assessments/partials/modals/assessment-editor') ?>
<?= $this->include('pages/courses/features/assessments/partials/modals/assessment-delete') ?>