<!DOCTYPE html>
<html lang="en">

<head>

    <?= $this->include('layouts/partials/head') ?>

</head>

<body 
    class="min-h-screen bg-gray-100"
    data-page="<?= esc($page ?? '') ?>"
>

    <?= $this->renderSection('content') ?>

</body>

</html>