<?php

$content = $currentAssessment['instructions'] ?? ['version' => 1, 'blocks' => []];
$blocks = $content['blocks'] ?? [];

if (!function_exists('renderInstructionsInline')) {

    function renderInstructionsInline(array $nodes): string
    {
        $html = '';

        foreach ($nodes as $node) {

            $text = esc($node['text'] ?? '');

            foreach ($node['marks'] ?? [] as $mark) {

                switch ($mark) {

                    case 'bold':
                        $text = "<strong>{$text}</strong>";
                        break;

                    case 'italic':
                        $text = "<em>{$text}</em>";
                        break;

                    case 'underline':
                        $text = "<u>{$text}</u>";
                        break;

                }

            }

            $html .= $text;

        }

        return $html;
    }

}
?>

<?php if (empty($blocks)): ?>

    <p class="text-sm italic text-gray-400">
        <?= esc($instructionsEmptyText ?? 'No instructions added yet. Click Edit to write some.') ?>
    </p>

<?php else: ?>

    <div class="flex flex-col gap-3 text-sm text-[#646464]">

        <?php foreach ($blocks as $block): ?>

            <?php $align = $block['align'] ?? 'left'; ?>

            <?php if (($block['type'] ?? '') === 'heading'): ?>

                <?php $level = (int) ($block['level'] ?? 2); ?>

                <p
                    class="<?= $level === 1 ? 'text-lg' : 'text-base' ?> font-bold text-[#0040A6]"
                    style="text-align: <?= esc($align) ?>">

                    <?= renderInstructionsInline($block['content'] ?? []) ?>

                </p>

            <?php elseif (($block['type'] ?? '') === 'paragraph'): ?>

                <p
                    class="text-justify"
                    style="text-align: <?= esc($align) ?>">

                    <?= renderInstructionsInline($block['content'] ?? []) ?>

                </p>

            <?php elseif (($block['type'] ?? '') === 'unordered-list'): ?>

                <ul class="list-disc space-y-1 pl-5">

                    <?php foreach (($block['items'] ?? []) as $item): ?>
                        <li><?= renderInstructionsInline($item) ?></li>
                    <?php endforeach; ?>

                </ul>

            <?php elseif (($block['type'] ?? '') === 'ordered-list'): ?>

                <ol class="list-decimal space-y-1 pl-5">

                    <?php foreach (($block['items'] ?? []) as $item): ?>
                        <li><?= renderInstructionsInline($item) ?></li>
                    <?php endforeach; ?>

                </ol>

            <?php endif; ?>

        <?php endforeach; ?>

    </div>

<?php endif; ?>
