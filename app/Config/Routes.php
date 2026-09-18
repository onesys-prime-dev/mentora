<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Auth\RedirectController::index');

service('auth')->routes($routes);

$routes->group('student', ['filter' => 'group:student'], function ($routes) {
    $routes->get('/', 'Dashboard\DashboardController::index');
    $routes->get('dashboard', 'Dashboard\DashboardController::index');

    $routes->get('courses', 'Courses\CourseController::index');
    $routes->get('courses/(:num)/(:segment)', 'Courses\CourseController::redirect/$1/$2');
    $routes->get('courses/(:num)/(:segment)/modules/items/(:num)', 'Courses\ModuleController::viewLearningItem/$1/$2/$3');
    $routes->get('courses/(:num)/(:segment)/(:segment)', 'Courses\CourseController::view/$1/$2/$3');

    $routes->group('api/courses', function ($routes) {
        $routes->get('(:num)/overview', 'Courses\OverviewController::show/$1');
        $routes->get('(:num)/announcements', 'Courses\AnnouncementController::show/$1');
        
        $routes->get('(:num)/modules', 'Courses\ModuleController::show/$1');
        $routes->get('(:num)/modules/items/(:num)', 'Courses\ModuleController::viewLearningItem/$1/$2');

        $routes->get('(:num)/resources', 'Courses\ResourceController::show/$1');
        $routes->get('(:num)/discussions', 'Courses\DiscussionController::show/$1');

        $routes->get('(:num)/assessments', 'Courses\Assessments\AssessmentController::show/$1');
        $routes->get('(:num)/assessments/(:num)/view', 'Courses\Assessments\AssessmentController::viewAssessment/$2');

        $routes->get('(:num)/assessments/(:num)/take', 'Courses\Assessments\QuizAttemptController::start/$2');
        $routes->post('(:num)/submissions/(:num)/questions/(:num)/answer', 'Courses\Assessments\QuizAttemptController::saveAnswer/$2/$3');
        $routes->post('(:num)/submissions/(:num)/submit', 'Courses\Assessments\QuizAttemptController::submit/$2');

        $routes->get('(:num)/grades', 'Courses\GradeController::show/$1');
    });
});

