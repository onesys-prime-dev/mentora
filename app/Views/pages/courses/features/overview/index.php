<div class="flex gap-8">

    <div class="flex flex-col gap-6">

        <div class="flex flex-col gap-4 p-6 pb-8 border border-black/10 rounded-2xl">
            
            <div class="flex items-center gap-3">

                <div class="w-5 h-5 flex justify-center items-center text-xs font-semibold font-lao text-blue-900 border border-blue-900 rounded-[100%]">
                    i
                </div>

                <span class="text-lg text-blue-900">
                    Course Description
                </span>

            </div>

            <p class="text-sm leading-relaxed">
                This course introduces students to Python, one of the world's most popular and versatile programming languages. We will cover foundational concepts including data types, control flow, functions, and object-oriented programming. By the end of the semester, students will be able to write robust scripts, analyze data using core libraries, and build simple web applications.
            </p>

        </div>

        <div class="w-full flex gap-6">

            <div class="w-full flex gap-4 p-6 border border-black/10 rounded-2xl">
            
                <div class="w-13 h-13 mt-1">
                    <img
                        class="w-full h-full" 
                        src="<?= base_url('assets/images/placeholder/profile.png') ?>" alt="">
                </div>

                <div class="flex flex-col">
                    <span class="uppercase text-sm font-bold text-gray-400">
                        INSTRUCTOR
                    </span>

                    <span class="text-md text-black">
                        Prof. Jalen Brunson
                    </span>

                    <span class="text-md text-black">
                        jalenb@sanbeda.edu.ph
                    </span>
                </div>

            </div>

            <div class="w-full flex gap-4 p-6 border border-black/10 rounded-2xl">
            
                <div class="w-13 h-13 mt-1 flex justify-center items-center bg-black/6 rounded-[100%]">
                    <img
                        class="w-auto h-6" 
                        src="<?= base_url('assets/images/icons/message-outline.png') ?>" alt="">
                </div>

                <div class="flex flex-col">
                    <span class="uppercase text-sm font-bold text-gray-400">
                        LOCATION
                    </span>

                    <span class="text-md text-black">
                        Room 33A
                    </span>

                    <span class="text-md text-black">
                        Lydia San-Ty Building
                    </span>
                </div>

            </div>

        </div>

        <div class="w-full flex gap-6">

            <div class="w-full flex gap-4 p-6 border border-black/10 rounded-2xl">
            
                <div class="w-13 h-13 mt-1 flex justify-center items-center bg-black/6 rounded-[100%]">
                    <img
                        class="w-auto h-6"
                        src="<?= base_url('assets/images/icons/time.png') ?>" alt="">
                </div>

                <div class="flex flex-col">
                    <span class="uppercase text-sm font-bold text-gray-400">
                        SCHEDULE
                    </span>

                    <span class="text-md text-black">
                        Mon, Wed, Fri
                    </span>

                    <span class="text-md text-black">
                        9:00AM - 10:30AM
                    </span>
                </div>

            </div>

            <div class="w-full flex gap-4 p-6 border border-black/10 rounded-2xl">
            
                <div class="w-13 h-13 mt-1 flex justify-center items-center bg-black/6 rounded-[100%]">
                    <img
                        class="w-auto h-6"
                        src="<?= base_url('assets/images/icons/door.png') ?>" alt="">
                </div>

                <div class="flex flex-col">
                    <span class="uppercase text-sm font-bold text-gray-400">
                        LOCATION
                    </span>

                    <span class="text-md text-black">
                        Room 33A
                    </span>

                    <span class="text-md text-black">
                        Lydia San-Ty Building
                    </span>
                </div>

            </div>

        </div>

    </div>

    <div class="w-350 flex flex-col gap-6">

        <?= $this->include('pages/courses/features/overview/partials/progress') ?>

        <?= $this->include('pages/courses/features/overview/partials/upcoming-deadlines') ?>

        <?= $this->include('pages/courses/features/overview/partials/recent-activity') ?>
        
    </div>

</div>