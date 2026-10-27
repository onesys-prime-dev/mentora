<?php

/*
|--------------------------------------------------------------------------
| Inline SVG icons
|--------------------------------------------------------------------------
|
| Replaces the PNGs that used to live in public/assets/images/icons. Icons
| are drawn with currentColor, so a Tailwind text-* class colors them.
| Each icon has a default color matching its old PNG; it's applied only
| when the caller's class doesn't set a text-* color of its own.
|
|   <?= icon('info', 'h-5 w-5') ?>                  // default color
|   <?= icon('check', 'h-3 w-3 text-white') ?>      // overridden
|   <?= learning_item_icon($item['item_type']) ?>   // reading, video, …
|
*/

if (! function_exists('icon_registry')) {

    /**
     * @return array<string, array{body: string, color: string, fill?: bool, viewBox?: string}>
     */
    function icon_registry(): array
    {
        static $icons = null;

        if ($icons !== null) {
            return $icons;
        }

        $blue = 'text-brand';
        $gray = 'text-[#8A8A8A]';
        $dark = 'text-[#2F2F2F]';

        $document = 'M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z';
        $clipboard = 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2';
        $circle = 'M21 12a9 9 0 11-18 0 9 9 0 0118 0z';
        $clock = 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z';
        $bubbles = 'M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.994 1.994 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z';
        $calendar = 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z';
        $warning = 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z';
        $question = 'M9.75 10a2.25 2.25 0 114.1 1.28c-.47.66-1.35 1.02-1.85 1.62-.2.25-.25.55-.25.85M11.75 16.5h.01';
        $stack = 'M8 7h11a2 2 0 012 2v10a2 2 0 01-2 2H8a2 2 0 01-2-2V9a2 2 0 012-2zM4 16.5V5a2 2 0 012-2h11.5';
        $stackQuestion = 'M11.75 12a1.75 1.75 0 113.2 1c-.37.5-1.05.78-1.45 1.25-.15.2-.2.43-.2.66M13.3 17.5h.01';

        $path = static fn (string ...$d) => implode('', array_map(static fn ($p) => "<path d=\"{$p}\"/>", $d));

        return $icons = [

            /* navigation & chrome */
            'apps' => ['color' => $blue, 'fill' => true, 'body' => implode('', array_map(
                static fn ($c) => "<circle cx=\"{$c[0]}\" cy=\"{$c[1]}\" r=\"2.25\"/>",
                [[5, 5], [12, 5], [19, 5], [5, 12], [12, 12], [19, 12], [5, 19], [12, 19], [19, 19]]
            ))],
            'bell' => ['color' => $blue, 'fill' => true, 'viewBox' => '0 0 20 20', 'body' => $path('M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z')],
            'night' => ['color' => $blue, 'fill' => true, 'viewBox' => '0 0 20 20', 'body' => $path('M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z')],
            'hat' => ['color' => $dark, 'fill' => true, 'viewBox' => '0 0 20 20', 'body' => $path('M10.394 2.08a1 1 0 00-.788 0l-7 3a1 1 0 000 1.84L5.25 8.051a.999.999 0 01.356-.257l4-1.714a1 1 0 11.788 1.838L7.667 9.088l1.94.831a1 1 0 00.787 0l7-3a1 1 0 000-1.838l-7-3zM3.31 9.397L5 10.12v4.102a8.969 8.969 0 00-1.05-.174 1 1 0 01-.89-.89 11.115 11.115 0 01.25-3.762zM9.3 16.573A9.026 9.026 0 007 14.935v-3.957l1.818.78a3 3 0 002.364 0l5.508-2.361a11.026 11.026 0 01.25 3.762 1 1 0 01-.89.89 8.968 8.968 0 00-5.35 2.524 1 1 0 01-1.4 0zM6 18a1 1 0 001-1v-2.065a8.935 8.935 0 00-2-.712V17a1 1 0 001 1z')],
            'message' => ['color' => $dark, 'fill' => true, 'viewBox' => '0 0 20 20', 'body' => '<path fill-rule="evenodd" clip-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z"/>'],
            'calendar' => ['color' => $dark, 'fill' => true, 'viewBox' => '0 0 20 20', 'body' => '<path fill-rule="evenodd" clip-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z"/>'],
            'search' => ['color' => $gray, 'body' => $path('M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z')],
            'code' => ['color' => 'text-white', 'body' => $path('M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4')],
            'door' => ['color' => $blue, 'body' => $path('M4 21h16M6 21V4a1 1 0 011-1h10a1 1 0 011 1v17M14.5 12h.01')],
            'home' => ['color' => $blue, 'body' => $path('M3 10.5L12 3l9 7.5M5 9.5V20a1 1 0 001 1h4v-6h4v6h4a1 1 0 001-1V9.5')],

            /* global apps (solid 20×20, same family as hat / calendar / message) */
            'clock' => ['color' => $dark, 'fill' => true, 'viewBox' => '0 0 20 20', 'body' => '<path fill-rule="evenodd" clip-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z"/>'],
            'grades' => ['color' => $dark, 'fill' => true, 'viewBox' => '0 0 20 20', 'body' => $path('M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z')],
            'announcement' => ['color' => $dark, 'fill' => true, 'viewBox' => '0 0 20 20', 'body' => '<path fill-rule="evenodd" clip-rule="evenodd" d="M18 3a1 1 0 00-1.447-.894L8.763 6H5a3 3 0 000 6h.28l1.771 5.316A1 1 0 008 18h1a1 1 0 001-1v-4.382l6.553 3.276A1 1 0 0018 15V3z"/>'],
            'inbox' => ['color' => $dark, 'fill' => true, 'viewBox' => '0 0 20 20', 'body' => '<path fill-rule="evenodd" clip-rule="evenodd" d="M5 3a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V5a2 2 0 00-2-2H5zm0 2h10v7h-2l-1 2H8l-1-2H5V5z"/>'],

            /* account menu */
            'user' => ['color' => $dark, 'body' => $path('M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z')],
            'settings' => ['color' => $dark, 'body' => $path(
                'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
                'M15 12a3 3 0 11-6 0 3 3 0 016 0z'
            )],
            'help' => ['color' => $dark, 'body' => $path('M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z')],
            'logout' => ['color' => 'text-red-600', 'body' => $path('M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1')],

            /* course structure */
            'modules' => ['color' => $blue, 'body' => $path('M4 6h16M4 12h16M4 18h16')],
            'learning-unit' => ['color' => 'text-[#1E7A8C]', 'body' => $path('M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z')],
            'resources' => ['color' => $blue, 'body' => $path('M9 13h6m-6 4h6m-7 4h8a2 2 0 002-2V7.414a1 1 0 00-.293-.707l-4.414-4.414A1 1 0 0012.586 2H7a2 2 0 00-2 2v14a2 2 0 002 2z')],
            'pin' => ['color' => $blue, 'fill' => true, 'body' => $path('M14 2a1 1 0 011 1v6.17l3.293 3.293a1 1 0 01-.707 1.707H13v6a1 1 0 11-2 0v-6H6.414a1 1 0 01-.707-1.707L9 9.17V3a1 1 0 011-1h4z')],
            'folder' => ['color' => $blue, 'body' => $path('M3 7a2 2 0 012-2h4.586a1 1 0 01.707.293L12.414 7H19a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z')],
            'lessons' => ['color' => 'text-white', 'body' => $path('M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253')],
            'tasks' => ['color' => 'text-white', 'body' => $path($clipboard, 'M9 14l2 2 4-4')],

            /* learning item types */
            'reading' => ['color' => $gray, 'body' => $path('M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253')],
            'video' => ['color' => $gray, 'body' => $path('M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z')],
            'pdf' => ['color' => $gray, 'body' => $path($document, 'M9 13h6M9 17h6M13 3v5a1 1 0 001 1h5')],
            'link' => ['color' => $gray, 'body' => $path('M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1')],
            'assignment' => ['color' => $gray, 'body' => $path($clipboard, 'M10 16.5v-1.75l4.25-4.25 1.75 1.75-4.25 4.25H10z')],
            'file' => ['color' => $gray, 'body' => $path($document)],

            /* resource file types (Resources repository) */
            'word' => ['color' => $gray, 'body' => $path($document, 'M8 13l1.5 5 1.5-4 1.5 4L14 13')],
            'excel' => ['color' => $gray, 'body' => $path($document, 'M9 13l3 4m0-4l-3 4M15 13l-3 4m0-4l3 4')],
            'powerpoint' => ['color' => $gray, 'body' => $path($document, 'M9 17V13h2a1.5 1.5 0 010 3H9z')],
            'image' => ['color' => $gray, 'body' => $path($document, 'M9 16l1.5-2 1.5 1.5L14.5 13 17 16')],

            /* assessments */
            'quiz' => ['color' => $blue, 'body' => $path('M5 3h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2z', $question)],
            'assessment' => ['color' => $gray, 'body' => $path($stack, $stackQuestion)],
            'drafts' => ['color' => $blue, 'body' => $path($stack, $stackQuestion)],
            'assessments' => ['color' => $blue, 'body' => $path($clipboard, 'M12 12h3m-3 4h3m-6-4h.01M9 16h.01')],
            'work' => ['color' => $blue, 'body' => $path('M13 3H7a2 2 0 00-2 2v14a2 2 0 002 2h5M13 3l6 6M13 3v4a2 2 0 002 2h4m0 0v3M15 18h6m0 0l-3-3m3 3l-3 3')],
            'info' => ['color' => $blue, 'body' => $path('M13 16h-1v-4h-1m1-4h.01', $circle)],
            'start' => ['color' => $blue, 'body' => $path('M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z', $circle)],
            'points' => ['color' => $blue, 'body' => $path('M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z')],
            'schedule' => ['color' => $blue, 'body' => $path($calendar)],
            'score' => ['color' => $blue, 'body' => $path('M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z')],
            'no-score' => ['color' => 'text-[#9E9E9E]', 'body' => $path('M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01', $circle)],
            'feedbacks' => ['color' => $blue, 'body' => $path($bubbles)],
            'no-feedback' => ['color' => 'text-[#C4C4C4]', 'body' => $path('M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636')],
            'discussion' => ['color' => $gray, 'body' => $path($bubbles)],
            'message-outline' => ['color' => $blue, 'body' => $path('M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z')],

            /* status */
            'check' => ['color' => 'text-[#5C9F72]', 'body' => $path('M5 13l4 4L19 7')],
            'in-progress' => ['color' => $blue, 'body' => $path('M8 12h.01M12 12h.01M16 12h.01', $circle)],
            'lock' => ['color' => $gray, 'body' => $path('M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z')],
            'attention' => ['color' => 'text-[#B73A3A]', 'body' => $path($warning)],
            'warning' => ['color' => 'text-white', 'body' => $path($warning)],
            'time' => ['color' => $blue, 'body' => $path($clock)],
            'time-mono' => ['color' => $gray, 'body' => $path($clock)],
        ];
    }
}

