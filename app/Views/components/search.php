<form
    method="get"
    class="flex items-center gap-2 h-10 w-72 rounded-4xl border border-gray-300 bg-white px-4 shadow">

    <?= icon('search', 'h-4 w-4 opacity-60') ?>

    <input
        type="text"
        name="q"
        value="<?= esc($q ?? '') ?>"
        placeholder="Search"
        class="flex-1 bg-transparent text-sm outline-none placeholder:text-gray-300">

</form>