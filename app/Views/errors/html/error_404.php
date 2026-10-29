<?php
/**
 * "We could not find that page": what a person sees for an address that does not exist or is not theirs.
 * It is one page for both, on purpose, so it never tells anyone which. Self-contained like the maintenance
 * page (its own styles, no layout, no Vite assets), because it is drawn while an error is being handled and
 * must not depend on anything that could itself be the problem. The 404 status itself is the handler's.
 *
 * @var string $message the technical reason, shown to developers outside production only
 */
helper('error_page');

$next   = error_page_next_step();
$detail = error_page_detail((string) ($message ?? ''), ENVIRONMENT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Page not found</title>
    <style>
        :root { --bg: #f3f4f6; --card: #ffffff; --text: #1f2937; --muted: #5b6472; --brand: #0040a6; --brand-dark: #00307d; --tint: #e8eefc; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #111827; --card: #1f2937; --text: #f3f4f6; --muted: #aab2c0; --brand: #7ea6ff; --brand-dark: #a9c4ff; --tint: #1b2a4d; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--bg); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--text); }
        main { width: 100%; max-width: 30rem; margin: 1rem; padding: 2.25rem 2rem; background: var(--card); border-radius: 1rem; box-shadow: 0 10px 25px rgba(0, 0, 0, .08); text-align: center; }
        .code { display: inline-block; margin: 0 0 1rem; padding: .2rem .75rem; border-radius: 999px; background: var(--tint); color: var(--brand); font-size: .8125rem; font-weight: 700; letter-spacing: .04em; }
        h1 { margin: 0 0 .75rem; font-size: 1.5rem; line-height: 1.25; color: var(--brand); }
        p { margin: .5rem 0; line-height: 1.55; }
        .muted { font-size: .875rem; color: var(--muted); }
        .actions { margin: 1.5rem 0 1rem; }
        a.button { display: inline-block; padding: .65rem 1.5rem; border-radius: 999px; background: var(--brand); color: #fff; font-weight: 600; text-decoration: none; }
        @media (prefers-color-scheme: dark) { a.button { color: #0b1220; } }
        a.button:hover { background: var(--brand-dark); }
        a.button:focus-visible { outline: 3px solid var(--brand); outline-offset: 3px; }
        .debug { margin-top: 1.25rem; padding: .6rem .75rem; border-radius: .5rem; background: var(--bg); color: var(--muted); font-size: .75rem; text-align: left; overflow-wrap: anywhere; }
    </style>
</head>
<body>
    <main data-not-found>
        <span class="code">Error 404</span>
        <h1>We couldn't find that page</h1>
        <p>The address may be mistyped, or the page may have moved or been removed. If you followed a link, it may be out of date, or it may lead somewhere you can't open.</p>

        <p class="actions"><a class="button" href="<?= esc($next['url'], 'attr') ?>"><?= esc($next['label']) ?></a></p>

        <p class="muted">If this keeps happening, tell your administrator what you were trying to open.</p>

        <?php if ($detail !== null): ?>
            <p class="debug" data-debug><strong>For developers:</strong> <?= nl2br(esc($detail)) ?></p>
        <?php endif; ?>
    </main>
</body>
</html>
