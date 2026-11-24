<?php
/** The Cancel / Save row every profile dialog ends with (Cancel first, primary last). $label names the primary (default Save). */
?>
<p class="text-xs font-medium text-[#C33939]" data-form-error hidden></p>
<div class="flex flex-wrap items-center justify-end gap-3">
    <button type="button" data-dialog-close class="rounded-full border border-gray-300 px-5 py-2 text-sm font-semibold text-[#3D3D3D] transition hover:bg-gray-50 pointer-coarse:min-h-11">Cancel</button>
    <button type="submit" class="rounded-full bg-brand px-5 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-50 pointer-coarse:min-h-11"><?= esc($label ?? 'Save') ?></button>
</div>
