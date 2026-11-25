<?php
/**
 * Add, change or remove a profile photo: pick a file, drag and zoom a square crop, Save
 * (resources/js/pages/profile/photo-crop.js). The same dialog serves the owner and an administrator.
 *
 * @var string      $id         the dialog's id
 * @var string      $action     where the cropped photo is posted
 * @var string      $removeUrl  where Remove photo posts
 * @var bool        $has        the person has a photo to remove
 * @var bool        $on         the school shows profile photos
 * @var string      $title      dialog title
 * @var string|null $offNote    what to say when photos are off
 */
$title   ??= 'Profile photo';
$offNote ??= "Your school has turned profile photos off. Your photo is kept and will show again if they turn them back on.";
$limit     = 'Choose a JPEG, PNG or WebP image up to 5 MB.';
$btn       = 'rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50 pointer-coarse:min-h-11';

ob_start();
?>
<?php if (! $on): ?>
    <p class="text-sm text-[#3D3D3D]" data-photo-off><?= esc($offNote) ?></p>
    <div class="flex justify-end"><button type="button" data-dialog-close class="<?= $btn ?>">Close</button></div>
<?php else: ?>
    <form method="post" action="<?= esc($action, 'attr') ?>" enctype="multipart/form-data" data-dialog-form data-photo-crop data-max-bytes="<?= 5 * 1024 * 1024 ?>" data-limit="<?= esc($limit, 'attr') ?>" class="group flex flex-col gap-4">
        <?= csrf_field() ?>
        <div data-field="photo" class="flex flex-col gap-2">
            <label for="<?= esc($id, 'attr') ?>-file" class="text-xs font-medium text-gray-600">Photo</label>
            <input type="file" id="<?= esc($id, 'attr') ?>-file" accept="image/jpeg,image/png,image/webp" data-photo-choose aria-describedby="<?= esc($id, 'attr') ?>-help" class="block w-full text-sm text-[#3D3D3D] file:mr-3 file:rounded-full file:border-0 file:bg-brand-tint file:px-4 file:py-2 file:text-sm file:font-semibold file:text-brand hover:file:bg-brand-tint/70 pointer-coarse:file:min-h-11">
            <p id="<?= esc($id, 'attr') ?>-help" class="text-xs text-[#646464]"><?= esc($limit) ?></p>
            <p class="text-xs font-medium text-[#C33939]" data-field-error hidden></p>

            <div class="flex flex-col items-center gap-3" data-photo-stage hidden>
                <canvas width="256" height="256" tabindex="0" role="img" aria-label="Photo crop. Drag the photo, or use the arrow keys, to choose what shows." data-photo-canvas class="h-64 w-64 max-w-full touch-none cursor-grab rounded-full bg-gray-100 outline-none ring-4 ring-brand-tint focus-visible:ring-brand/50"></canvas>
                <label class="flex w-64 max-w-full items-center gap-3 text-xs font-medium text-gray-600">Zoom
                    <input type="range" min="1" max="4" step="0.05" value="1" data-photo-zoom aria-label="Zoom" class="w-full accent-brand">
                </label>
            </div>
            <input type="file" name="photo" data-photo-export hidden tabindex="-1" aria-hidden="true">
        </div>
        <p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>
        <div class="flex flex-wrap items-center justify-end gap-3">
            <button type="button" data-dialog-close class="<?= $btn ?>">Cancel</button>
            <button type="submit" data-photo-save disabled class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-50 pointer-coarse:min-h-11"><span class="group-aria-busy:hidden">Save</span><span class="hidden group-aria-busy:inline" role="status">Saving…</span></button>
        </div>
    </form>
    <?php if ($has): ?>
        <form method="post" action="<?= esc($removeUrl, 'attr') ?>" data-dialog-form class="flex flex-col gap-2 border-t border-gray-200 pt-4">
            <?= csrf_field() ?>
            <p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>
            <button type="submit" data-photo-remove class="w-fit rounded-full border border-[#C33939]/40 px-5 py-2 text-sm font-semibold text-[#C33939] transition hover:bg-[#C33939]/5 disabled:opacity-50 pointer-coarse:min-h-11">Remove photo</button>
        </form>
    <?php endif; ?>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
?>
<?= view('components/dialog', ['id' => $id, 'title' => $title, 'body' => $body, 'size' => 'md', 'variant' => 'default'], ['saveData' => false]) ?>
