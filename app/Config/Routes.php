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

// Every uploaded file (videos, PDFs, submitted work, AI sources) is served
// here; access is decided per file by FileAccessService.
$routes->get('files/(:num)', 'FileController::show/$1', ['filter' => 'session']);

$routes->group('student', ['filter' => ['role:student', 'readonly']], function ($routes) {
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

    $routes->group('api/courses', ['filter' => 'assessmentscope'], function ($routes) {
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

    $routes->group('api/courses', ['filter' => 'assessmentscope'], function ($routes) {
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

$routes->group('chairperson', ['filter' => 'role:chairperson'], function ($routes) {
    $routes->get('/', 'Dashboard\DashboardController::index');
    $routes->get('dashboard', 'Dashboard\DashboardController::index');
});

// System administrator area: platform-wide, above every institution.
$routes->group('admin', ['filter' => 'group:admin'], function ($routes) {
    $routes->get('/', 'Admin\HomeController::index');
    $routes->get('dashboard', 'Admin\HomeController::index');

    // Password confirmation in front of dangerous actions (the `reauth` filter).
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
