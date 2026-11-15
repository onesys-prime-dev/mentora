<aside class="flex flex-col gap-6 lg:sticky lg:top-[calc(var(--app-header-h,0px)+1.5rem)] lock:top-6" data-announcements-sidebar>

    <?= view('pages/courses/features/announcements/partials/sidebar-tools', [
        'filters' => $filters,
        'activeFilter' => $activeFilter,
    ]) ?>

    <?= view('pages/courses/features/announcements/partials/sidebar-pinned', [
        'pinned' => $pinned,
    ]) ?>

</aside>
