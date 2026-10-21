<?php
/**
 * A slim strip above the dashboard for what the dean has announced to this person's department, sub-department
 * or program: up to three, newest first, each opening the Announcements page. Nothing is printed when there is
 * nothing to say, so a dashboard without any looks exactly as before. (The dean's own dashboard is its own page.)
 */
$deanPosts = (new App\Services\Courses\DepartmentAnnouncementService())->forUser(auth()->user(), 3);

if ($deanPosts === []) {
    return;
}

$area = esc(active_role() ?: (auth()->user()->getGroups()[0] ?? ''));
?>

<section class="shrink-0 flex flex-col gap-1 rounded-2xl border border-brand/20 bg-brand-tint px-5 py-3" aria-label="From your department" data-department-strip>
    <div class="flex items-center justify-between gap-3">
        <span class="text-xs font-bold uppercase tracking-wide text-brand-dark">From your department</span>
        <a href="<?= esc(site_url($area . '/announcements')) ?>" class="text-xs font-semibold text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50">All announcements</a>
    </div>

    <ul class="flex flex-col gap-0.5">
        <?php foreach ($deanPosts as $post): ?>
            <li class="truncate text-sm text-brand-dark">
                <a href="<?= esc(site_url($area . '/announcements')) ?>" class="font-semibold hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50"><?= esc($post['title']) ?></a>
                <span class="text-xs text-[#646464]"> · <?= esc($post['scope_label']) ?> · <?= esc(date('M j', strtotime((string) $post['created_at']))) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
