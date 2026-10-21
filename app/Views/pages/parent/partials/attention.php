<?php
/**
 * "Needs attention" for the child: at-risk courses, work not handed in, final
 * grades just released. Each line carries an icon and words, never colour alone.
 *
 * @var list<array{kind: string, tone: string, text: string, offering_id: int}> $items
 */
$tones = [
    'warning' => ['attention', 'text-[#946200]'],
    'danger'  => ['attention', 'text-[#C33939]'],
    'success' => ['check', 'text-[#2B7549]'],
];
?>

<section class="flex flex-col gap-3 rounded-2xl bg-white p-6 shadow-sm" aria-labelledby="parent-attention" data-parent-attention>
    <h2 id="parent-attention" class="text-sm font-bold text-brand">Needs attention</h2>

    <?php if ($items === []): ?>
        <p class="flex items-center gap-2 text-sm text-[#646464]">
            <?= icon('check', 'h-4 w-4 text-[#2B7549]') ?>
            Nothing needs attention right now.
        </p>
    <?php else: ?>
        <ul class="flex flex-col gap-2">
            <?php foreach ($items as $item): ?>
                <?php [$symbol, $colour] = $tones[$item['tone']] ?? $tones['warning']; ?>
                <li class="flex items-start gap-2 text-sm text-[#1A1C1E]" data-attention="<?= esc($item['kind']) ?>">
                    <?= icon($symbol, 'mt-0.5 h-4 w-4 shrink-0 ' . $colour) ?>
                    <span><?= esc($item['text']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
