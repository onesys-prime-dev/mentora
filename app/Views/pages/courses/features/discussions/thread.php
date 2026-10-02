<div class="flex flex-col gap-6 p-10" data-discussions-page data-thread-screen>

    <?= view('components/breadcrumb', ['crumbs' => [
        ['label' => 'Discussions', 'attrs' => 'data-discussions-breadcrumb'],
        ['label' => $thread['title']],
    ]], ['saveData' => false]) ?>

    <?= view('pages/courses/features/discussions/partials/composer', [], ['saveData' => false]) ?>

    <?= view('pages/courses/features/discussions/partials/thread-body', [
        'thread' => $thread,
        'replies' => $replies,
    ], ['saveData' => false]) ?>

</div>
