<?php
/**
 * Pages opt into a viewport-locked shell by passing 'fullHeight' => true.
 * There the page itself never scrolls — the shell is pinned to the viewport
 * and any overflow is handled inside the individual cards. The main area
 * itself scrolls if the cards still don't fit.
 *
 * The header is sticky, so it stays on top on every page, locked or not.
 *
 * The lock only engages with the `lock:` variant (at least 1280 wide AND 760
 * tall). Below that there isn't the room to fit a dashboard's worth of cards
 * on one screen, so the page falls back to normal scrolling rather than
 * crushing them. Every other page keeps the grow-and-scroll behaviour at all
 * widths.
 *
 * The background is `bg-fixed`: with `bg-cover` alone, the image scales to
 * cover `body`'s own box, and body's height varies by page — min-h-screen
 * here, h-screen there, taller still wherever content overflows — so the
 * same image visibly zoomed in and out between pages. Fixed attachment
 * anchors it to the viewport instead, which never changes size, so the
 * scale (and the image) stays constant everywhere.
 */
$fullHeight = $fullHeight ?? false;

$hasMenu     = active_role() === 'institutional_admin';

// The header's ☰ button opens the menu's phone and tablet dialog (partials/institution-menu).
$this->setVar('navMenuId', $hasMenu ? 'nav-menu' : null);

// With the institution menu the header and the menu stay put when the shell locks and only the page scrolls.
$locked      = $fullHeight || $hasMenu;
$bodyHeight  = $locked ? 'min-h-dvh lock:h-dvh lock:overflow-hidden' : 'min-h-dvh';
$shellHeight = $locked ? 'min-h-dvh lock:h-full lock:min-h-0' : 'min-h-dvh';
// A page can pass its own gutter. The default is the fluid gutter token, widening to px-40 from xl up;
// beside the menu the page has less room, so it stays on the token.
$mainGutter  = $mainGutter ?? ($hasMenu ? 'px-gutter' : 'px-gutter xl:px-40');
$mainSpacing = $fullHeight ? 'pb-10 pt-4 lock:min-h-0 lock:pb-6 lock:pt-2' : 'pb-10 pt-2';

// The page is rendered first, so the header can tell whether the page already names itself:
// a page with its own <h1> does not need the same words repeated beside the logo.
ob_start();
echo $this->renderSection('content');
$pageContent = (string) ob_get_clean();

$this->setVar('headerTitle', preg_match('/<h1[\s>]/i', $pageContent) !== 1);
?>
<!DOCTYPE html>
<html lang="en" data-themeable>

<head>

    <?= $this->include('layouts/partials/head') ?>

</head>

<body 
    class="<?= $bodyHeight ?> bg-gray-100 bg-cover bg-center bg-no-repeat bg-fixed"
    style="--page-bg-light: url('<?= base_url('assets/images/background/light.png') ?>'); --page-bg-dark: url('<?= base_url('assets/images/background/dark.png') ?>'); background-image: var(--page-bg-light);"
    data-page="<?= esc($page ?? '') ?>"
    data-features="<?= esc(json_encode(current_features(), JSON_FORCE_OBJECT), 'attr') ?>"
>

    <?= $this->include('layouts/partials/page-progress') ?>

    <div class="flex flex-col pl-safe pr-safe <?= $shellHeight ?>">

        <div class="sticky top-0 z-30 shrink-0 pt-safe" data-app-header>

        <?= $this->include('layouts/partials/header') ?>

        <?= $this->include('layouts/partials/announcement') ?>

        <?= $this->include('layouts/partials/institution-announcement') ?>

        <?= $this->include('layouts/partials/impersonation-banner') ?>

                <?= $this->include('layouts/partials/read-only-banner') ?>

        </div>

        <?php if ($flashToasts ?? false): ?>
            <?= $this->include('layouts/partials/flash-toasts') ?>
        <?php endif; ?>

        <div class="flex flex-1 flex-col <?= $locked ? 'lock:min-h-0' : '' ?> <?= $hasMenu ? 'lg:flex-row' : '' ?>">

            <?php if ($hasMenu): ?>
                <?= $this->include('layouts/partials/institution-menu') ?>
            <?php endif; ?>

            <main <?= $hasMenu ? 'data-pinned-panel' : '' ?> class="min-w-0 flex-1 <?= $mainGutter ?> <?= $mainSpacing ?> <?= $locked ? 'lock:overflow-y-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden' : '' ?>">

                <?php // A full-height page's root is h-full, so its wrapper needs a definite height too. Only there: an h-full wrapper drops main's bottom padding once a page overflows it. ?>
                <div class="mx-auto w-full max-w-shell <?= $fullHeight ? 'lock:h-full lock:min-h-0' : '' ?>">

                    <?= $pageContent ?>

                </div>

            </main>

        </div>

    </div>

    <?php if ($reauthDialog ?? false): ?>
        <?= $this->include('layouts/partials/reauth-dialog') ?>
    <?php endif; ?>

    <?= $this->include('layouts/partials/header-metrics') ?>

    <?= $this->include('layouts/partials/person-dialog') ?>

    <?= $this->renderSection('scripts') ?>

</body>

</html>
