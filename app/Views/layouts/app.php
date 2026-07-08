<!DOCTYPE html>
<html lang="en">

<head>

    <?= $this->include('layouts/partials/head') ?>

</head>

<body 
    class="min-h-screen bg-gray-100"
    data-page="<?= esc($page ?? '') ?>"
>

    <div class="flex min-h-screen">

        <?= $this->include('layouts/partials/sidebar') ?>

        <div class="flex flex-1 flex-col">

            <?= $this->include('layouts/partials/header') ?>

            <main class="flex-1 p-6">

                <?= $this->renderSection('content') ?>

            </main>

            <?= $this->include('layouts/partials/footer') ?>

        </div>

    </div>

    <?= $this->renderSection('scripts') ?>

</body>

</html>