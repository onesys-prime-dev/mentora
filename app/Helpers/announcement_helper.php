<?php

/*
|--------------------------------------------------------------------------
| Renders a composer message ({version, blocks} JSON — same shape the
| assessment instructions editor produces) to safe HTML for the feed, so
| the page doesn't need a TipTap instance per card just to display text.
|--------------------------------------------------------------------------
*/

if (! function_exists('announcement_blocks')) {

    /**
     * The blocks of a stored message, or none when it isn't a readable
     * {version, blocks} document. Anything in the list that isn't a block
     * (a stray string or number) is dropped rather than breaking the page.
     *
     * @return list<array>
     */
    function announcement_blocks(?string $json): array
    {
        $data = json_decode((string) $json, true);

        $blocks = is_array($data) ? ($data['blocks'] ?? []) : [];

        return is_array($blocks) ? array_values(array_filter($blocks, 'is_array')) : [];
    }

}

if (! function_exists('announcement_lists')) {

    /**
     * The items of a list block, each a list of text nodes.
     *
     * @return list<array>
     */
    function announcement_lists(array $block): array
    {
        $items = $block['items'] ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

}

if (! function_exists('render_announcement_message')) {

    function render_announcement_message(?string $json): string
    {
        $html = '';

        foreach (announcement_blocks($json) as $block) {

            $type = $block['type'] ?? 'paragraph';

            // Only the editor's four alignments: the value ends up inside a
            // class attribute, so anything else must never reach it.
            $align = $block['align'] ?? 'left';
            $align = in_array($align, ['left', 'center', 'right', 'justify'], true) ? $align : 'left';
            $alignClass = $align !== 'left' ? " text-{$align}" : '';

            switch ($type) {

                case 'heading':
                    $level = (int) ($block['level'] ?? 2) === 1 ? 1 : 2;
                    $html .= "<h{$level} class=\"font-bold{$alignClass}\">" . announcement_inline($block['content'] ?? []) . "</h{$level}>";
                    break;

                case 'unordered-list':
                    $html .= '<ul class="list-disc pl-5">';
                    foreach (announcement_lists($block) as $item) {
                        $html .= '<li>' . announcement_inline($item) . '</li>';
                    }
                    $html .= '</ul>';
                    break;

                case 'ordered-list':
                    $html .= '<ol class="list-decimal pl-5">';
                    foreach (announcement_lists($block) as $item) {
                        $html .= '<li>' . announcement_inline($item) . '</li>';
                    }
                    $html .= '</ol>';
                    break;

                default:
                    $html .= "<p class=\"{$alignClass}\">" . announcement_inline($block['content'] ?? []) . '</p>';

            }

        }

        return $html;
    }

}

if (! function_exists('announcement_inline')) {

    function announcement_inline(mixed $nodes): string
    {
        $out = '';

        foreach (is_array($nodes) ? $nodes : [] as $node) {

            if (!is_array($node)) {
                continue;
            }

            $text = esc((string) ($node['text'] ?? ''));
            $marks = is_array($node['marks'] ?? null) ? $node['marks'] : [];

            if (in_array('bold', $marks, true)) {
                $text = "<strong>{$text}</strong>";
            }
            if (in_array('italic', $marks, true)) {
                $text = "<em>{$text}</em>";
            }
            if (in_array('underline', $marks, true)) {
                $text = "<u>{$text}</u>";
            }

            $out .= $text;

        }

        return $out === '' ? '&nbsp;' : $out;
    }

}

if (! function_exists('announcement_plain_text')) {

    /** Flattens a composer message ({version, blocks} JSON) to plain text, e.g. for a one-line preview. */
    function announcement_plain_text(?string $json): string
    {
        $parts = [];

        foreach (announcement_blocks($json) as $block) {

            $nodeLists = isset($block['items']) ? announcement_lists($block) : [$block['content'] ?? []];

            foreach ($nodeLists as $nodes) {
                $parts[] = implode('', array_map(
                    static fn ($n) => is_array($n) ? (string) ($n['text'] ?? '') : '',
                    is_array($nodes) ? $nodes : []
                ));
            }

        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
    }

}

if (! function_exists('announcement_message_from_text')) {

    /** Wraps plain text (one paragraph per line) into the composer's {version, blocks} JSON. */
    function announcement_message_from_text(string $text): string
    {
        $blocks = [];

        foreach (preg_split('/\R/', trim($text)) as $line) {
            $blocks[] = ['type' => 'paragraph', 'content' => [['text' => $line, 'marks' => []]]];
        }

        return json_encode(['version' => 1, 'blocks' => $blocks]);
    }

}
