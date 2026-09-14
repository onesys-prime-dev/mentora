<div class="w-1/5 flex flex-col gap-8">

    <!-- Your Score -->
    <div class="flex flex-col gap-3">

        <div class="flex items-center gap-3">
            <img
                class="w-5 h-5" 
                src="<?= base_url('assets/images/icons/score.png') ?>" alt="Instructions Icon">

            <span class="font-bold text-lg text-[#0040A6]">
                Your Score
            </span>
        </div>

        <div class="w-full aspect-square p-10 flex flex-col justify-center items-center bg-[#F9F9FC] border-1 border-[#DBDBDB] rounded-2xl gap-2">

            <img src="<?= base_url('assets/images/icons/no-score.png') ?>" alt="">

            <span class="font-bold text-md text-[#939393]">
                No Score Yet
            </span>

            <span class="text-xs text-[#939393] text-center">
                Your score will appear here once the instructor has given the grade.
            </span>

        </div>
        
    </div>

    <!-- Feedbacks -->
    <div class="flex flex-col gap-3">

        <div class="flex items-center gap-3">
            <img
                class="w-5 h-5" 
                src="<?= base_url('assets/images/icons/feedbacks.png') ?>" alt="Instructions Icon">

            <span class="font-bold text-lg text-[#0040A6]">
                Feedbacks
            </span>
        </div>

        <div class="w-full aspect-square p-10 flex flex-col justify-center items-center bg-[#F9F9FC] border-1 border-[#DBDBDB] rounded-2xl gap-2">

            <img src="<?= base_url('assets/images/icons/no-feedback.png') ?>" alt="">

            <span class="font-bold text-md text-[#939393]">
                No Feedback Yet
            </span>

            <span class="text-xs text-[#939393] text-center">
                Feedback will appear here once your assignment is graded.
            </span>

        </div>
        
    </div>

</div>