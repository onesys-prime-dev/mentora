<div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4" data-resources-grid>

    <?php foreach ($resources as $resource): ?>
        <?= view('pages/courses/features/resources/partials/grid-item', ['resource' => $resource]) ?>
    <?php endforeach; ?>

</div>
