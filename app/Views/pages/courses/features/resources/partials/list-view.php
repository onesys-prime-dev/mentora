<div class="flex flex-col divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white" data-resources-list>

    <?php foreach ($resources as $resource): ?>
        <?= view('pages/courses/features/resources/partials/list-item', ['resource' => $resource]) ?>
    <?php endforeach; ?>

</div>
