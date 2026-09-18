<div class="flex flex-col gap-8">

    <!-- Instructions -->
    <div class="flex flex-col gap-3">

        <div class="flex items-center gap-3">
            <img
                class="w-5 h-5" 
                src="<?= base_url('assets/images/icons/info.png') ?>" alt="Instructions Icon">

            <span class="font-bold text-lg text-[#0040A6]">
                Instructions
            </span>
        </div>

        <div class="flex flex-col gap-2">

            <p class="text-justify text-sm text-[#5B5B5B]">This assessment is designed to evaluate your understanding of the course material. Please read each question carefully and provide your answers in the space provided. Make sure to review your answers before submitting the assessment.</p>
            <p class="text-justify text-sm text-[#5B5B5B]">Create a Python script that calculates factorial using recursion. Your function should be named calculate_factorial and take a single integer argument.</p>

        </div>
        
    </div>

    <!-- Grading Rubriks -->
    <div class="flex flex-col gap-3">

        <div class="flex items-center gap-3">
            <img
                class="w-5 h-5" 
                src="<?= base_url('assets/images/icons/info.png') ?>" alt="Instructions Icon">

            <span class="font-bold text-lg text-[#0040A6]">
                Grading Rubriks
            </span>
        </div>

        <div class="flex gap-3">

            <div class="p-4 flex flex-col gap-2 rounded-xl bg-[#F2F2F2]">
                
                <span class="font-bold text-md text-[#0040A6]">
                    50%
                </span>

                <span class="font-bold text-sm text-[#5B5B5B]">
                    Logic & Correctness
                </span>

                <span class="text-sm text-[#5B5B5B]">
                    Recursion is correctly implemented and produces accurate results.
                </span>

            </div>

            <div class="p-4 flex flex-col gap-2 rounded-xl bg-[#F2F2F2]">
                
                <span class="font-bold text-md text-[#0040A6]">
                    30%
                </span>

                <span class="font-bold text-sm text-[#5B5B5B]">
                    Efficiency
                </span>

                <span class="text-sm text-[#5B5B5B]">
                    Code is optimized and avoids unnecessary operations.
                </span>

            </div>    

            <div class="p-4 flex flex-col gap-2 rounded-xl bg-[#F2F2F2]">
                
                <span class="font-bold text-md text-[#0040A6]">
                    20%
                </span>

                <span class="font-bold text-sm text-[#5B5B5B]">
                    Documentation
                </span>

                <span class="text-sm text-[#5B5B5B]">
                    Clear docstrings, comments, and adherence to PEP 8 standards.
                </span>

            </div>    

        </div>
        
    </div>

    <!-- Your Work -->
    <div class="flex flex-col gap-3">

        <div class="flex items-center gap-3">
            <img
                class="w-5 h-5" 
                src="<?= base_url('assets/images/icons/work.png') ?>" alt="Instructions Icon">

            <span class="font-bold text-lg text-[#0040A6]">
                Your Work
            </span>
        </div>

        <div class="group/work-tabs flex flex-col">

            <div class="flex w-fit border-x border-t border-[#DBDBDB] rounded-t-xl overflow-hidden">

                <label
                    class="cursor-pointer border-r border-[#DBDBDB] px-4 py-2 text-sm font-semibold text-[#8A8A8A] bg-[#F2F2F4] transition-colors
                        hover:text-[#0040A6]
                        group-has-[#work-tab-upload:checked]/work-tabs:bg-white
                        group-has-[#work-tab-upload:checked]/work-tabs:text-[#0040A6]">

                    <input
                        type="radio"
                        name="work-tab"
                        id="work-tab-upload"
                        class="hidden"
                        checked>

                    File Upload
                </label>

                <label
                    class="cursor-pointer px-4 py-2 text-sm font-semibold text-[#8A8A8A] bg-[#F2F2F4] transition-colors
                        hover:text-[#0040A6]
                        group-has-[#work-tab-text:checked]/work-tabs:bg-white
                        group-has-[#work-tab-text:checked]/work-tabs:text-[#0040A6]">

                    <input
                        type="radio"
                        name="work-tab"
                        id="work-tab-text"
                        class="hidden">

                    Text Entry
                </label>

            </div>

            <div
                class="hidden w-full min-h-100 p-10 flex-col justify-center items-center bg-white border border-[#DBDBDB] rounded-b-2xl rounded-r-2xl gap-2
                    group-has-[#work-tab-upload:checked]/work-tabs:flex">

                <img
                    class="w-10 h-10 opacity-40"
                    src="<?= base_url('assets/images/icons/work.png') ?>" alt="">

                <span class="font-semibold text-sm text-[#5B5B5B]">
                    Drag and drop your file here
                </span>

                <span class="text-xs text-[#939393]">
                    or click to browse
                </span>

            </div>

            <div
                class="hidden w-full min-h-100 p-10 flex-col justify-center items-center bg-white border border-[#DBDBDB] rounded-b-2xl rounded-r-2xl gap-2
                    group-has-[#work-tab-text:checked]/work-tabs:flex">

                <textarea
                    class="h-full w-full resize-none border-0 text-sm text-[#5B5B5B] outline-none"
                    placeholder="Type your response here..."></textarea>

            </div>

        </div>
        
    </div>

    <!-- Submission History -->
    <div class="flex flex-col gap-3">

        <div class="flex items-center gap-3">
            <span class="font-bold text-md text-[#0040A6]">
                Submission History
            </span>
        </div>

        <div class="flex gap-2">
  
            <span class="text-sm italic text-[#5B5B5B]">
                No previous drafts uploaded for this assignment.
            </span>

        </div>
        
    </div>

</div>