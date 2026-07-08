<?php 
$userRole = esc($user->getGroups()[0]);
?>

<header
    class="
        flex
        items-center
        justify-between
        border-b
        bg-white
        px-6
        py-4
    ">

    <h1 class="text-xl font-semibold">
        <?= esc($title ?? '') ?>
    </h1>
    
    <span>
        <?= esc($userRole) ?>
    </span>

</header>