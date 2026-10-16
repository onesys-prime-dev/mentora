<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Auth\RedirectController::index');
$routes->post('role/switch', 'Auth\RedirectController::switchRole');

service('auth')->routes($routes);

// Public liveness check for the deploy pipeline; see HealthController.
$routes->get('health', 'HealthController::index');

// An invited person sets their password here, from the emailed link. Public on
// purpose: they have no account yet; the secret in the link is the proof.
$routes->get('invitation/(:segment)', 'InvitationController::show/$1');
$routes->post('invitation/(:segment)', 'InvitationController::accept/$1');

// A person who was sent a password reset link chooses a new password here. Public
// on purpose (they are locked out); the secret in the link is the proof.
$routes->get('reset-password/(:segment)', 'PasswordResetController::show/$1');
$routes->post('reset-password/(:segment)', 'PasswordResetController::complete/$1');

// Leaving a "view as" session. Outside the role areas on purpose: while viewing, the session
// is the other person's and must not need the admin group to get out (the filter allows it).
$routes->post('impersonation/stop', 'Admin\ImpersonationController::stop');

// The question-builder worker's side of the job queue: no session, loopback and
// a shared secret only (AiWorkerFilter).
$routes->group('internal/ai', ['filter' => 'aiworker'], function ($routes) {
    $routes->post('claim', 'Internal\AiJobsController::claim');
    $routes->post('heartbeat', 'Internal\AiJobsController::heartbeat');
    $routes->post('progress', 'Internal\AiJobsController::progress');
    $routes->post('complete', 'Internal\AiJobsController::complete');
    $routes->post('fail', 'Internal\AiJobsController::fail');
});

// A signed-in person's own notifications, for every role.
$routes->get('notifications', 'NotificationController::index', ['filter' => 'session']);
$routes->post('notifications/read', 'NotificationController::read', ['filter' => 'session']);

// Every uploaded file (videos, PDFs, submitted work, AI sources) is served
// here; access is decided per file by FileAccessService.
$routes->get('files/(:num)', 'FileController::show/$1', ['filter' => 'session']);

