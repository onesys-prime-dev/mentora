<?php
/**
 * The Program tab's At risk list: the students at risk in every section of the program this term, for either
 * reason, in the instructor's own component (partials/instructor/at-risk), with each section linking to the
 * chairperson's view of its course. The reason filter sits beside the list pills, as on the instructor's page.
 * The dean's (area "dean") is the same list over the college's sections, or the program picked.
 *
 * @var string                     $area
 * @var list<array<string, mixed>> $atRisk
 * @var string|null                $reason
 * @var bool                       $canOpen  false: the sections are plain text (the course page would refuse them)
 */
?>

<div class="flex flex-col gap-3" data-program-at-risk>
    <p class="text-sm text-[#646464]">Students failing so far, with an INC, or falling behind, in every section of <?= $area === 'dean' ? 'your college' : 'your program' ?> this term.</p>

    <?= view('pages/grades/partials/instructor/at-risk', compact('area', 'atRisk', 'reason') + ['canOpen' => $canOpen ?? true], ['saveData' => false]) ?>
</div>
