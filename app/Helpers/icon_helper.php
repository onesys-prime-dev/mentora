<?php

if (! function_exists('icon')) {

    /**
     * Renders a monochrome icon whose colour is set by CSS, not by the file.
     *
     * The PNG is used as a mask, so only its alpha channel (the shape) is
     * kept and the fill comes from `currentColor`. That means one asset
     * reads correctly on a dark bubble and on a light tile — without it,
     * the dark-glyph icons (hat, message, calendar, bell, time, apps, door,
     * night) disappear on dark backgrounds and the white-glyph ones (tasks,
     * lessons, warning, code) disappear on light ones.
     *
     * Set the colour with any Tailwind text utility:
     *
     *     icon('hat.png', 'w-4 h-4 text-white')
     *     icon('tasks.png', 'w-6 h-6 text-gray-700')
     *
     * @param string $name    File name inside assets/images/icons
     * @param string $classes Tailwind classes — size and text colour
     * @param string $label   Accessible name; empty renders it decorative
     */
    function icon(string $name, string $classes = 'w-4 h-4', string $label = ''): string
    {
        // The name lands inside a CSS url() in a style attribute, so keep it
        // to plain filename characters rather than relying on escaping.
        $name = preg_replace('/[^A-Za-z0-9._-]/', '', $name);

        $url = base_url('assets/images/icons/' . $name);

        $a11y = $label !== ''
            ? 'role="img" aria-label="' . esc($label, 'attr') . '"'
            : 'aria-hidden="true"';

        return '<span class="icon ' . esc($classes) . '"'
            . ' style="--icon-url:url(\'' . esc($url) . '\')" '
            . $a11y . '></span>';
    }
}
