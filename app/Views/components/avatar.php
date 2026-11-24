<?php

/*
|--------------------------------------------------------------------------
| Avatar — a person's photo, or initials on a colour picked from their id
|--------------------------------------------------------------------------
| Params: person (a row of AvatarData::forMany; only id and name are needed,
| initials and colour are worked out when missing), size (xs|sm|md|lg|xl),
| interactive (bool, default false: a button carrying the hooks the profile
| modal binds to later, instead of a plain element).
| Colours mirror AvatarData::COLOURS and resources/js/shared/avatar.js.
|--------------------------------------------------------------------------
*/

use App\Services\Profiles\AvatarData;

$size        = $size ?? 'md';
$interactive = $interactive ?? false;

$id    = (int) ($person['id'] ?? 0);
$name  = (string) ($person['name'] ?? '');
$words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$initials = $person['initials']
    ?? mb_strtoupper(mb_substr($words[0] ?? '', 0, 1) . (count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
// Only the fixed set is ever written into style="", so a stray value cannot inject CSS.
$colour   = in_array($person['colour'] ?? null, AvatarData::COLOURS, true) ? $person['colour'] : AvatarData::COLOURS[$id % 8];
$photoUrl = $person['photo_url'] ?? null;

$sizeClasses = [
    'xs' => 'h-6 w-6 text-[10px]',
    'sm' => 'h-8 w-8 text-xs',
    'md' => 'h-12 w-12 text-base',
    'lg' => 'h-14 w-14 text-lg',
    'xl' => 'h-18 w-18 text-2xl',
][$size] ?? 'h-12 w-12 text-base';

$classes = $sizeClasses . ' shrink-0 inline-flex items-center justify-center overflow-hidden rounded-full font-bold text-white select-none';
$tag     = $interactive ? 'button' : 'span';
?>
<<?= $tag ?>
    <?= $interactive ? 'type="button" data-avatar-open data-user-id="' . $id . '" aria-label="' . esc($name) . '"' : '' ?>
    class="<?= $classes ?>"
    style="background-color: <?= $colour ?>"
    data-avatar>
    <?php if ($photoUrl !== null && $photoUrl !== ''): ?>
        <img class="h-full w-full object-cover" src="<?= esc($photoUrl, 'attr') ?>" alt="Photo of <?= esc($name) ?>">
    <?php elseif ($interactive): ?>
        <span aria-hidden="true"><?= esc($initials) ?></span>
    <?php else: ?>
        <span role="img" aria-label="<?= esc($name) ?>"><?= esc($initials) ?></span>
    <?php endif; ?>
</<?= $tag ?>>
