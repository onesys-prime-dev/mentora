<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Auth\RedirectController::index');

service('auth')->routes($routes);

// Public liveness check for the deploy pipeline; see HealthController.
$routes->get('health', 'HealthController::index');

// Every uploaded file (videos, PDFs, submitted work, AI sources) is served
// here; access is decided per file by FileAccessService.
$routes->get('files/(:num)', 'FileController::show/$1', ['filter' => 'session']);

$routes->group('student', ['filter' => 'group:student'], function ($routes) {
    $routes->get('/', 'Dashboard\DashboardController::index');
    $routes->get('dashboard', 'Dashboard\DashboardController::index');

    $routes->get('courses', 'Courses\CourseController::index');
    $routes->get('courses/(:num)/(:segment)', 'Courses\CourseController::redirect/$1/$2');
    $routes->get('courses/(:num)/(:segment)/modules/items/(:num)', 'Courses\ModuleController::viewLearningItem/$1/$2/$3');
    $routes->get('courses/(:num)/(:segment)/(:segment)', 'Courses\CourseController::view/$1/$2/$3');

    $routes->get('calendar', 'Calendar\CalendarController::index');

    $routes->group('api/calendar', function ($routes) {
        $routes->get('events', 'Calendar\CalendarController::events');

        $routes->post('reminders', 'Calendar\ReminderController::store');
        $routes->patch('reminders/(:num)/done', 'Calendar\ReminderController::markDone/$1');
        $routes->delete('reminders/(:num)', 'Calendar\ReminderController::destroy/$1');
    });
    $routes->get('schedule', 'Schedule\ScheduleController::index');

    $routes->group('api/courses', function ($routes) {
        $routes->get('(:num)/overview', 'Courses\OverviewController::show/$1');
        $routes->get('(:num)/announcements', 'Courses\AnnouncementController::show/$1');
        $routes->get('(:num)/announcements/more', 'Courses\AnnouncementController::loadMore/$1');
        $routes->post('(:num)/announcements/(:num)/reply', 'Courses\AnnouncementController::reply/$1/$2');
        $routes->delete('(:num)/announcements/replies/(:num)', 'Courses\AnnouncementController::deleteReply/$1/$2');
        $routes->post('(:num)/announcements/(:num)/react', 'Courses\AnnouncementController::react/$1/$2');

        $routes->get('(:num)/modules', 'Courses\ModuleController::show/$1');
        $routes->get('(:num)/modules/(:num)', 'Courses\ModuleController::showModule/$1/$2');
        $routes->get('(:num)/modules/items/(:num)', 'Courses\ModuleController::viewLearningItem/$1/$2');
        $routes->post('(:num)/modules/items/(:num)/complete', 'Courses\ModuleController::completeLearningItem/$1/$2');
        $routes->post('(:num)/modules/items/(:num)/progress', 'Courses\ModuleController::saveLearningItemProgress/$1/$2');

        $routes->get('(:num)/resources', 'Courses\ResourceController::show/$1');
        $routes->get('(:num)/discussions', 'Courses\DiscussionController::show/$1');
        $routes->get('(:num)/discussions/more', 'Courses\DiscussionController::loadMore/$1');
        $routes->get('(:num)/discussions/(:num)', 'Courses\DiscussionController::thread/$1/$2');
        $routes->post('(:num)/discussions/attachments', 'Courses\DiscussionController::uploadAttachment/$1');
        $routes->post('(:num)/discussions', 'Courses\DiscussionController::store/$1');
        $routes->patch('(:num)/discussions/(:num)', 'Courses\DiscussionController::update/$1/$2');
        $routes->delete('(:num)/discussions/(:num)', 'Courses\DiscussionController::destroy/$1/$2');
        $routes->patch('(:num)/discussions/(:num)/pin', 'Courses\DiscussionController::pin/$1/$2');
        $routes->patch('(:num)/discussions/(:num)/lock', 'Courses\DiscussionController::lock/$1/$2');
        $routes->post('(:num)/discussions/(:num)/reply', 'Courses\DiscussionController::reply/$1/$2');
        $routes->delete('(:num)/discussions/replies/(:num)', 'Courses\DiscussionController::deleteReply/$1/$2');
        $routes->post('(:num)/discussions/(:num)/react', 'Courses\DiscussionController::react/$1/$2');
        $routes->post('(:num)/discussions/(:num)/answer', 'Courses\DiscussionController::markAnswer/$1/$2');

        $routes->get('(:num)/assessments', 'Courses\Assessments\AssessmentController::show/$1');
        $routes->get('(:num)/assessments/(:num)/view', 'Courses\Assessments\AssessmentController::viewAssessment/$1/$2');

        $routes->get('(:num)/assessments/(:num)/take', 'Courses\Assessments\QuizAttemptController::start/$1/$2');
        $routes->post('(:num)/submissions/(:num)/questions/(:num)/answer', 'Courses\Assessments\QuizAttemptController::saveAnswer/$2/$3');
        $routes->post('(:num)/submissions/(:num)/submit', 'Courses\Assessments\QuizAttemptController::submit/$2');

        $routes->post('(:num)/assessments/(:num)/assignment-submissions', 'Courses\Assessments\AssignmentSubmissionController::submit/$1/$2');

        $routes->post('(:num)/submissions/(:num)/feedback', 'Courses\Assessments\AssessmentFeedbackController::store/$2');

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

    $routes->get('calendar', 'Calendar\CalendarController::index');

    $routes->group('api/calendar', function ($routes) {
        $routes->get('events', 'Calendar\CalendarController::events');

        $routes->post('reminders', 'Calendar\ReminderController::store');
        $routes->patch('reminders/(:num)/done', 'Calendar\ReminderController::markDone/$1');
        $routes->delete('reminders/(:num)', 'Calendar\ReminderController::destroy/$1');

        $routes->post('announcements', 'Courses\AnnouncementController::storeFromCalendar');
    });
    $routes->get('schedule', 'Schedule\ScheduleController::index');

    $routes->group('api/courses', function ($routes) {
        $routes->get('(:num)/overview', 'Courses\OverviewController::show/$1');
        $routes->get('(:num)/announcements', 'Courses\AnnouncementController::show/$1');
        $routes->get('(:num)/announcements/more', 'Courses\AnnouncementController::loadMore/$1');
        $routes->post('(:num)/announcements', 'Courses\AnnouncementController::store/$1');
        $routes->patch('(:num)/announcements/(:num)', 'Courses\AnnouncementController::update/$1/$2');
        $routes->delete('(:num)/announcements/(:num)', 'Courses\AnnouncementController::destroy/$1/$2');
        $routes->patch('(:num)/announcements/(:num)/pin', 'Courses\AnnouncementController::togglePin/$1/$2');
        $routes->post('(:num)/announcements/attachments', 'Courses\AnnouncementController::uploadAttachment/$1');
        $routes->post('(:num)/announcements/(:num)/reply', 'Courses\AnnouncementController::reply/$1/$2');
        $routes->delete('(:num)/announcements/replies/(:num)', 'Courses\AnnouncementController::deleteReply/$1/$2');
        $routes->post('(:num)/announcements/(:num)/react', 'Courses\AnnouncementController::react/$1/$2');

        $routes->get('(:num)/modules', 'Courses\ModuleController::show/$1');
        $routes->get('(:num)/modules/(:num)', 'Courses\ModuleController::showModule/$1/$2');

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
        $routes->post('(:num)/modules/items/(:num)/pdf', 'Courses\ModuleController::updatePdfItem/$1/$2');
        $routes->get('(:num)/modules/items/(:num)/dependencies', 'Courses\ModuleController::learningItemDependencies/$1/$2');
        $routes->patch('(:num)/modules/items/(:num)/dependencies', 'Courses\ModuleController::saveLearningItemDependencies/$1/$2');
        $routes->patch('(:num)/modules/items/reorder', 'Courses\ModuleController::reorderLearningItems/$1');

        $routes->get('(:num)/resources', 'Courses\ResourceController::show/$1');
        $routes->get('(:num)/discussions', 'Courses\DiscussionController::show/$1');
        $routes->get('(:num)/discussions/more', 'Courses\DiscussionController::loadMore/$1');
        $routes->get('(:num)/discussions/(:num)', 'Courses\DiscussionController::thread/$1/$2');
        $routes->post('(:num)/discussions/attachments', 'Courses\DiscussionController::uploadAttachment/$1');
        $routes->post('(:num)/discussions', 'Courses\DiscussionController::store/$1');
        $routes->patch('(:num)/discussions/(:num)', 'Courses\DiscussionController::update/$1/$2');
        $routes->delete('(:num)/discussions/(:num)', 'Courses\DiscussionController::destroy/$1/$2');
        $routes->patch('(:num)/discussions/(:num)/pin', 'Courses\DiscussionController::pin/$1/$2');
        $routes->patch('(:num)/discussions/(:num)/lock', 'Courses\DiscussionController::lock/$1/$2');
        $routes->post('(:num)/discussions/(:num)/reply', 'Courses\DiscussionController::reply/$1/$2');
        $routes->delete('(:num)/discussions/replies/(:num)', 'Courses\DiscussionController::deleteReply/$1/$2');
        $routes->post('(:num)/discussions/(:num)/react', 'Courses\DiscussionController::react/$1/$2');
        $routes->post('(:num)/discussions/(:num)/answer', 'Courses\DiscussionController::markAnswer/$1/$2');
        $routes->get('(:num)/assessments', 'Courses\Assessments\AssessmentController::show/$1');
        $routes->post('(:num)/modules/(:num)/learning-units/(:num)/assessments', 'Courses\Assessments\AssessmentController::create/$1/$2/$3');
        $routes->get('(:num)/assessments/(:num)/view', 'Courses\Assessments\AssessmentController::viewAssessment/$1/$2');
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
        $routes->delete('(:num)/assessments/(:num)/questions', 'Courses\Assessments\QuestionController::clearAll/$2');
        $routes->get('(:num)/questions/(:num)', 'Courses\Assessments\QuestionController::show/$2');
        $routes->patch('(:num)/questions/(:num)', 'Courses\Assessments\QuestionController::update/$2');
        $routes->delete('(:num)/questions/(:num)', 'Courses\Assessments\QuestionController::destroy/$2');
        $routes->post('(:num)/questions/(:num)/duplicate', 'Courses\Assessments\QuestionController::duplicate/$2');

        $routes->get('(:num)/assessments/(:num)/grade-work', 'Courses\Assessments\GradeWorkController::show/$1/$2');
        $routes->get('(:num)/assessments/(:num)/grade-work/(:num)', 'Courses\Assessments\GradeWorkController::show/$1/$2/$3');
        $routes->patch('(:num)/submissions/(:num)/start-grading', 'Courses\Assessments\GradeWorkController::startGrading/$2');
        $routes->patch('(:num)/submissions/(:num)/reopen-grading', 'Courses\Assessments\GradeWorkController::reopenGrading/$2');
        $routes->patch('(:num)/submissions/(:num)/grade', 'Courses\Assessments\GradeWorkController::submitGrade/$2');
        $routes->patch('(:num)/submissions/(:num)/questions/(:num)/grade', 'Courses\Assessments\GradeWorkController::gradeQuestion/$2/$3');
        $routes->post('(:num)/submissions/(:num)/feedback', 'Courses\Assessments\AssessmentFeedbackController::store/$2');

        $routes->post('(:num)/generate-questions', 'Courses\Assessments\QuestionGenerationController::start/$1');
        $routes->post('(:num)/generation-batches/(:num)/next-chunk', 'Courses\Assessments\QuestionGenerationController::nextChunk/$2');
        $routes->get('(:num)/generation-batches/(:num)', 'Courses\Assessments\QuestionGenerationController::show/$2');
        $routes->patch('(:num)/generated-questions/(:num)', 'Courses\Assessments\QuestionGenerationController::updateQuestion/$2');
        $routes->post('(:num)/generated-questions/(:num)/accept', 'Courses\Assessments\QuestionGenerationController::acceptQuestion/$2');
        $routes->post('(:num)/generated-questions/(:num)/discard', 'Courses\Assessments\QuestionGenerationController::discardQuestion/$2');
        $routes->post('(:num)/generation-batches/(:num)/accept-all', 'Courses\Assessments\QuestionGenerationController::acceptAll/$2');

        $routes->get('(:num)/assessments/(:num)/export', 'Courses\Assessments\QuestionImportExportController::export/$2');
        $routes->post('(:num)/assessments/(:num)/import/preview', 'Courses\Assessments\QuestionImportExportController::preview/$2');
        $routes->post('(:num)/assessments/(:num)/import/apply', 'Courses\Assessments\QuestionImportExportController::apply/$2');

        $routes->get('(:num)/grades', 'Courses\GradeController::show/$1');

        // Grading scheme (categories, weights, grade items) + recalculation
        $routes->get('(:num)/grades/scheme', 'Courses\Grades\GradingSchemeController::show/$1');
        $routes->patch('(:num)/grades/scheme', 'Courses\Grades\GradingSchemeController::update/$1');
        $routes->post('(:num)/grades/scheme/categories', 'Courses\Grades\GradingSchemeController::storeCategory/$1');
        $routes->patch('(:num)/grades/scheme/categories/(:num)', 'Courses\Grades\GradingSchemeController::updateCategory/$1/$2');
        $routes->delete('(:num)/grades/scheme/categories/(:num)', 'Courses\Grades\GradingSchemeController::deleteCategory/$1/$2');
        $routes->post('(:num)/grades/scheme/categories/(:num)/items', 'Courses\Grades\GradingSchemeController::storeItem/$1/$2');
        $routes->patch('(:num)/grades/scheme/items/(:num)', 'Courses\Grades\GradingSchemeController::updateItem/$1/$2');
        $routes->delete('(:num)/grades/scheme/items/(:num)', 'Courses\Grades\GradingSchemeController::deleteItem/$1/$2');
        $routes->post('(:num)/grades/scheme/items/(:num)/resync', 'Courses\Grades\GradingSchemeController::resyncItem/$1/$2');
        $routes->post('(:num)/grades/recalculate', 'Courses\Grades\GradingSchemeController::recalculate/$1');

        // Grading periods (Prelim / Midterm / Finals, ...)
        $routes->post('(:num)/grades/scheme/periods', 'Courses\Grades\GradingSchemeController::storePeriod/$1');
        $routes->patch('(:num)/grades/scheme/periods/(:num)', 'Courses\Grades\GradingSchemeController::updatePeriod/$1/$2');
        $routes->delete('(:num)/grades/scheme/periods/(:num)', 'Courses\Grades\GradingSchemeController::deletePeriod/$1/$2');

        // Gradebook grid: manual item scores + final-grade override
        $routes->get('(:num)/grades/roster', 'Courses\Grades\GradingSchemeController::roster/$1');
        $routes->patch('(:num)/grades/roster/items/(:num)/students/(:num)', 'Courses\Grades\GradingSchemeController::updateManualScore/$1/$2/$3');
        $routes->patch('(:num)/grades/roster/students/(:num)/override', 'Courses\Grades\GradingSchemeController::overrideGrade/$1/$2');

        // Offline grade sheet (XLSX / CSV)
        $routes->get('(:num)/grades/export', 'Courses\Grades\GradeSheetController::export/$1');
        $routes->post('(:num)/grades/import/preview', 'Courses\Grades\GradeSheetController::preview/$1');
        $routes->post('(:num)/grades/import/apply', 'Courses\Grades\GradeSheetController::apply/$1');
    });
});