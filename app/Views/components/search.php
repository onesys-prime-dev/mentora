<form
    method="get"
    role="search"
    class="flex items-center gap-2 h-10 w-72 rounded-full border border-gray-300 bg-white px-4 shadow focus-within:border-brand/60">

    <?= icon('search', 'h-4 w-4 opacity-60') ?>

    <input
        type="text"
        name="q"
        aria-label="<?= esc($label ?? 'Search') ?>"
        value="<?= esc($q ?? '') ?>"
        placeholder="Search"
        class="flex-1 bg-transparent text-sm outline-none placeholder:text-gray-500">

</form>