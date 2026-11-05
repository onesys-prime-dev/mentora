<?php

/*
|--------------------------------------------------------------------------
| What the quiz page saw during one attempt, for the teacher (grading page).
| Shown only when something was recorded. It says that the student left the
| page and for how long, never where they went: a web page cannot know.
|
| Data: $security (QuizAttemptService::securityReport): events + summary.
|--------------------------------------------------------------------------
*/

$events = $security['events'] ?? [];

if ($events === []) {
    return;
}

$summary = $security['summary'];

$duration = static function (int $seconds): string {
    if ($seconds < 60) {
        return $seconds . ' s';
    }

    $minutes = intdiv($seconds, 60);
    $rest = $seconds % 60;

    return $minutes . ' min' . ($rest > 0 ? ' ' . $rest . ' s' : '');
};

$describe = static fn (array $e): string => match ($e['kind']) {
    'left_page'       => 'Left the page for ' . $duration((int) $e['duration_seconds']),
    'left_fullscreen' => 'Left fullscreen',
    default           => 'Tried to ' . ($e['detail'] === 'contextmenu' ? 'open the right-click menu' : (string) $e['detail']),
};

$facts = [];

if ($summary['left_page'] > 0) {
    $facts[] = 'Left the page ' . $summary['left_page'] . ' time' . ($summary['left_page'] === 1 ? '' : 's') . ', ' . $duration((int) $summary['away_seconds']) . ' away';
}

if ($summary['left_fullscreen'] > 0) {
    $facts[] = 'Left fullscreen ' . $summary['left_fullscreen'] . ' time' . ($summary['left_fullscreen'] === 1 ? '' : 's');
}

if ($summary['blocked_actions'] > 0) {
    $facts[] = $summary['blocked_actions'] . ' blocked action' . ($summary['blocked_actions'] === 1 ? '' : 's');
}
?>

<details data-security-report class="rounded-xl border border-[#F3D9A4] bg-[#FFF8E8] px-4 py-3 text-sm text-[#7A5600]">

    <summary class="cursor-pointer font-semibold">
        Quiz security: <?= esc(implode(' · ', $facts)) ?>
    </summary>

    <ul class="mt-2 flex flex-col gap-1 text-xs">

        <?php foreach ($events as $e): ?>

            <li data-security-event>
                <span class="text-[#946200]"><?= esc(date('g:i:s A', strtotime((string) $e['occurred_at']))) ?></span>
                · <?= esc($describe($e)) ?>
            </li>

        <?php endforeach; ?>

    </ul>

    <p class="mt-2 text-xs text-[#946200]">
        Recorded by the quiz page. It can tell that the student left, not where they went, so it is a prompt to look, not proof.
    </p>

</details>
