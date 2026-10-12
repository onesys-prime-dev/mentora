<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0">

<title><?= esc($title ?? 'Mentora') ?></title>

<?= vite('resources/js/app.js') ?>

<?php
// An institution's own colours (never for the vendor's area): custom properties on :root,
// which every brand utility reads. Validated hex only, written by BrandColours::css().
$instituteBranding = (new \App\Services\Institutions\InstitutionBranding())->forUser(auth()->user());
?>
<?php if ($instituteBranding !== null && $instituteBranding['css'] !== ''): ?>
    <style data-institution-branding><?= $instituteBranding['css'] ?></style>
<?php endif; ?>

<?= $this->renderSection('head') ?>