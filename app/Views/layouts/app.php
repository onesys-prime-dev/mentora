<!DOCTYPE html>
<html lang="en">

<head>

    <?= $this->include('layouts/partials/head') ?>

</head>

<body 
    class="min-h-screen bg-gray-100"
    data-page="<?= esc($page ?? '') ?>"
>

    <div class="flex flex-col min-h-screen">

        <?= $this->include('layouts/partials/header') ?>

        <div class="flex flex-1">

            <?= $this->include('layouts/partials/sidebar') ?>

            <main class="flex-1 p-6">

                <?= $this->renderSection('content') ?>

            </main>

        </div>

    </div>

    <?= $this->renderSection('scripts') ?>

</body>

</html>