<?php
/**
 * The profile's Message button: a form, so it works without script; the route starts or reopens the conversation.
 *
 * @var int $id the person to message
 */
?>
<form method="post" action="<?= esc(site_url('people/' . $id . '/message'), 'attr') ?>" class="contents" data-person-message>
    <?= csrf_field() ?>
    <button type="submit" class="inline-flex items-center gap-2 rounded-full bg-brand px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11"><?= icon('message', 'h-4 w-4 text-white') ?>Message</button>
</form>
