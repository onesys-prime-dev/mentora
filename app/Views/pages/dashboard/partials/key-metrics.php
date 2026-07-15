<!-- key metrics -->
<div class="flex flex-col gap-6">

    <div class="w-30 h-25 flex flex-col justify-between bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl px-4 py-3">
        <span class="text-xs text-gray-600">Courses Enrolled</span>
        <span class="text-3xl font-bold font-display">
            <?= esc($data['courses_enrolled']) ?>
        </span>
    </div>

    <div class="w-30 h-25 flex flex-col justify-between bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl px-4 py-3">
        <span class="text-xs text-gray-600">Pending Tasks</span>
        <span class="text-3xl font-bold font-display">8</span>
    </div>

    <div class="w-30 h-25 flex flex-col justify-between bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl px-4 py-3">
        <span class="text-xs text-gray-600">Current GPA</span>
        <span class="text-3xl font-bold font-display">8</span>
    </div>

</div>