<?php
/**
 * The parent's own page: the account, a password link, and the email choice
 * for each child (the app's own notifications are always on).
 *
 * @var string                                                                    $accountName
 * @var string                                                                    $accountEmail
 * @var bool                                                                      $portalOn
 * @var list<array{student_id: int, name: string, institution_id: int, email: bool}> $children empty while the portal is off
 */
$primary = 'rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
$second  = 'rounded-full border border-brand bg-white px-5 py-2 text-sm font-semibold text-brand transition hover:bg-brand-tint focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50';
?>

<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div class="flex flex-col gap-6 py-3">

    <?= view('components/page-head', [
        'title'       => 'Profile',
        'icon'        => 'user',
        'description' => 'Your account and how Mentora tells you things.',
        'backUrl'     => site_url('parent/dashboard'),
        'backLabel'   => 'Back to dashboard',
    ], ['saveData' => false]) ?>

    <section class="flex max-w-2xl flex-col gap-4 rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="parent-account" data-parent-account>
        <h2 id="parent-account" class="text-sm font-bold text-brand">Your account</h2>

        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-[8rem_1fr]">
            <dt class="font-medium text-gray-600">Name</dt>
            <dd class="text-[#1A1C1E]"><?= esc($accountName) ?></dd>

            <dt class="font-medium text-gray-600">Email</dt>
            <dd class="text-[#1A1C1E]">
                <?= esc($accountEmail) ?>
                <span class="block text-xs text-[#646464]">To change your email, contact your school.</span>
            </dd>

            <dt class="font-medium text-gray-600">Password</dt>
            <dd>
                <form method="post" action="<?= esc(site_url('parent/profile/password-link')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="<?= $second ?>">Email me a link to change my password</button>
                </form>
            </dd>
        </dl>
    </section>

    <section class="flex max-w-2xl flex-col gap-4 rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="parent-children" data-parent-children>
        <h2 id="parent-children" class="text-sm font-bold text-brand">Your children</h2>

        <?php if (! $portalOn): ?>

            <p class="text-sm text-[#646464]">The parent portal is switched off right now, so there is nothing to choose. Contact your school.</p>

        <?php elseif ($children === []): ?>

            <p class="text-sm text-[#646464]">You are not linked to a student yet.</p>

        <?php else: ?>

            <form method="post" action="<?= esc(site_url('parent/profile/notifications')) ?>" class="flex flex-col gap-4">
                <?= csrf_field() ?>

                <fieldset class="flex flex-col gap-3">
                    <legend class="sr-only">Email me about</legend>

                    <?php foreach ($children as $child): ?>
                        <label class="flex items-center gap-3 text-sm text-[#1A1C1E]">
                            <input type="checkbox" name="email[]" value="<?= (int) $child['student_id'] ?>" class="h-4 w-4 rounded border-gray-300 text-brand focus:ring-brand/40" <?= $child['email'] ? 'checked' : '' ?>>
                            <span class="font-semibold"><?= esc($child['name']) ?></span>
                            <span class="text-[#646464]">Email me about <?= esc(strtok($child['name'], ' ') ?: 'this child') ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <p class="text-xs text-[#646464]">Notifications in the app are always on.</p>

                <div class="flex justify-end"><button type="submit" class="<?= $primary ?>">Save choices</button></div>
            </form>

        <?php endif; ?>
    </section>

</div>

<?= $this->endSection() ?>
