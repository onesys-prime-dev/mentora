<?= view('components/breadcrumb', ['crumbs' => [
    ['label' => 'Assessments', 'attrs' => 'data-assessments-breadcrumb'],
    ['label' => $currentAssessment['title']],
]], ['saveData' => false]) ?>
