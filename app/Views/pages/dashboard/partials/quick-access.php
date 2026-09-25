<?php
/**
 * Labelled destinations. Icon-only tiles force a guess-and-click to learn
 * what each one does and give screen readers nothing, so every tile carries
 * a caption and a real alt text.
 *
 * Same global apps as the header's app menu — both come from global_apps()
 * (app/Helpers/navigation_helper.php), so the two can't drift apart.
 */
$userRole = esc($user->getGroups()[0]);

$links = global_apps($userRole);
?>

<!-- quick access -->
<div class="h-full min-h-0 flex flex-col bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl overflow-clip">

    <div class="shrink-0 px-6 py-3 bg-black/10">
        <span class="text-xl font-display">Quick Access</span>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scroll-area px-6 py-4">

        <div class="grid grid-cols-3 gap-4">

            <?php foreach ($links as $link): ?>

                <?= view('components/app-tile', ['app' => $link, 'variant' => 'dashboard', 'active' => false], ['saveData' => false]) ?>

            <?php endforeach; ?>

        </div>

    </div>

</div>
