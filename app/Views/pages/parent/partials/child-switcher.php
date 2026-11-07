<?php
/**
 * Which child the parent is looking at: a select of the linked children that
 * opens that child's dashboard, or the same page of theirs when $path names one
 * (shared/select-link.js). Only drawn for two or more children.
 *
 * @var list<array{student_id: int, name: string, institution_id: int}> $children
 * @var int                                                              $selected the child shown now
 * @var string|null                                                      $path     the child's page to open, e.g. "grades"; none is the dashboard
 */

$suffix = isset($path) && $path !== '' ? '/' . $path : '';
?>

<div class="flex items-center gap-2">
    <label for="child-switcher" class="text-xs font-medium text-gray-600">Showing</label>
    <select id="child-switcher" data-child-switcher data-select-link class="min-h-10 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-[#1A1C1E] focus:border-brand/60 focus:outline-none focus:ring-2 focus:ring-brand/40">
        <?php foreach ($children as $kid): ?>
            <option value="<?= esc(site_url('parent/children/' . $kid['student_id'] . $suffix)) ?>" <?= (int) $kid['student_id'] === (int) $selected ? 'selected' : '' ?>><?= esc($kid['name']) ?></option>
        <?php endforeach; ?>
    </select>
</div>