$routes->group('instructor', ['filter' => 'group:instructor'], function ($routes) {
    $routes->get('/', 'Dashboard\DashboardController::index');
    $routes->get('dashboard', 'Dashboard\DashboardController::index');

    $routes->get('courses', 'Courses\CourseController::index');
    $routes->get('courses/(:num)/(:segment)', 'Courses\CourseController::redirect/$1/$2');
    $routes->get('courses/(:num)/(:segment)/modules/items/(:num)', 'Courses\ModuleController::viewLearningItem/$1/$2/$3');
    $routes->get('courses/(:num)/(:segment)/(:segment)', 'Courses\CourseController::view/$1/$2/$3');

    $routes->group('api/courses', function ($routes) {
        $routes->get('(:num)/overview', 'Courses\OverviewController::show/$1');
        $routes->get('(:num)/announcements', 'Courses\AnnouncementController::show/$1');
        
        $routes->get('(:num)/modules', 'Courses\ModuleController::show/$1');

        $routes->post('(:num)/modules', 'Courses\ModuleController::storeModule/$1');
        $routes->post('(:num)/modules/(:num)', 'Courses\ModuleController::updateModule/$1/$2');
        $routes->patch('(:num)/modules/(:num)/status','Courses\ModuleController::updateModuleStatus/$1/$2');
        $routes->delete('(:num)/modules/(:num)', 'Courses\ModuleController::deleteModule/$1/$2');
        $routes->patch('(:num)/modules/reorder', 'Courses\ModuleController::reorderModules/$1');

        $routes->post('(:num)/modules/(:num)/learning-units', 'Courses\ModuleController::storeLearningUnit/$1/$2');
        $routes->post('(:num)/modules/(:num)/learning-units/(:num)', 'Courses\ModuleController::updateLearningUnit/$1/$2/$3');
        $routes->patch('(:num)/learning-units/(:num)/status', 'Courses\ModuleController::updateLearningUnitStatus/$1/$2');
        $routes->delete('(:num)/modules/(:num)/learning-units/(:num)', 'Courses\ModuleController::deleteLearningUnit/$1/$2/$3');
        $routes->patch('(:num)/learning-units/reorder', 'Courses\ModuleController::reorderLearningUnits/$1');

        $routes->post('(:num)/modules/(:num)/learning-units/(:num)/learning-items', 'Courses\ModuleController::storeLearningItem/$1/$2/$3');

        $routes->get('(:num)/modules/items/(:num)', 'Courses\ModuleController::viewLearningItem/$1/$2');
        $routes->get('(:num)/modules/items/edit/(:num)', 'Courses\ModuleController::viewLearningItemEditor/$1/$2');
        $routes->patch('(:num)/modules/items/(:num)', 'Courses\ModuleController::updateLearningItem/$1/$2');
        $routes->delete('(:num)/modules/items/(:num)', 'Courses\ModuleController::deleteLearningItem/$1/$2');
        $routes->post('(:num)/modules/items/(:num)/video', 'Courses\ModuleController::updateVideoItem/$1/$2');
        $routes->patch('(:num)/modules/items/reorder', 'Courses\ModuleController::reorderLearningItems/$1');

        $routes->get('(:num)/resources', 'Courses\ResourceController::show/$1');
        $routes->get('(:num)/discussions', 'Courses\DiscussionController::show/$1');
        $routes->get('(:num)/assessments', 'Courses\Assessments\AssessmentController::show/$1');
        $routes->post('(:num)/modules/(:num)/learning-units/(:num)/assessments', 'Courses\Assessments\AssessmentController::create/$1/$2/$3');
        $routes->get('(:num)/assessments/(:num)/view', 'Courses\Assessments\AssessmentController::viewAssessment/$2');
        $routes->get('(:num)/assessments/(:num)/editor', 'Courses\Assessments\AssessmentController::editAssessment/$2');
        $routes->get('(:num)/assessments/(:num)/quiz-builder', 'Courses\Assessments\AssessmentController::quizBuilder/$2');
        $routes->patch('(:num)/assessments/(:num)', 'Courses\Assessments\AssessmentController::update/$2');
        $routes->delete('(:num)/assessments/(:num)', 'Courses\Assessments\AssessmentController::destroy/$2');
        $routes->patch('(:num)/assessments/(:num)/instructions', 'Courses\Assessments\AssessmentController::updateInstructions/$2');
        $routes->patch('(:num)/assessments/(:num)/submission-settings', 'Courses\Assessments\AssessmentController::updateSubmissionSettings/$2');

        $routes->get('(:num)/assessments/(:num)/rubrics', 'Courses\Assessments\RubricController::index/$2');
        $routes->post('(:num)/assessments/(:num)/rubrics', 'Courses\Assessments\RubricController::store/$2');
        $routes->post('(:num)/assessments/(:num)/rubrics/(:num)/select', 'Courses\Assessments\RubricController::select/$2/$3');
        $routes->get('(:num)/rubrics/(:num)', 'Courses\Assessments\RubricController::show/$2');
        $routes->patch('(:num)/rubrics/(:num)', 'Courses\Assessments\RubricController::update/$2');
        $routes->delete('(:num)/rubrics/(:num)', 'Courses\Assessments\RubricController::destroy/$2');
        $routes->post('(:num)/rubrics/(:num)/criteria', 'Courses\Assessments\RubricController::storeCriterion/$2');
        $routes->patch('(:num)/rubric-criteria/(:num)', 'Courses\Assessments\RubricController::updateCriterion/$2');
        $routes->delete('(:num)/rubric-criteria/(:num)', 'Courses\Assessments\RubricController::destroyCriterion/$2');

        $routes->get('(:num)/assessments/(:num)/questions', 'Courses\Assessments\QuestionController::index/$2');
        $routes->post('(:num)/assessments/(:num)/questions', 'Courses\Assessments\QuestionController::store/$2');
        $routes->patch('(:num)/assessments/(:num)/questions/reorder', 'Courses\Assessments\QuestionController::reorder/$2');
        $routes->get('(:num)/questions/(:num)', 'Courses\Assessments\QuestionController::show/$2');
        $routes->patch('(:num)/questions/(:num)', 'Courses\Assessments\QuestionController::update/$2');
        $routes->delete('(:num)/questions/(:num)', 'Courses\Assessments\QuestionController::destroy/$2');
        $routes->post('(:num)/questions/(:num)/duplicate', 'Courses\Assessments\QuestionController::duplicate/$2');

        $routes->get('(:num)/assessments/(:num)/grade-work', 'Courses\Assessments\GradeWorkController::show/$2');
        $routes->get('(:num)/assessments/(:num)/grade-work/(:num)', 'Courses\Assessments\GradeWorkController::show/$2/$3');
        $routes->patch('(:num)/submissions/(:num)/start-grading', 'Courses\Assessments\GradeWorkController::startGrading/$2');
        $routes->patch('(:num)/submissions/(:num)/grade', 'Courses\Assessments\GradeWorkController::submitGrade/$2');

        $routes->get('(:num)/grades', 'Courses\GradeController::show/$1');
    });
});