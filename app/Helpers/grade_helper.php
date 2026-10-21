<?php

/*
|--------------------------------------------------------------------------
| How grades are written on screen, once.
|--------------------------------------------------------------------------
| A percentage is shown with up to two decimals and no trailing zeros:
| 85%, 85.5%, 85.25%. The same rule is used for every grade, category and
| class figure, so the same number never reads two ways. The JavaScript
| twin is resources/js/shared/grade-format.js.
|--------------------------------------------------------------------------
*/

if (! function_exists('grade_number')) {

    /** A number with up to two decimals and no trailing zeros ("85", "85.5", "85.25"). */
    function grade_number(null|int|float|string $value, string $empty = '—'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }

        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}

if (! function_exists('grade_percent')) {

    /** A percentage ("85.5%"), or `$empty` when there is no grade yet. */
    function grade_percent(null|int|float|string $value, string $empty = '—'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }

        return grade_number($value) . '%';
    }
}

if (! function_exists('grade_tag_look')) {

    /**
     * How a standing is shown: the status-badge tone and icon.
     *
     * @return array{tone: string, icon: ?string}
     */
    function grade_tag_look(string $tag): array
    {
        return match ($tag) {
            'Passed' => ['tone' => 'success', 'icon' => 'check'],
            'On track' => ['tone' => 'success', 'icon' => 'in-progress'],
            'Failed' => ['tone' => 'danger', 'icon' => 'attention'],
            'At risk' => ['tone' => 'warning', 'icon' => 'attention'],
            'INC' => ['tone' => 'warning', 'icon' => 'in-progress'],
            'Withdrawn' => ['tone' => 'neutral', 'icon' => null],
            default => ['tone' => 'neutral', 'icon' => null],
        };
    }
}
