<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0">

<title><?= esc($title ?? 'Mentora') ?></title>

<?php // The person's chosen appearance, applied before the page paints so there is no flash (shared/theme.js). ?>
<script>
    (function () {
        try {
            var root = document.documentElement;

            if (root.hasAttribute('data-themeable') && localStorage.getItem('mentora-theme') === 'dark') {
                root.setAttribute('data-theme', 'dark');
            }
        } catch (e) { /* storage blocked: light */ }
    })();
</script>

<?php // The session's CSRF token, which shared/csrf.js adds to every change a script makes. ?>
<meta name="csrf-token" content="<?= esc(csrf_hash(), 'attr') ?>">

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