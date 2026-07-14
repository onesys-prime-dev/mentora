<?php 
$userRole = esc($user->getGroups()[0]);
?>

<div class="flex gap-4 items-center mb-5 uppercase">

    <a 
        href="<?= site_url($userRole . '/' . $previousPage) ?>">
        < Back to <?= esc($previousPage) ?>
    </a>

</div>