<?php

$content = $currentItem['content'] ?? [];
$blocks = $content['blocks'] ?? [];

/**
 * Render inline text with formatting.
 */
function renderInline(array $nodes): string
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

?>

<div class="reading-content flex flex-col">

    <?php foreach ($blocks as $block): ?>

        <?php

        $align = match ($block['align'] ?? 'left') {

            'center'  => 'text-center',
            'right'   => 'text-right',
            'justify' => 'text-justify',

            default   => 'text-left',
        };

        ?>

        <?php switch ($block['type']):

            case 'heading': ?>

                <?php if (($block['level'] ?? 2) === 1): ?>

                    <h1 class="<?= $align ?>">

                        <?= renderInline($block['content'] ?? []) ?>

                    </h1>

                <?php else: ?>

                    <h2 class="<?= $align ?>">

                        <?= renderInline($block['content'] ?? []) ?>

                    </h2>

                <?php endif; ?>

                <?php break; ?>


            <?php case 'paragraph': ?>

                <p class="<?= $align ?>">

                    <?= renderInline($block['content'] ?? []) ?>

                </p>

                <?php break; ?>


            <?php case 'unordered-list': ?>

                <ul class="<?= $align ?>">

                    <?php foreach ($block['items'] ?? [] as $item): ?>

                        <li>

                            <?= renderInline($item) ?>

                        </li>

                    <?php endforeach; ?>

                </ul>

                <?php break; ?>


            <?php case 'ordered-list': ?>

                <ol class="<?= $align ?>">

                    <?php foreach ($block['items'] ?? [] as $item): ?>

                        <li>

                            <?= renderInline($item) ?>

                        </li>

                    <?php endforeach; ?>

                </ol>

                <?php break; ?>

        <?php endswitch; ?>

    <?php endforeach; ?>

</div>