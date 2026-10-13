<?php
/**
 * Pages opt into a viewport-locked shell by passing 'fullHeight' => true.
 * There the page itself never scrolls — the shell is pinned to the viewport
 * and any overflow is handled inside the individual cards.
 *
 * The lock only engages from `lg` up. Below that there isn't the vertical
 * room to fit a dashboard's worth of cards on one screen, so the page falls
 * back to normal scrolling rather than crushing them. Every other page keeps
 * the grow-and-scroll behaviour at all widths.
 *
 * The background is `bg-fixed`: with `bg-cover` alone, the image scales to
 * cover `body`'s own box, and body's height varies by page — min-h-screen
 * here, h-screen there, taller still wherever content overflows — so the
 * same image visibly zoomed in and out between pages. Fixed attachment
 * anchors it to the viewport instead, which never changes size, so the
 * scale (and the image) stays constant everywhere.
 */
$fullHeight = $fullHeight ?? false;

$bodyHeight  = $fullHeight ? 'min-h-screen lg:h-screen lg:overflow-hidden' : 'min-h-screen';
$shellHeight = $fullHeight ? 'min-h-screen lg:h-full lg:min-h-0' : 'min-h-screen';
// A page can pass its own gutter. The default keeps px-40 from xl up (as before) and
// narrows below it, so pages work on a laptop, a tablet and a phone.
$mainGutter  = $mainGutter ?? 'px-4 sm:px-8 lg:px-16 xl:px-40';
$mainSpacing = $fullHeight ? 'py-10 lg:min-h-0 lg:py-6' : 'py-10';
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <?= $this->include('layouts/partials/head') ?>

</head>

<body 
    class="<?= $bodyHeight ?> bg-gray-100 bg-cover bg-center bg-no-repeat bg-fixed"
    style="background-image: url('<?= base_url('assets/images/background/light.png') ?>');"
    data-page="<?= esc($page ?? '') ?>"
    data-features="<?= esc(json_encode(current_features(), JSON_FORCE_OBJECT), 'attr') ?>"
>

    <div class="flex flex-col <?= $shellHeight ?>">

        <?= $this->include('layouts/partials/header') ?>

        <?= $this->include('layouts/partials/announcement') ?>

        <?= $this->include('layouts/partials/institution-announcement') ?>

        <?= $this->include('layouts/partials/impersonation-banner') ?>

        <?= $this->include('layouts/partials/notifications-strip') ?>

        <?= $this->include('layouts/partials/read-only-banner') ?>

        <?php if ($flashToasts ?? false): ?>
            <?= $this->include('layouts/partials/flash-toasts') ?>
        <?php endif; ?>

        <main class="flex-1 <?= $mainGutter ?> <?= $mainSpacing ?>">

            <?= $this->renderSection('content') ?>

        </main>

    </div>

    <?php if ($reauthDialog ?? false): ?>
        <?= $this->include('layouts/partials/reauth-dialog') ?>
    <?php endif; ?>

    <?= $this->renderSection('scripts') ?>

</body>

</html>
