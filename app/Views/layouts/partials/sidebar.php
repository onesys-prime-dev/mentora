<?php 
$userRole = esc($user->getGroups()[0]);
?>

<aside class="w-30 bg-white border-r flex flex-col justify-center align-middle">

    <nav class="flex flex-col gap-2 text-center">
        <?php if ($userRole == 'student'): ?>

            <a href="<?= site_url($userRole . '/dashboard') ?>">Dashboard</a>
            <a href="<?= site_url($userRole . '/courses') ?>">My Courses</a>

        <?php elseif ($userRole == 'instructor'): ?>

        <?php endif; ?>
    </nav>

</aside>