<?php
/**
 * The institution's own announcement line, for the people of that institution, under the platform's.
 * Nothing is printed for anyone else (system administrators, other institutions) or when it has ended.
 * People can dismiss it for themselves, like the platform's.
 */
$institutionAnnouncement = (new App\Services\Institutions\InstitutionAnnouncement())->forUser(auth()->user());

if ($institutionAnnouncement === null) {
    return;
}
?>
<div class="flex items-center justify-between gap-3 border-b border-brand/20 bg-brand-tint px-6 py-2 text-sm text-brand-dark" role="status" data-announcement data-institution-announcement>
    <span data-announcement-text><?= esc($institutionAnnouncement) ?></span>
    <button type="button" class="rounded px-2 py-0.5 text-xs font-semibold hover:bg-brand/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50" data-announcement-dismiss aria-label="Hide this announcement">Dismiss</button>
</div>
