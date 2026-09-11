<form
    method="get"
    class="flex items-center gap-2 h-10 w-72 rounded-4xl border border-gray-300 bg-white px-4 shadow">

    <img
        class="h-4 w-auto opacity-60"
        src="<?= base_url('assets/images/icons/search.png') ?>"
        alt="">

    <input
        type="text"
        name="q"
        value="<?= esc($q ?? '') ?>"
        placeholder="Search"
        class="flex-1 bg-transparent text-sm outline-none placeholder:text-gray-300">

</form>