<?php
/**
 * The popover card (D20): who this is in a glance, and Message / See more. Task 3.2's script anchors it.
 *
 * @var array<string, mixed> $p          ProfileReader::forViewer output
 * @var array<string, mixed> $avatar     AvatarData row
 * @var bool                 $canMessage MessagingPolicy::canStart allows the viewer to start a conversation
 */

$id       = (int) $p['id'];
$name     = (string) $p['display_name'];
$legal    = (string) ($p['legal_name'] ?? '');
$top      = $p['roles'][0] ?? null;
$roleName = static fn (string $r): string => $r === 'admin' ? 'System admin' : ucfirst(str_replace('_', ' ', $r));
$gone     = array_key_exists('deactivated_badge', $p);
?>
<div class="flex w-[19rem] max-w-full flex-col gap-3 rounded-2xl bg-white p-4 shadow-lg" data-person-card="<?= $id ?>">
    <div class="flex items-start gap-3">
        <?= view('components/avatar', ['person' => $avatar, 'size' => 'lg'], ['saveData' => false]) ?>
        <div class="flex min-w-0 flex-col gap-0.5">
            <p class="break-words text-sm font-bold text-[#1A1C1E]">
                <?= esc($name) ?>
                <?php if (! empty($p['pronouns'])): ?>
                    <span class="text-xs font-medium text-[#646464]"><?= esc($p['pronouns']) ?></span>
                <?php endif; ?>
            </p>
            <?php if ($legal !== '' && $legal !== $name): ?>
                <p class="break-words text-xs text-[#646464]"><?= esc($legal) ?></p>
            <?php endif; ?>
            <?php if ($top !== null): ?>
                <p class="break-words text-xs font-semibold <?= $gone ? 'text-gray-700' : 'text-brand' ?>"><?= esc($roleName((string) $top['role'])) ?><?= ($top['detail'] ?? '') !== '' && $top['detail'] !== null ? ' · ' . esc($top['detail']) : '' ?></p>
            <?php endif; ?>
            <?php if ($gone): ?>
                <p><span class="inline-flex rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700" data-profile-deactivated>Deactivated</span></p>
            <?php endif; ?>
        </div>
    </div>
    <div class="flex flex-wrap gap-2">
        <?php if ($canMessage): ?>
            <?= view('pages/profile/partials/message-button', ['id' => $id], ['saveData' => false]) ?>
        <?php endif; ?>
        <a href="<?= esc(site_url('people/' . $id), 'attr') ?>" data-person-panel="<?= $id ?>" class="inline-flex items-center rounded-full border border-brand/30 bg-white px-4 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 pointer-coarse:min-h-11">See more</a>
    </div>
</div>
