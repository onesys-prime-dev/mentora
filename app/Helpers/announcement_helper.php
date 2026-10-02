<?php

/*
|--------------------------------------------------------------------------
| Renders a composer message ({version, blocks} JSON — same shape the
| assessment instructions editor produces) to safe HTML for the feed, so
| the page doesn't need a TipTap instance per card just to display text.
|--------------------------------------------------------------------------
*/

if (! function_exists('render_announcement_message')) {

    function render_announcement_message(?string $json): string
    {
        $data = json_decode((string) $json, true);

        $blocks = $data['blocks'] ?? [];

        if (!is_array($blocks) || $blocks === []) {
            return '';
        }

        $html = '';

        foreach ($blocks as $block) {

            $type = $block['type'] ?? 'paragraph';
            $align = $block['align'] ?? 'left';
            $alignClass = $align !== 'left' ? " text-{$align}" : '';

            switch ($type) {

                case 'heading':
                    $level = (int) ($block['level'] ?? 2) === 1 ? 1 : 2;
                    $html .= "<h{$level} class=\"font-bold{$alignClass}\">" . announcement_inline($block['content'] ?? []) . "</h{$level}>";
                    break;

                case 'unordered-list':
                    $html .= '<ul class="list-disc pl-5">';
                    foreach ($block['items'] ?? [] as $item) {
                        $html .= '<li>' . announcement_inline($item) . '</li>';
                    }
                    $html .= '</ul>';
                    break;

                case 'ordered-list':
                    $html .= '<ol class="list-decimal pl-5">';
                    foreach ($block['items'] ?? [] as $item) {
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

    function announcement_inline(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {

            $text = esc((string) ($node['text'] ?? ''));
            $marks = $node['marks'] ?? [];

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
        $data = json_decode((string) $json, true);

        $blocks = is_array($data) ? ($data['blocks'] ?? []) : [];

        $parts = [];

        foreach ($blocks as $block) {

            $nodeLists = isset($block['items']) ? $block['items'] : [$block['content'] ?? []];

            foreach ($nodeLists as $nodes) {
                $parts[] = implode('', array_map(static fn ($n) => (string) ($n['text'] ?? ''), $nodes));
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