$routes->group('student', ['filter' => ['role:student', 'readonly']], function ($routes) {
    $routes->get('/', 'Dashboard\DashboardController::index');
    $routes->get('dashboard', 'Dashboard\DashboardController::index');

    $routes->get('courses', 'Courses\CourseController::index');
    $routes->get('courses/(:num)/(:segment)', 'Courses\CourseController::redirect/$1/$2', ['filter' => 'coursemember:student']);
    $routes->get('courses/(:num)/(:segment)/modules/items/(:num)', 'Courses\CourseController::view/$1/$2/modules', ['filter' => 'coursemember:student']);
    $routes->get('courses/(:num)/(:segment)/(:segment)', 'Courses\CourseController::view/$1/$2/$3', ['filter' => 'coursemember:student']);

    $routes->get('calendar', 'Calendar\CalendarController::index');

    $routes->group('api/calendar', ['filter' => 'closedoffering'], function ($routes) {
        $routes->get('events', 'Calendar\CalendarController::events');

        $routes->post('reminders', 'Calendar\ReminderController::store');
        $routes->patch('reminders/(:num)/done', 'Calendar\ReminderController::markDone/$1');
        $routes->delete('reminders/(:num)', 'Calendar\ReminderController::destroy/$1');
    });
    $routes->get('schedule', 'Schedule\ScheduleController::index');

    $routes->group('api/courses', ['filter' => ['coursemember:student', 'assessmentscope', 'closedoffering']], function ($routes) {
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

$routes->group('instructor', ['filter' => ['role:instructor', 'readonly']], function ($routes) {
    $routes->get('/', 'Dashboard\DashboardController::index');
    $routes->get('dashboard', 'Dashboard\DashboardController::index');

    $routes->get('courses', 'Courses\CourseController::index');
    $routes->get('courses/(:num)/(:segment)', 'Courses\CourseController::redirect/$1/$2', ['filter' => 'coursemember:instructor']);
    $routes->get('courses/(:num)/(:segment)/modules/items/(:num)', 'Courses\CourseController::view/$1/$2/modules', ['filter' => 'coursemember:instructor']);
    $routes->get('courses/(:num)/(:segment)/(:segment)', 'Courses\CourseController::view/$1/$2/$3', ['filter' => 'coursemember:instructor']);

    $routes->get('calendar', 'Calendar\CalendarController::index');

    $routes->group('api/calendar', ['filter' => 'closedoffering'], function ($routes) {
        $routes->get('events', 'Calendar\CalendarController::events');

        $routes->post('reminders', 'Calendar\ReminderController::store');
        $routes->patch('reminders/(:num)/done', 'Calendar\ReminderController::markDone/$1');
        $routes->delete('reminders/(:num)', 'Calendar\ReminderController::destroy/$1');

        $routes->post('announcements', 'Courses\AnnouncementController::storeFromCalendar');
    });

    $routes->get('schedule', 'Schedule\ScheduleController::index');

    $routes->group('api/courses', ['filter' => ['coursemember:instructor', 'assessmentscope', 'closedoffering']], function ($routes) {
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
        $routes->post('(:num)/generation-batches/(:num)/next-chunk', 'Courses\Assessments\QuestionGenerationController::nextChunk/$1/$2');
        $routes->get('(:num)/generation-batches/(:num)', 'Courses\Assessments\QuestionGenerationController::show/$1/$2');
        $routes->patch('(:num)/generated-questions/(:num)', 'Courses\Assessments\QuestionGenerationController::updateQuestion/$1/$2');
        $routes->post('(:num)/generated-questions/(:num)/accept', 'Courses\Assessments\QuestionGenerationController::acceptQuestion/$1/$2');
        $routes->post('(:num)/generated-questions/(:num)/discard', 'Courses\Assessments\QuestionGenerationController::discardQuestion/$1/$2');
        $routes->post('(:num)/generated-questions/(:num)/replace', 'Courses\Assessments\QuestionGenerationController::replaceQuestion/$1/$2');
        $routes->post('(:num)/generation-batches/(:num)/accept-all', 'Courses\Assessments\QuestionGenerationController::acceptAll/$1/$2');
        $routes->post('(:num)/generation-batches/(:num)/cancel', 'Courses\Assessments\QuestionGenerationController::cancel/$1/$2');
        $routes->post('(:num)/generation-batches/(:num)/discard-all', 'Courses\Assessments\QuestionGenerationController::discardAll/$1/$2');
        $routes->post('(:num)/generation-batches/(:num)/top-up', 'Courses\Assessments\QuestionGenerationController::topUp/$1/$2');
        $routes->get('(:num)/assessments/(:num)/pending-generation', 'Courses\Assessments\QuestionGenerationController::pending/$1/$2');

        $routes->get('(:num)/assessments/(:num)/export', 'Courses\Assessments\QuestionImportExportController::export/$2');
        $routes->post('(:num)/assessments/(:num)/import/preview', 'Courses\Assessments\QuestionImportExportController::preview/$2');
        $routes->post('(:num)/assessments/(:num)/import/apply', 'Courses\Assessments\QuestionImportExportController::apply/$2');
        $routes->post('(:num)/assessments/(:num)/import/undo', 'Courses\Assessments\QuestionImportExportController::undo/$2');
        $routes->get('(:num)/assessments/(:num)/import/sample', 'Courses\Assessments\QuestionImportExportController::sample/$2');
        $routes->get('(:num)/assessments/(:num)/question-sources', 'Courses\Assessments\QuestionPickerController::sources/$2');
        $routes->get('(:num)/assessments/(:num)/question-sources/(:num)', 'Courses\Assessments\QuestionPickerController::questions/$2/$3');
        $routes->post('(:num)/assessments/(:num)/question-sources/copy', 'Courses\Assessments\QuestionPickerController::copy/$2');

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

// The dean oversees one department. Every route in this group is read-only
// oversight; role:dean also checks the dean's active assignment (ActiveRoleService).
$routes->group('dean', ['filter' => ['role:dean', 'readonly']], function ($routes) {
    $routes->get('/', 'Dean\DeanDashboardController::index');
    $routes->get('dashboard', 'Dean\DeanDashboardController::index');
    $routes->get('courses', 'Dean\DeanCoursesController::index');
    $routes->get('monitor-department', 'Dean\MonitorDepartmentController::index');
    $routes->get('monitor-department/programs/(:num)', 'Dean\MonitorDepartmentController::program/$1');

    // A read-only view of every course of the department (courses.view), exactly what its
    // instructor sees: the same controllers as the instructor's, but only the reading routes.
    // There is no route to post, patch or delete anything in a course here, so nothing can be
    // changed from this view; the editors, the grading screens and the question-generation
    // tools are left out, as in the institution's view. The offering must be in the dean's
    // department, else 404 (deancourse).
    $routes->get('courses/(:num)/(:segment)', 'Courses\CourseController::redirect/$1/$2', ['filter' => ['permission:courses.view', 'deancourse']]);
    $routes->get('courses/(:num)/(:segment)/modules/items/(:num)', 'Courses\ModuleController::viewLearningItem/$1/$2/$3', ['filter' => ['permission:courses.view', 'deancourse']]);
    $routes->get('courses/(:num)/(:segment)/(:segment)', 'Courses\CourseController::view/$1/$2/$3', ['filter' => ['permission:courses.view', 'deancourse']]);

    $routes->group('api/courses', ['filter' => ['permission:courses.view', 'deancourse', 'assessmentscope']], function ($routes) {
        $routes->get('(:num)/overview', 'Courses\OverviewController::show/$1');
        $routes->get('(:num)/announcements', 'Courses\AnnouncementController::show/$1');
        $routes->get('(:num)/announcements/more', 'Courses\AnnouncementController::loadMore/$1');
        $routes->get('(:num)/modules', 'Courses\ModuleController::show/$1');
        $routes->get('(:num)/modules/(:num)', 'Courses\ModuleController::showModule/$1/$2');
        $routes->get('(:num)/modules/items/(:num)', 'Courses\ModuleController::viewLearningItem/$1/$2');
        $routes->get('(:num)/modules/items/(:num)/dependencies', 'Courses\ModuleController::learningItemDependencies/$1/$2');
        $routes->get('(:num)/resources', 'Courses\ResourceController::show/$1');
        $routes->get('(:num)/discussions', 'Courses\DiscussionController::show/$1');
        $routes->get('(:num)/discussions/more', 'Courses\DiscussionController::loadMore/$1');
        $routes->get('(:num)/discussions/(:num)', 'Courses\DiscussionController::thread/$1/$2');
        $routes->get('(:num)/assessments', 'Courses\Assessments\AssessmentController::show/$1');
        $routes->get('(:num)/assessments/(:num)/view', 'Courses\Assessments\AssessmentController::viewAssessment/$1/$2');
        $routes->get('(:num)/assessments/(:num)/rubrics', 'Courses\Assessments\RubricController::index/$2');
        $routes->get('(:num)/rubrics/(:num)', 'Courses\Assessments\RubricController::show/$2');
        $routes->get('(:num)/assessments/(:num)/questions', 'Courses\Assessments\QuestionController::index/$2');
        $routes->get('(:num)/questions/(:num)', 'Courses\Assessments\QuestionController::show/$2');
        $routes->get('(:num)/grades', 'Courses\GradeController::show/$1');
        $routes->get('(:num)/grades/scheme', 'Courses\Grades\GradingSchemeController::show/$1');
        $routes->get('(:num)/grades/roster', 'Courses\Grades\GradingSchemeController::roster/$1');
        $routes->get('(:num)/grades/export', 'Courses\Grades\GradeSheetController::export/$1');
    });
});

// A chairperson has full instructor rights on every course in their
// program (R7 of the chairperson plan) — this block mirrors instructor's
// course routes exactly, same controllers, scoped by ChairpersonAccessService
// instead of InstructorAssignmentModel. Calendar and schedule are the
// chairperson's own too, over the program's courses (getCourseOfferings).
$routes->group('chairperson', ['filter' => ['role:chairperson', 'readonly']], function ($routes) {
    $routes->get('/', 'Dashboard\DashboardController::index');
    $routes->get('dashboard', 'Dashboard\DashboardController::index');

    $routes->get('calendar', 'Calendar\CalendarController::index');

    $routes->group('api/calendar', ['filter' => 'closedoffering'], function ($routes) {
        $routes->get('events', 'Calendar\CalendarController::events');

        $routes->post('reminders', 'Calendar\ReminderController::store');
        $routes->patch('reminders/(:num)/done', 'Calendar\ReminderController::markDone/$1');
        $routes->delete('reminders/(:num)', 'Calendar\ReminderController::destroy/$1');

        $routes->post('announcements', 'Courses\AnnouncementController::storeFromCalendar');
    });

    $routes->get('schedule', 'Schedule\ScheduleController::index');

    $routes->get('courses', 'Courses\ChairpersonCoursesController::index');
    $routes->get('monitor-program', 'Courses\MonitorProgramController::index');

    // Monitor Program's data layer (R5) — a separate URL space from
    // api/courses below, since these aren't scoped to one offering and
    // have no numeric id segment for assessmentscope/chairpersoncourseaudit
    // to key off; role:chairperson plus MonitorProgramService's own
    // program-scoped join is this group's only access check.
    $routes->group('api/monitor-program', function ($routes) {
        $routes->get('snapshot', 'Courses\MonitorProgramController::snapshot');
        $routes->get('enrollment', 'Courses\MonitorProgramController::enrollment');
        $routes->get('grades', 'Courses\MonitorProgramController::grades');
        $routes->get('instructor-activity', 'Courses\MonitorProgramController::instructorActivity');
        $routes->get('at-risk', 'Courses\MonitorProgramController::atRisk');
        $routes->get('course-comparison', 'Courses\MonitorProgramController::courseComparison');
        $routes->get('terms', 'Courses\MonitorProgramController::terms');
    });
    $routes->get('courses/(:num)/(:segment)', 'Courses\CourseController::redirect/$1/$2', ['filter' => 'coursemember:chairperson']);
    $routes->get('courses/(:num)/(:segment)/modules/items/(:num)', 'Courses\CourseController::view/$1/$2/modules', ['filter' => 'coursemember:chairperson']);
    $routes->get('courses/(:num)/(:segment)/(:segment)', 'Courses\CourseController::view/$1/$2/$3', ['filter' => 'coursemember:chairperson']);

    $routes->group('api/courses', ['filter' => ['coursemember:chairperson', 'assessmentscope', 'closedoffering', 'chairpersoncourseaudit']], function ($routes) {
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
        $routes->post('(:num)/generation-batches/(:num)/next-chunk', 'Courses\Assessments\QuestionGenerationController::nextChunk/$1/$2');
        $routes->get('(:num)/generation-batches/(:num)', 'Courses\Assessments\QuestionGenerationController::show/$1/$2');
        $routes->patch('(:num)/generated-questions/(:num)', 'Courses\Assessments\QuestionGenerationController::updateQuestion/$1/$2');
        $routes->post('(:num)/generated-questions/(:num)/accept', 'Courses\Assessments\QuestionGenerationController::acceptQuestion/$1/$2');
        $routes->post('(:num)/generated-questions/(:num)/discard', 'Courses\Assessments\QuestionGenerationController::discardQuestion/$1/$2');
        $routes->post('(:num)/generated-questions/(:num)/replace', 'Courses\Assessments\QuestionGenerationController::replaceQuestion/$1/$2');
        $routes->post('(:num)/generation-batches/(:num)/accept-all', 'Courses\Assessments\QuestionGenerationController::acceptAll/$1/$2');
        $routes->post('(:num)/generation-batches/(:num)/cancel', 'Courses\Assessments\QuestionGenerationController::cancel/$1/$2');
        $routes->post('(:num)/generation-batches/(:num)/discard-all', 'Courses\Assessments\QuestionGenerationController::discardAll/$1/$2');
        $routes->post('(:num)/generation-batches/(:num)/top-up', 'Courses\Assessments\QuestionGenerationController::topUp/$1/$2');
        $routes->get('(:num)/assessments/(:num)/pending-generation', 'Courses\Assessments\QuestionGenerationController::pending/$1/$2');

        $routes->get('(:num)/assessments/(:num)/export', 'Courses\Assessments\QuestionImportExportController::export/$2');
        $routes->post('(:num)/assessments/(:num)/import/preview', 'Courses\Assessments\QuestionImportExportController::preview/$2');
        $routes->post('(:num)/assessments/(:num)/import/apply', 'Courses\Assessments\QuestionImportExportController::apply/$2');
        $routes->post('(:num)/assessments/(:num)/import/undo', 'Courses\Assessments\QuestionImportExportController::undo/$2');
        $routes->get('(:num)/assessments/(:num)/import/sample', 'Courses\Assessments\QuestionImportExportController::sample/$2');
        $routes->get('(:num)/assessments/(:num)/question-sources', 'Courses\Assessments\QuestionPickerController::sources/$2');
        $routes->get('(:num)/assessments/(:num)/question-sources/(:num)', 'Courses\Assessments\QuestionPickerController::questions/$2/$3');
        $routes->post('(:num)/assessments/(:num)/question-sources/copy', 'Courses\Assessments\QuestionPickerController::copy/$2');

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

// "Confirm your password" in front of dangerous actions, for every administrative area
// except the system administrator's own address (admin/reauth, below). The ReauthFilter
// sends people here.
$routes->get('reauth', 'ReauthController::show', ['filter' => 'role:institutional_admin']);
$routes->post('reauth', 'ReauthController::confirm', ['filter' => 'role:institutional_admin']);

// A gallery of the shared interface components (app/Views/components), for the people
// building and reviewing screens. Signed-in people only; not routed in production.
if (ENVIRONMENT !== 'production') {
    $routes->get('ui-kit', 'UiKitController::index', ['filter' => 'session']);
    $routes->post('ui-kit', 'UiKitController::submit', ['filter' => 'session']);
}

// Institutional administrator area. Everyone in it is the institutional administrator
// of one institution, which the server decides from who is signed in (InstitutionScope).
// The data export is deliberately NOT behind `readonly`: it must keep working once the
// subscription has ended (SA13). Everything that writes goes in the second group.
$routes->group('institution', ['filter' => 'role:institutional_admin'], function ($routes) {
    $routes->get('export', 'Institution\ExportController::index');
    $routes->post('export', 'Institution\ExportController::create');
    $routes->get('export/(:num)', 'Institution\ExportController::download/$1');

    // Viewing as one of the institution's people (view only). Not behind `readonly`: it changes
    // nothing, so it works in a read-only institution too. The password is asked first.
    $routes->post('view-as/(:num)', 'Institution\ViewAsController::start/$1', ['filter' => ['permission:institution.impersonate', 'reauth']]);

    $routes->group('', ['filter' => 'readonly'], function ($routes) {
        $routes->get('/', 'Institution\HomeController::index');
        $routes->get('dashboard', 'Institution\HomeController::index');

        // Academic structure: departments, sub-departments, programs (structure.manage).
        $routes->group('structure', ['filter' => 'permission:structure.manage'], function ($routes) {
            $routes->get('/', 'Institution\StructureController::index');

            $routes->post('departments', 'Institution\StructureController::createDepartment');
            $routes->post('departments/(:num)', 'Institution\StructureController::updateDepartment/$1');
            $routes->post('departments/(:num)/deactivate', 'Institution\StructureController::deactivateDepartment/$1');
            $routes->post('departments/(:num)/reactivate', 'Institution\StructureController::reactivateDepartment/$1');
            $routes->post('departments/(:num)/sub-departments', 'Institution\StructureController::createSubDepartment/$1');

            $routes->post('sub-departments/(:num)', 'Institution\StructureController::updateSubDepartment/$1');
            $routes->post('sub-departments/(:num)/deactivate', 'Institution\StructureController::deactivateSubDepartment/$1');
            $routes->post('sub-departments/(:num)/reactivate', 'Institution\StructureController::reactivateSubDepartment/$1');
            $routes->post('sub-departments/(:num)/programs', 'Institution\StructureController::createProgram/$1');

            $routes->post('programs/(:num)', 'Institution\StructureController::updateProgram/$1');
            $routes->post('programs/(:num)/deactivate', 'Institution\StructureController::deactivateProgram/$1');
            $routes->post('programs/(:num)/archive', 'Institution\StructureController::archiveProgram/$1');
            $routes->post('programs/(:num)/reactivate', 'Institution\StructureController::reactivateProgram/$1');

            $routes->post('sub-departments/(:num)/chairpersons', 'Institution\StructureController::assignChairperson/$1');
            $routes->post('chairpersons/(:num)/remove', 'Institution\StructureController::revokeChairperson/$1');
        });

        // Academic terms (terms.manage).
        $routes->group('terms', ['filter' => 'permission:terms.manage'], function ($routes) {
            $routes->get('/', 'Institution\TermsController::index');
            $routes->post('/', 'Institution\TermsController::create');
            $routes->post('(:num)', 'Institution\TermsController::update/$1');
            $routes->post('(:num)/start', 'Institution\TermsController::start/$1');
            $routes->post('(:num)/upcoming', 'Institution\TermsController::unstart/$1');
            $routes->get('copy', 'Institution\OfferingCopyController::index');
            $routes->post('copy', 'Institution\OfferingCopyController::copy');
            $routes->get('(:num)/close', 'Institution\TermCloseController::confirm/$1');
            $routes->post('(:num)/close', 'Institution\TermCloseController::close/$1', ['filter' => 'reauth']);
            $routes->post('(:num)/reopen', 'Institution\TermCloseController::reopen/$1', ['filter' => 'reauth']);
        });

        // Course offerings and their instructors (offerings.manage).
        $routes->group('offerings', ['filter' => 'permission:offerings.manage'], function ($routes) {
            $routes->get('/', 'Institution\OfferingsController::index');
            $routes->post('/', 'Institution\OfferingsController::create');
            $routes->post('(:num)', 'Institution\OfferingsController::update/$1');
            $routes->post('(:num)/archive', 'Institution\OfferingsController::archive/$1');
            $routes->post('(:num)/reactivate', 'Institution\OfferingsController::reactivate/$1');
            $routes->post('(:num)/instructors', 'Institution\OfferingsController::assignInstructor/$1');
            $routes->post('instructors/(:num)/remove', 'Institution\OfferingsController::unassignInstructor/$1');
        });

        // Who is enrolled in an offering (enrolments.manage).
        $routes->group('enrolments', ['filter' => 'permission:enrolments.manage'], function ($routes) {
            $routes->get('offering/(:num)', 'Institution\EnrolmentsController::roster/$1');
            $routes->post('offering/(:num)', 'Institution\EnrolmentsController::add/$1');
            $routes->post('(:num)/status', 'Institution\EnrolmentsController::setStatus/$1');
        });

        // A read-only view of every course of the institution (courses.view), exactly what its
        // instructor sees. Only the reading routes of a course exist here: there is no route to
        // post, patch or delete anything in a course, so nothing can be changed from this view.
        // The editors, the grading screens and the question-generation tools are left out too.
        $routes->get('courses/(:num)/(:segment)', 'Courses\CourseController::redirect/$1/$2', ['filter' => ['permission:courses.view', 'institutioncourse']]);
        $routes->get('courses/(:num)/(:segment)/modules/items/(:num)', 'Courses\ModuleController::viewLearningItem/$1/$2/$3', ['filter' => ['permission:courses.view', 'institutioncourse']]);
        $routes->get('courses/(:num)/(:segment)/(:segment)', 'Courses\CourseController::view/$1/$2/$3', ['filter' => ['permission:courses.view', 'institutioncourse']]);

        $routes->group('api/courses', ['filter' => ['permission:courses.view', 'institutioncourse', 'assessmentscope']], function ($routes) {
            $routes->get('(:num)/overview', 'Courses\OverviewController::show/$1');
            $routes->get('(:num)/announcements', 'Courses\AnnouncementController::show/$1');
            $routes->get('(:num)/announcements/more', 'Courses\AnnouncementController::loadMore/$1');
            $routes->get('(:num)/modules', 'Courses\ModuleController::show/$1');
            $routes->get('(:num)/modules/(:num)', 'Courses\ModuleController::showModule/$1/$2');
            $routes->get('(:num)/modules/items/(:num)', 'Courses\ModuleController::viewLearningItem/$1/$2');
            $routes->get('(:num)/modules/items/(:num)/dependencies', 'Courses\ModuleController::learningItemDependencies/$1/$2');
            $routes->get('(:num)/resources', 'Courses\ResourceController::show/$1');
            $routes->get('(:num)/discussions', 'Courses\DiscussionController::show/$1');
            $routes->get('(:num)/discussions/more', 'Courses\DiscussionController::loadMore/$1');
            $routes->get('(:num)/discussions/(:num)', 'Courses\DiscussionController::thread/$1/$2');
            $routes->get('(:num)/assessments', 'Courses\Assessments\AssessmentController::show/$1');
            $routes->get('(:num)/assessments/(:num)/view', 'Courses\Assessments\AssessmentController::viewAssessment/$1/$2');
            $routes->get('(:num)/assessments/(:num)/rubrics', 'Courses\Assessments\RubricController::index/$2');
            $routes->get('(:num)/rubrics/(:num)', 'Courses\Assessments\RubricController::show/$2');
            $routes->get('(:num)/assessments/(:num)/questions', 'Courses\Assessments\QuestionController::index/$2');
            $routes->get('(:num)/questions/(:num)', 'Courses\Assessments\QuestionController::show/$2');
            $routes->get('(:num)/grades', 'Courses\GradeController::show/$1');
            $routes->get('(:num)/grades/scheme', 'Courses\Grades\GradingSchemeController::show/$1');
            $routes->get('(:num)/grades/roster', 'Courses\Grades\GradingSchemeController::roster/$1');
            $routes->get('(:num)/grades/export', 'Courses\Grades\GradeSheetController::export/$1');
        });

        // The subscription, to read (institution.settings), and the SIS connection's runs (sis.run):
        // the connection's own settings and credentials stay with the system administrator.
        $routes->get('subscription', 'Institution\SubscriptionController::index', ['filter' => 'permission:institution.settings']);

        // The institution's own audit log, to read and export (institution.audit); no source addresses.
        $routes->get('audit', 'Institution\AuditController::index', ['filter' => 'permission:institution.audit']);
        $routes->get('audit/export', 'Institution\AuditController::export', ['filter' => 'permission:institution.audit']);
        $routes->group('integrations', ['filter' => 'permission:sis.run'], function ($routes) {
            $routes->get('/', 'Institution\IntegrationsController::index');
            $routes->post('preview', 'Institution\IntegrationsController::preview');
            $routes->post('run', 'Institution\IntegrationsController::run', ['filter' => 'reauth']);
            $routes->post('grades/preview', 'Institution\IntegrationsController::gradesPreview');
            $routes->post('grades/send', 'Institution\IntegrationsController::gradesSend', ['filter' => 'reauth']);
        });

        // Reports across the institution (reports.view): the page, a course, and CSV.
        $routes->group('reports', ['filter' => 'permission:reports.view'], function ($routes) {
            $routes->get('/', 'Institution\ReportsController::index');
            $routes->get('export/(:segment)', 'Institution\ReportsController::export/$1');
            $routes->get('offerings/(:num)', 'Institution\ReportsController::offering/$1');
        });

        // The institution's own settings: profile and branding (institution.settings).
        $routes->group('settings', ['filter' => 'permission:institution.settings'], function ($routes) {
            $routes->get('/', 'Institution\InstitutionSettingsController::profile');
            $routes->post('/', 'Institution\InstitutionSettingsController::saveProfile');
            $routes->get('branding', 'Institution\InstitutionSettingsController::branding');
            $routes->post('branding', 'Institution\InstitutionSettingsController::saveBranding');
            $routes->post('branding/reset', 'Institution\InstitutionSettingsController::resetBranding');
            $routes->post('logo', 'Institution\InstitutionSettingsController::uploadLogo');
            $routes->post('logo/remove', 'Institution\InstitutionSettingsController::removeLogo');
            $routes->get('academic', 'Institution\InstitutionSettingsController::academic');
            $routes->post('academic', 'Institution\InstitutionSettingsController::saveAcademic');
            $routes->post('academic/reset', 'Institution\InstitutionSettingsController::resetAcademic');
            $routes->get('announcement', 'Institution\InstitutionSettingsController::announcement');
            $routes->post('announcement', 'Institution\InstitutionSettingsController::saveAnnouncement');
            $routes->post('announcement/remove', 'Institution\InstitutionSettingsController::removeAnnouncement');
            // Security settings need the password (IA1): a loosened rule is refused, but a tightened one locks people out.
            $routes->get('security', 'Institution\InstitutionSettingsController::security');
            $routes->post('security', 'Institution\InstitutionSettingsController::saveSecurity', ['filter' => 'reauth']);
            $routes->post('security/reset', 'Institution\InstitutionSettingsController::resetSecurity', ['filter' => 'reauth']);
        });

        // The institution's people (people.view to look, people.manage to change; the
        // dangerous ones also need the password).
        $routes->group('people', function ($routes) {
            $routes->get('/', 'Institution\PeopleController::index', ['filter' => 'permission:people.view']);
            $routes->get('export', 'Institution\PeopleController::export', ['filter' => 'permission:people.view']);
            $routes->post('export', 'Institution\PeopleController::exportSelected', ['filter' => 'permission:people.view']);
            $routes->post('/', 'Institution\PeopleController::invite', ['filter' => 'permission:people.manage']);
            $routes->post('bulk/deactivate', 'Institution\PeopleController::bulkDeactivate', ['filter' => ['permission:people.manage', 'reauth']]);
            $routes->post('bulk/reactivate', 'Institution\PeopleController::bulkReactivate', ['filter' => 'permission:people.manage']);
            $routes->post('invitations/(:num)/resend', 'Institution\PeopleController::resend/$1', ['filter' => 'permission:people.manage']);
            $routes->post('invitations/(:num)/revoke', 'Institution\PeopleController::revoke/$1', ['filter' => 'permission:people.manage']);
            $routes->get('upload', 'Institution\CsvUploadController::index', ['filter' => 'permission:people.manage']);
            $routes->post('upload', 'Institution\CsvUploadController::store', ['filter' => 'permission:people.manage']);
            $routes->get('upload/template/(:segment)', 'Institution\CsvUploadController::template/$1', ['filter' => 'permission:people.manage']);
            $routes->get('upload/(:segment)', 'Institution\CsvUploadController::show/$1', ['filter' => 'permission:people.manage']);
            $routes->post('upload/(:segment)/apply', 'Institution\CsvUploadController::apply/$1', ['filter' => ['permission:people.manage', 'reauth']]);
            $routes->post('upload/(:segment)/discard', 'Institution\CsvUploadController::discard/$1', ['filter' => 'permission:people.manage']);
            $routes->get('parents', 'Institution\ParentLinksController::index', ['filter' => 'permission:people.view']);
            $routes->post('parents/(:num)/students', 'Institution\ParentLinksController::link/$1', ['filter' => 'permission:people.manage']);
            $routes->post('parents/links/(:num)/remove', 'Institution\ParentLinksController::unlink/$1', ['filter' => 'permission:people.manage']);
            $routes->post('(:num)', 'Institution\PeopleController::update/$1', ['filter' => 'permission:people.manage']);
            $routes->post('(:num)/deactivate', 'Institution\PeopleController::deactivate/$1', ['filter' => ['permission:people.manage', 'reauth']]);
            $routes->post('(:num)/reactivate', 'Institution\PeopleController::reactivate/$1', ['filter' => 'permission:people.manage']);
            $routes->post('(:num)/sign-out', 'Institution\PeopleController::signOut/$1', ['filter' => 'permission:people.manage']);
            $routes->post('(:num)/reset-password', 'Institution\PeopleController::resetPassword/$1', ['filter' => ['permission:people.manage', 'reauth']]);
            $routes->post('(:num)/roles', 'Institution\PeopleController::roles/$1', ['filter' => ['permission:people.manage', 'reauth']]);
            $routes->post('(:num)/remove', 'Institution\PeopleController::remove/$1', ['filter' => 'permission:people.manage']);
        });

        // The course catalog (structure.manage).
        $routes->group('courses', ['filter' => 'permission:structure.manage'], function ($routes) {
            $routes->get('/', 'Institution\CoursesController::index');
            $routes->post('/', 'Institution\CoursesController::create');
            $routes->post('(:num)', 'Institution\CoursesController::update/$1');
            $routes->post('(:num)/deactivate', 'Institution\CoursesController::deactivate/$1');
            $routes->post('(:num)/archive', 'Institution\CoursesController::archive/$1');
            $routes->post('(:num)/reactivate', 'Institution\CoursesController::reactivate/$1');
        });
    });
});

// System administrator area: platform-wide, above every institution.
// A form posted from a script (the password dialog) gets its redirect as JSON, so the message set for
// the next page is still there when the script goes to it (ajaxredirect).
$routes->group('admin', ['filter' => ['group:admin', 'ajaxredirect']], function ($routes) {
    $routes->get('/', 'Admin\HomeController::index');
    $routes->get('dashboard', 'Admin\HomeController::index');

    // Password confirmation in front of dangerous actions (the `reauth` filter).
    // Everyone else confirms at /reauth (above); the behaviour is shared.
    $routes->get('reauth', 'Admin\ReauthController::show');
    $routes->post('reauth', 'Admin\ReauthController::confirm');

    // Audit log: list, CSV export, and the source-address display setting.
    $routes->get('audit-log', 'Admin\AuditLogController::index');
    $routes->get('audit-log/export', 'Admin\AuditLogController::export');
    $routes->post('audit-log/settings', 'Admin\AuditLogController::updateSettings', ['filter' => 'reauth']);

    // Roles and permissions: view the matrix, change one cell (permission first, then password).
    $routes->get('roles', 'Admin\RolesController::index', ['filter' => 'permission:roles.view']);
    $routes->post('roles/permissions', 'Admin\RolesController::toggle', ['filter' => ['permission:roles.manage', 'reauth']]);

    // Institutions: look with institutions.view, change with institutions.manage; a restore also needs the password.
    $routes->get('institutions', 'Admin\InstitutionsController::index', ['filter' => 'permission:institutions.view']);
    $routes->get('institutions/new', 'Admin\InstitutionsController::new', ['filter' => 'permission:institutions.manage']);
    $routes->post('institutions', 'Admin\InstitutionsController::create', ['filter' => 'permission:institutions.manage']);
    $routes->get('institutions/(:num)/edit', 'Admin\InstitutionsController::edit/$1', ['filter' => 'permission:institutions.manage']);
    $routes->post('institutions/(:num)', 'Admin\InstitutionsController::update/$1', ['filter' => 'permission:institutions.manage']);
    $routes->post('institutions/(:num)/suspend', 'Admin\InstitutionsController::suspend/$1', ['filter' => 'permission:institutions.manage']);
    $routes->post('institutions/(:num)/delete', 'Admin\InstitutionsController::delete/$1', ['filter' => 'permission:institutions.manage']);
    $routes->post('institutions/(:num)/restore', 'Admin\InstitutionsController::restore/$1', ['filter' => ['permission:institutions.manage', 'reauth']]);
    $routes->post('institutions/(:num)/purge', 'Admin\InstitutionsController::purge/$1', ['filter' => ['permission:institutions.manage', 'reauth']]);

    // An institution's profile and its other tabs (subscription and features have their own routes).
    $routes->get('institutions/(:num)', 'Admin\InstitutionsController::show/$1', ['filter' => 'permission:institutions.view']);
    $routes->get('institutions/(:num)/audit', 'Admin\InstitutionsController::audit/$1', ['filter' => 'permission:audit.view']);
    $routes->get('institutions/(:num)/users', 'Admin\InstitutionPeopleController::index/$1', ['filter' => 'permission:users.view']);
    $routes->post('institutions/(:num)/invitations', 'Admin\InstitutionPeopleController::invite/$1', ['filter' => 'permission:users.manage']);
    $routes->post('institutions/(:num)/invitations/(:num)/resend', 'Admin\InstitutionPeopleController::resend/$1/$2', ['filter' => 'permission:users.manage']);
    $routes->post('institutions/(:num)/invitations/(:num)/revoke', 'Admin\InstitutionPeopleController::revoke/$1/$2', ['filter' => 'permission:users.manage']);

    // Onboarding a new institution: its plan and first subscription, then its first administrator. Nothing is saved until the last step.
    $routes->get('institutions/(:num)/onboarding', 'Admin\OnboardingController::index/$1', ['filter' => 'permission:institutions.manage']);
    $routes->get('institutions/(:num)/onboarding/subscription', 'Admin\OnboardingController::subscription/$1', ['filter' => 'permission:subscriptions.manage']);
    $routes->post('institutions/(:num)/onboarding/subscription', 'Admin\OnboardingController::saveSubscription/$1', ['filter' => 'permission:subscriptions.manage']);
    $routes->get('institutions/(:num)/onboarding/administrator', 'Admin\OnboardingController::administrator/$1', ['filter' => 'permission:institutions.manage']);
    $routes->post('institutions/(:num)/onboarding/administrator', 'Admin\OnboardingController::finish/$1', ['filter' => 'permission:institutions.manage']);

    // An institution's subscription: look with subscriptions.view, change with subscriptions.manage.
    $routes->get('institutions/(:num)/subscription', 'Admin\SubscriptionsController::show/$1', ['filter' => 'permission:subscriptions.view']);
    $routes->post('institutions/(:num)/subscription', 'Admin\SubscriptionsController::start/$1', ['filter' => 'permission:subscriptions.manage']);
    $routes->post('institutions/(:num)/subscription/renew', 'Admin\SubscriptionsController::renew/$1', ['filter' => 'permission:subscriptions.manage']);
    $routes->post('institutions/(:num)/subscription/renewals/(:num)/correct', 'Admin\SubscriptionsController::correct/$1/$2', ['filter' => 'permission:subscriptions.manage']);

    // Feature flags: look with flags.view, change with flags.manage. A change for everyone (including the kill switch) also needs the password.
    $routes->get('flags', 'Admin\FlagsController::index', ['filter' => 'permission:flags.view']);
    $routes->post('flags/(:segment)/global', 'Admin\FlagsController::setGlobal/$1', ['filter' => ['permission:flags.manage', 'reauth']]);
    $routes->get('institutions/(:num)/flags', 'Admin\FlagsController::institution/$1', ['filter' => 'permission:flags.view']);
    $routes->post('institutions/(:num)/flags/(:segment)', 'Admin\FlagsController::setForInstitution/$1/$2', ['filter' => 'permission:flags.manage']);

    // Student information system connection, per institution: look with integrations.view; saving
    // (which can replace credentials) also needs the password; testing only reads.
    $routes->get('institutions/(:num)/integrations', 'Admin\IntegrationsController::show/$1', ['filter' => 'permission:integrations.view']);
    $routes->post('institutions/(:num)/integrations', 'Admin\IntegrationsController::save/$1', ['filter' => ['permission:integrations.manage', 'reauth']]);
    $routes->post('institutions/(:num)/integrations/test', 'Admin\IntegrationsController::test/$1', ['filter' => 'permission:integrations.manage']);
    // A preview changes nothing; a real import writes people, courses and enrolments, so it needs the password.
    $routes->post('institutions/(:num)/integrations/preview', 'Admin\IntegrationsController::preview/$1', ['filter' => 'permission:integrations.manage']);
    $routes->post('institutions/(:num)/integrations/run', 'Admin\IntegrationsController::run/$1', ['filter' => ['permission:integrations.manage', 'reauth']]);
    // Final grades out: a preview sends nothing; sending hands data to another system, so it needs the password.
    $routes->post('institutions/(:num)/integrations/grades/preview', 'Admin\IntegrationsController::gradesPreview/$1', ['filter' => 'permission:integrations.manage']);
    $routes->post('institutions/(:num)/integrations/grades/send', 'Admin\IntegrationsController::gradesSend/$1', ['filter' => ['permission:integrations.manage', 'reauth']]);

    // People across every institution: look and export with users.view, change with users.manage.
    // Changes that decide who can get in or who someone is (name and email, password reset, roles,
    // institutions) also need the password.
    $routes->get('users', 'Admin\UsersController::index', ['filter' => 'permission:users.view']);
    $routes->get('users/export', 'Admin\UsersController::export', ['filter' => 'permission:users.view']);
    $routes->post('users/export', 'Admin\UsersController::exportSelected', ['filter' => 'permission:users.view']);
    $routes->get('users/new', 'Admin\UsersController::new', ['filter' => 'permission:users.manage']);
    $routes->post('users', 'Admin\UsersController::invite', ['filter' => 'permission:users.manage']);
    $routes->post('users/bulk', 'Admin\UsersController::bulk', ['filter' => 'permission:users.manage']);
    $routes->get('users/(:num)', 'Admin\UsersController::show/$1', ['filter' => 'permission:users.view']);
    $routes->post('users/(:num)', 'Admin\UsersController::update/$1', ['filter' => ['permission:users.manage', 'reauth']]);
    $routes->post('users/(:num)/impersonate', 'Admin\ImpersonationController::start/$1', ['filter' => ['permission:impersonation.start', 'reauth']]);
    $routes->post('users/(:num)/deactivate', 'Admin\UsersController::deactivate/$1', ['filter' => 'permission:users.manage']);
    $routes->post('users/(:num)/reactivate', 'Admin\UsersController::reactivate/$1', ['filter' => 'permission:users.manage']);
    $routes->post('users/(:num)/sign-out', 'Admin\UsersController::signOut/$1', ['filter' => 'permission:users.manage']);
    $routes->post('users/(:num)/reset-password', 'Admin\UsersController::resetPassword/$1', ['filter' => ['permission:users.manage', 'reauth']]);
    $routes->post('users/(:num)/roles', 'Admin\UsersController::roles/$1', ['filter' => ['permission:users.manage', 'reauth']]);
    $routes->post('users/(:num)/memberships', 'Admin\UsersController::addMembership/$1', ['filter' => ['permission:users.manage', 'reauth']]);
    $routes->post('users/(:num)/memberships/(:num)/remove', 'Admin\UsersController::removeMembership/$1/$2', ['filter' => ['permission:users.manage', 'reauth']]);

    // General settings: look with settings.view; saving needs settings.manage and the password.
    $routes->get('settings/general', 'Admin\GeneralSettingsController::index', ['filter' => 'permission:settings.view']);
    $routes->post('settings/general', 'Admin\GeneralSettingsController::update', ['filter' => ['permission:settings.manage', 'reauth']]);

    // Security settings: look with settings.view; saving needs settings.manage and the password.
    $routes->get('settings/security', 'Admin\SecuritySettingsController::index', ['filter' => 'permission:settings.view']);
    $routes->post('settings/security', 'Admin\SecuritySettingsController::update', ['filter' => ['permission:settings.manage', 'reauth']]);

    // System status: health, platform counts, subscription watch and usage against plans (read-only).
    $routes->get('monitoring', 'Admin\MonitoringController::index', ['filter' => 'permission:monitoring.view']);

    // Backups: history and alert with backup.view; asking for one needs backup.manage and the password.
    $routes->get('backups', 'Admin\BackupController::index', ['filter' => 'permission:backup.view']);
    $routes->post('backups', 'Admin\BackupController::request', ['filter' => ['permission:backup.manage', 'reauth']]);

    // Archive: past and deleted institutions, with restore and purge.
    $routes->get('archive', 'Admin\ArchiveController::index', ['filter' => 'permission:institutions.view']);

    // Plans: look with subscriptions.view, change with subscriptions.manage; editing, retiring and reinstating also need the password.
    $routes->get('plans', 'Admin\PlansController::index', ['filter' => 'permission:subscriptions.view']);
    $routes->get('plans/new', 'Admin\PlansController::new', ['filter' => 'permission:subscriptions.manage']);
    $routes->post('plans', 'Admin\PlansController::create', ['filter' => 'permission:subscriptions.manage']);
    $routes->get('plans/(:num)/edit', 'Admin\PlansController::edit/$1', ['filter' => ['permission:subscriptions.manage', 'reauth']]);
    $routes->post('plans/(:num)', 'Admin\PlansController::update/$1', ['filter' => ['permission:subscriptions.manage', 'reauth']]);
    $routes->post('plans/(:num)/retire', 'Admin\PlansController::retire/$1', ['filter' => ['permission:subscriptions.manage', 'reauth']]);
    $routes->post('plans/(:num)/reinstate', 'Admin\PlansController::reinstate/$1', ['filter' => ['permission:subscriptions.manage', 'reauth']]);
});
