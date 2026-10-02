

<div class="flex">

    <?= $this->include('pages/courses/features/modules/partials/learning-item-panel') ?>

    <div class="flex min-w-0 flex-1 flex-col p-10">

        <?= $this->include('pages/courses/features/modules/partials/breadcrumb') ?>

        <div data-learning-item-main class="flex flex-1 flex-col min-h-0">

            <div class="flex flex-1">

                <section class="flex min-w-0 flex-1 gap-12 py-6">

                    <div class="flex min-w-0 flex-1 flex-col gap-5">

                        <!-- header -->
                        <?= $this->include('pages/courses/features/modules/partials/learning-item-header') ?>

                        <!-- contents -->
                        <?php if (!empty($currentLock)): ?>

                            <?= view('pages/courses/features/modules/learning-items/views/locked', ['lock' => $currentLock, 'lockLinks' => true]) ?>

                        <?php else: ?>

                            <?= $this->include('pages/courses/features/modules/learning-items/views/' . $currentItem['item_type']) ?>

                        <?php endif; ?>

                    </div>

                    <?php if (empty($currentLock) && in_array($currentItem['item_type'], ['assignment', 'quiz'], true)): ?>

                        <?= $this->include('pages/courses/features/modules/partials/detail-panel') ?>

                    <?php endif; ?>

                </section>

            </div>

            <?= $this->include('pages/courses/features/modules/partials/navigations') ?>

        </div>

    </div>

</div>