if (! function_exists('icon')) {

    /**
     * Inline SVG for a registered icon. Unknown names fall back to a
     * generic file icon rather than breaking the page.
     *
     * @param string $name  Registry name; a trailing ".png" (the old helper's
     *                      file-name form) is accepted and ignored
     * @param string $class Tailwind classes — size, and optionally a text-* color
     * @param string $label Accessible name; empty renders it decorative
     */
    function icon(string $name, string $class = 'h-4 w-4', string $label = ''): string
    {
        $registry = icon_registry();

        $name = preg_replace('/\.png$/', '', $name);

        $name = $name === 'check-green' ? 'check' : $name;

        $icon = $registry[$name] ?? $registry['file'];

        $hasColor = (bool) preg_match('/(^|\s)([\w-]+:)*text-(?!xs\b|sm\b|base\b|lg\b|xl\b|\dxl\b|left\b|right\b|center\b|justify\b|\[\d)/', $class);

        $classes = trim('shrink-0 ' . $class . ($hasColor ? '' : ' ' . $icon['color']));

        $viewBox = $icon['viewBox'] ?? '0 0 24 24';

        $paint = ! empty($icon['fill'])
            ? 'fill="currentColor"'
            : 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';

        $a11y = $label !== ''
            ? 'role="img" aria-label="' . esc($label, 'attr') . '"'
            : 'aria-hidden="true"';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . $viewBox . '" ' . $paint
            . ' class="' . esc($classes) . '" ' . $a11y . ' focusable="false">'
            . $icon['body']
            . '</svg>';
    }
}

if (! function_exists('learning_item_icon')) {

    /**
     * Icon for a learning item type (reading, video, pdf, link, quiz, assignment).
     */
    function learning_item_icon(string $itemType, string $class = 'h-4 w-4'): string
    {
        $name = match (strtolower($itemType)) {
            'reading', 'video', 'pdf', 'link', 'quiz', 'assignment', 'discussion' => strtolower($itemType),
            default => 'file',
        };

        // Item-type icons read as metadata next to a title, so they share
        // one muted color unless the caller asks otherwise.
        return icon($name, $class);
    }
}

if (! function_exists('resource_type_icon')) {

    /**
     * Icon for a Resources-repository file-type bucket (pdf, word,
     * powerpoint, excel, image, video, link, other).
     */
    function resource_type_icon(string $typeBucket, string $class = 'h-5 w-5'): string
    {
        $name = match (strtolower($typeBucket)) {
            'pdf', 'word', 'excel', 'powerpoint', 'image', 'video', 'link' => strtolower($typeBucket),
            default => 'file',
        };

        return icon($name, $class);
    }
}
