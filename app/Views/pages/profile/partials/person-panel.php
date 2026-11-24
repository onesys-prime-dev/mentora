<?php
/**
 * Someone else's profile: the modal's body and the /people/{id} page's content (D20, one partial).
 *
 * @var array<string, mixed> $p          ProfileReader::forViewer output
 * @var array<string, mixed> $avatar     AvatarData row
 * @var bool                 $canMessage MessagingPolicy::canStart allows the viewer to start a conversation
 * @var array{has: bool, on: bool}|null $photo for an administrator: the person's photo state
 * @var bool                 $modal      drawn in the profile modal (a dialog cannot open over it)
 */

$name = (string) $p['display_name'];
$last = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
// shortcut: People search matches one name or the email, not "first last"; a per-person People URL would replace this.
$q = (string) ($p['email'] ?? end($last));
?>
<div class="flex flex-col gap-4" data-person-profile="<?= (int) $p['id'] ?>">
    <?= view('pages/profile/partials/profile-body', [
        'p'       => $p,
        'isOwner' => false,
        'avatar'  => $avatar,
        'actions' => ($canMessage ? view('pages/profile/partials/message-button', ['id' => (int) $p['id']], ['saveData' => false]) : '')
            // The address is copied by shared/person-card.js, which reads it from this button.
            . (trim((string) ($p['email'] ?? '')) !== '' ? '<button type="button" data-profile-copy-email="' . esc((string) $p['email'], 'attr') . '" class="inline-flex items-center gap-2 rounded-full border border-brand/30 bg-white px-4 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11">' . 'Copy email</button>' : ''),
    ], ['saveData' => false]) ?>

    <?php if (array_key_exists('admin_actions', $p)): ?>
        <?php // Phase 6 adds Clear field here. ?>
        <section class="flex flex-wrap items-center gap-3 rounded-2xl bg-white p-4 shadow-sm sm:px-6" aria-label="Admin actions" data-profile-admin>
            <span class="rounded-full bg-brand-tint px-2.5 py-0.5 text-xs font-semibold text-brand">Admins only</span>
            <a href="<?= esc(site_url('institution/people') . '?' . http_build_query(['q' => $q]), 'attr') ?>" class="inline-flex items-center gap-2 rounded-full border border-brand/30 bg-white px-4 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11"><?= icon('users', 'h-4 w-4 text-brand') ?>Open in People</a>
            <?php if (($photo['on'] ?? false) === true): ?>
                <?php $adminBtn = 'inline-flex items-center gap-2 rounded-full border border-brand/30 bg-white px-4 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11'; ?>
                <?php if ($modal ?? false): ?>
                    <?php // A dialog cannot open over this modal; the page has the photo controls. ?>
                    <a href="<?= esc(site_url('people/' . (int) $p['id']), 'attr') ?>" class="<?= $adminBtn ?>" data-admin-photo-link><?= icon('pencil', 'h-4 w-4 text-brand') ?>Change photo</a>
                <?php else: ?>
                    <button type="button" data-dialog-open="person-photo-upload" class="<?= $adminBtn ?>"><?= icon('pencil', 'h-4 w-4 text-brand') ?>Upload photo</button>
                    <?php if ($photo['has']): ?>
                        <button type="button" data-dialog-open="person-photo-remove" class="<?= $adminBtn ?>">Remove photo</button>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php if (($photo['on'] ?? false) === true && ! ($modal ?? false)): ?>
            <?= view('pages/profile/partials/dialogs/photo', ['id' => 'person-photo-upload', 'title' => 'Upload a photo for ' . $name, 'action' => site_url('institution/people/' . (int) $p['id'] . '/photo'), 'removeUrl' => '', 'has' => false, 'on' => true], ['saveData' => false]) ?>
            <?php if ($photo['has']): ?>
                <?= view('components/confirm', ['id' => 'person-photo-remove', 'title' => 'Remove ' . $name . "'s photo?", 'text' => 'Their profile shows their initials until they add a photo again. They are told that your school removed it.', 'action' => site_url('institution/people/' . (int) $p['id'] . '/photo/remove'), 'confirmLabel' => 'Remove photo', 'tone' => 'danger', 'ajax' => true], ['saveData' => false]) ?>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
