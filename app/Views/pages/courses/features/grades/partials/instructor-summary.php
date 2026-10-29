<?php
/**
 * Same three-card header shape as the student view, different numbers:
 * an instructor's first question is "how's the class doing", not "how am
 * I doing" — class average, who's passing, who needs a look.
 */
$classAverage = $classSummary['average_percentage'] ?? null;
$passingCount = (int) ($classSummary['passing_count'] ?? 0);
$totalStudents = (int) ($classSummary['total_students'] ?? 0);
$gradedStudents = (int) ($classSummary['graded_students'] ?? 0);
$needsReview = (int) ($classSummary['needs_review'] ?? 0);
?>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-3">

    <?= view('components/metric-card', [
        'label' => 'Class Average',
        'icon' => 'points',
        'size' => 'prominent',
        'value' => grade_percent($classAverage),
        'detail' => $classAverage !== null ? 'Of ' . $gradedStudents . ($gradedStudents === 1 ? ' student' : ' students') . ' with a grade' : 'Nothing graded yet',
    ], ['saveData' => false]) ?>

    <?= view('components/metric-card', [
        'label' => 'Passing',
        'icon' => 'check',
        'size' => 'prominent',
        'tone' => $gradedStudents > 0 && $passingCount === $gradedStudents ? 'success' : 'neutral',
        'value' => $passingCount . '/' . $gradedStudents . ' Graded',
        'detail' => match (true) {
            $gradedStudents > 0 && $passingCount === $gradedStudents => 'Everyone with a grade is passing',
            $totalStudents > $gradedStudents => 'At or above the passing mark; ' . ($totalStudents - $gradedStudents) . ' not graded yet',
            default => 'At or above the passing mark',
        },
    ], ['saveData' => false]) ?>

    <?= view('components/metric-card', [
        'label' => 'Needs Review',
        'icon' => 'attention',
        'size' => 'prominent',
        'tone' => $needsReview > 0 ? 'danger' : 'neutral',
        'value' => $needsReview . ' Submission' . ($needsReview === 1 ? '' : 's'),
        'detail' => $needsReview > 0 ? 'Waiting on a grade' : 'Nothing waiting',
        'feature' => 'assessments',
    ], ['saveData' => false]) ?>

</div>
