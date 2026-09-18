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
        $routes->get('(:num)/assessments', 'Courses\AssessmentController::show/$1');
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
        $routes->post('(:num)/modules/items/(:num)/video', 'Courses\ModuleController::updateVideoItem/$1/$2');
        $routes->patch('(:num)/modules/items/reorder', 'Courses\ModuleController::reorderLearningItems/$1');

        $routes->get('(:num)/resources', 'Courses\ResourceController::show/$1');
        $routes->get('(:num)/discussions', 'Courses\DiscussionController::show/$1');
        $routes->get('(:num)/assessments', 'Courses\AssessmentController::show/$1');
        $routes->get('(:num)/grades', 'Courses\GradeController::show/$1');
    });
});