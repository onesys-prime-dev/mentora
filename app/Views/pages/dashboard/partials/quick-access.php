<?php
/**
 * Labelled destinations. Icon-only tiles force a guess-and-click to learn
 * what each one does and give screen readers nothing, so every tile carries
 * a caption and a real alt text.
 */
$userRole = esc($user->getGroups()[0]);

/*
 * Students navigate to places; instructors act on things. Same tile grid,
 * different verbs.
 */
$links = $userRole === 'instructor'
    ? [
        ['label' => 'Post',      'icon' => 'message.png',  'url' => site_url($userRole . '/')],
        ['label' => 'Assess',    'icon' => 'tasks.png',    'url' => site_url($userRole . '/')],
        ['label' => 'Upload',    'icon' => 'lessons.png',  'url' => site_url($userRole . '/')],
        ['label' => 'Sections',  'icon' => 'hat.png',      'url' => site_url($userRole . '/courses')],
        ['label' => 'Roster',    'icon' => 'door.png',     'url' => site_url($userRole . '/')],
        ['label' => 'Schedule',  'icon' => 'time.png',     'url' => site_url($userRole . '/')],
    ]
    : [
        ['label' => 'Courses',   'icon' => 'hat.png',      'url' => site_url($userRole . '/courses')],
        ['label' => 'Messages',  'icon' => 'message.png',  'url' => site_url($userRole . '/')],
        ['label' => 'Calendar',  'icon' => 'calendar.png', 'url' => site_url($userRole . '/')],
        ['label' => 'Tasks',     'icon' => 'tasks.png',    'url' => site_url($userRole . '/')],
        ['label' => 'Grades',    'icon' => 'lessons.png',  'url' => site_url($userRole . '/')],
        ['label' => 'Schedule',  'icon' => 'time.png',     'url' => site_url($userRole . '/')],
    ];
?>

<!-- quick access -->
<div class="h-full min-h-0 flex flex-col bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl overflow-clip">

    <div class="shrink-0 px-6 py-3 bg-black/10">
        <span class="text-xl font-display">Quick Access</span>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scroll-area px-6 py-4">

        <div class="grid grid-cols-3 gap-4">

            <?php foreach ($links as $link): ?>

                <a
                    class="flex flex-col items-center gap-2 group"
                    href="<?= $link['url'] ?>">

                    <span class="w-full aspect-square flex justify-center items-center bg-white/30 border border-black/5 shadow-md rounded-3xl transition group-hover:bg-white/60">
                        <?= icon($link['icon'], 'w-8 h-8 text-blue-800') ?>
                    </span>

                    <span class="text-[11px] text-gray-700 text-center leading-tight">
                        <?= esc($link['label']) ?>
                    </span>

                </a>

            <?php endforeach; ?>

        </div>

    </div>

</div>
