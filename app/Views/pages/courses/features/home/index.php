<div class="space-y-6">

    <div>

        <h2 class="text-2xl font-semibold">
            <?= esc($course['course_title']) ?>
        </h2>

        <p class="text-gray-500">
            <?= esc($course['course_code']) ?>
        </p>

    </div>

    <div class="rounded-lg border bg-white p-6 opacity-80">

        <h3 class="font-semibold mb-2">
            Course Description
        </h3>

        <p>
            <?= esc($course['description']) ?>
        </p>

    </div>

</div>