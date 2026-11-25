<?php
/**
 * Settings: Recent security activity (D16), the last 90 days from the audit log, newest first.
 *
 * @var list<array{action: string, label: string, at: string, by_admin: bool}> $activity
 */
?>
<h2 class="text-lg font-bold text-brand">Recent activity</h2>
<p class="text-sm text-[#646464]">Security changes to your account in the last 90 days.</p>

<?php if ($activity === []): ?>
    <p class="text-sm text-[#646464]" data-activity-empty>No security changes in the last 90 days.</p>
<?php else: ?>
    <ul class="flex flex-col" data-activity>
        <?php foreach ($activity as $entry): ?>
            <li class="flex items-baseline gap-3 border-t border-gray-100 py-2.5 text-sm text-[#1A1C1E] first:border-t-0">
                <span class="h-2 w-2 shrink-0 rounded-full <?= $entry['by_admin'] ? 'bg-amber-500' : 'bg-brand' ?>" aria-hidden="true"></span>
                <span class="min-w-0"><?= esc($entry['label']) ?></span>
                <time datetime="<?= esc(date('c', strtotime($entry['at']))) ?>" class="ml-auto whitespace-nowrap text-xs text-[#646464]"><?= esc(date(date('Y', strtotime($entry['at'])) === date('Y') ? 'M j, g:i A' : 'M j, Y, g:i A', strtotime($entry['at']))) ?></time>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
