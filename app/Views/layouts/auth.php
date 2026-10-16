<!DOCTYPE html>
<html lang="en">

<head>

    <?= $this->include('layouts/partials/head') ?>

</head>

<body
    class="min-h-screen bg-gray-100 bg-cover bg-center bg-no-repeat bg-fixed"
    style="background-image: url('<?= base_url('assets/images/background/light.png') ?>');"
    data-page="<?= esc($this->renderSection('page') ?: ($page ?? '')) ?>"
>

    <?= $this->include('layouts/partials/page-progress') ?>

    <?= $this->renderSection('content') ?>

</body>

</html>