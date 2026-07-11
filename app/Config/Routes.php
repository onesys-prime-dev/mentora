<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Auth\RedirectController::index');

service('auth')->routes($routes);

$routes->group('student', ['filter' => 'group:student'], function ($routes) {
    $routes->get('dashboard', 'Dashboard\DashboardController::index');

    $routes->get('courses', 'Courses\CourseController::index');
    $routes->get('courses/(:num)/(:segment)', 'Courses\CourseController::redirect/$1/$2');
    $routes->get('courses/(:num)/(:segment)/(:segment)', 'Courses\CourseController::view/$1/$2/$3');

    $routes->group('api/courses', function ($routes) {
        $routes->get('(:num)/home', 'Courses\HomeController::show/$1');
        $routes->get('(:num)/modules', 'Courses\ModuleController::show/$1');
        $routes->get('(:num)/resources', 'Courses\ResourceController::show/$1');
        $routes->get('(:num)/discussions', 'Courses\DiscussionController::show/$1');
        $routes->get('(:num)/assessments', 'Courses\AssessmentController::show/$1');
        $routes->get('(:num)/grade', 'Courses\GradeController::show/$1');
    });
});

$routes->group('instructor', ['filter' => 'group:instructor'], function ($routes) {
    $routes->get('dashboard', 'Dashboard\DashboardController::index');
});