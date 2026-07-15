<!-- upcoming tasks card -->
<div class="flex flex-col bg-white/20 backdrop-blur-xl border border-white/30 shadow-xl rounded-3xl overflow-clip">

    <div class="px-8 py-4 bg-black/10 backdrop-blur-xl">
        <span class="text-xl font-display">Upcoming Tasks</span>
    </div>

    <div class="h-full px-8 py-6 flex">

        <div class="flex flex-col gap-4">

            <?php for ($i = 0; $i < 4; $i++): ?>
                <div class="flex items-center gap-2">
                    <div class="w-12 h-12 flex justify-center items-center bg-blue-800 rounded-4xl">
                        <img 
                            class="h-4 w-auto"
                            src="<?= base_url('assets/images/icons/code.png') ?>" alt="">
                    </div>

                    <div class="flex flex-col text-xs">
                        <div class="flex gap-1">
                            <span class="font-bold">IT101</span>
                            <span>-</span>
                            <span>M1 - Coding Activity</span>
                        </div>

                        <div class="flex gap-1">
                            <span class="font-bold">Due:</span>
                            <span>July 10, 2026 - 11:59PM</span>
                        </div>
                    </div>
                </div>
            <?php endfor; ?>

        </div>

        <div class="h-full w-[1px] bg-gray-500 mx-10"></div>

        <div class="flex flex-col gap-4">

            <?php for ($i = 0; $i < 4; $i++): ?>
                <div class="flex items-center gap-2">
                    <div class="w-12 h-12 flex justify-center items-center bg-blue-800 rounded-4xl">
                        <img 
                            class="h-4 w-auto"
                            src="<?= base_url('assets/images/icons/code.png') ?>" alt="">
                    </div>

                    <div class="flex flex-col text-xs">
                        <div class="flex gap-1">
                            <span class="font-bold">IT101</span>
                            <span>-</span>
                            <span>M1 - Coding Activity</span>
                        </div>

                        <div class="flex gap-1">
                            <span class="font-bold">Due:</span>
                            <span>July 10, 2026 - 11:59PM</span>
                        </div>
                    </div>
                </div>
            <?php endfor; ?>

        </div>

    </div>

</div>