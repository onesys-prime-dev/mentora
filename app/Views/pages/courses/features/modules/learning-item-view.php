

<div class="flex">

    <?= $this->include('pages/courses/features/modules/partials/learning-item-panel') ?>

    <div class="flex min-w-0 flex-1 flex-col p-10">

        <?= $this->include('pages/courses/features/modules/partials/breadcrumb') ?>

        <div class="flex flex-1">

            <section class="flex min-w-0 flex-1 py-6">

                <div class="flex min-w-0 flex-1 flex-col gap-5">

                    <!-- header -->
                    <?= $this->include('pages/courses/features/modules/partials/learning-item-header') ?>

                    <!-- contents -->
                    <?= $this->include('pages/courses/features/modules/learning-items/views/' . $currentItem['item_type']) ?>

                </div>

                <?php if ($currentItem['item_type'] == 'assignment'): ?>

                    <?= $this->include('pages/courses/features/modules/partials/detail-panel') ?>

                <?php endif; ?>

            </section>

        </div>

        <?= $this->include('pages/courses/features/modules/partials/navigations') ?>

    </div>

</div>