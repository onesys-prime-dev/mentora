<?php
/**
 * @var string $title
 * @var string $message
 * @var string $contact
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title) ?></title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f3f4f6; font-family: system-ui, sans-serif; color: #1f2937; }
        main { max-width: 28rem; margin: 1rem; padding: 2rem; background: #fff; border-radius: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,.08); text-align: center; }
        h1 { margin: 0 0 .75rem; font-size: 1.5rem; color: #0040A6; }
        p { margin: .5rem 0; line-height: 1.5; }
        .contact { font-size: .875rem; color: #6b7280; }
        a { color: #0040A6; }
    </style>
</head>
<body>
    <main data-maintenance>
        <h1>Back soon</h1>
        <p><?= esc($message) ?></p>
        <?php if ($contact !== ''): ?>
            <p class="contact">Need help? <?= filter_var($contact, FILTER_VALIDATE_EMAIL) ? '<a href="mailto:' . esc($contact, 'attr') . '">' . esc($contact) . '</a>' : esc($contact) ?></p>
        <?php endif; ?>
        <p class="contact"><a href="<?= site_url('login') ?>">Administrators: sign in</a></p>
    </main>
</body>
</html>
