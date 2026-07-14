<!DOCTYPE html>
<html lang="en">

<head>

    <?= $this->include('layouts/partials/head') ?>

</head>

<body 
    class="min-h-screen bg-gray-100 bg-cover bg-center bg-no-repeat"
    style="background-image: url('<?= base_url('assets/images/background/light.png') ?>');"
    data-page="<?= esc($page ?? '') ?>"
>

    <div class="flex flex-col min-h-screen">

        <?= $this->include('layouts/partials/header') ?>

        <main class="flex-1 px-40 py-10">

            <?= $this->renderSection('content') ?>

        </main>

    </div>

    <?= $this->renderSection('scripts') ?>

</body>

</html>