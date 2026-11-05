<?= view('components/breadcrumb', ['crumbs' => [
    ['label' => 'Assessments', 'attrs' => 'data-assessments-breadcrumb'],
    ['label' => $currentAssessment['title']],
], 'sticky' => $breadcrumbSticky ?? true], ['saveData' => false]) ?>
