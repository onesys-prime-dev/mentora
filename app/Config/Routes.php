<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Auth\RedirectController::index');

service('auth')->routes($routes);

$routes->group('student', ['filter' => 'group:student'], function ($routes) {
    $routes->get('dashboard', 'Dashboard\DashboardController::index');

    $routes->get('courses', 'Courses\CourseController::index');
    $routes->get('courses/(:num)/(:segment)', 'Courses\CourseController::view/$1/$2');
    $routes->get('courses/(:num)/(:segment)/(:segment)', 'Courses\CourseController::view/$1/$2/$3');
    
    $routes->get('api/courses/(:num)/tabs/(:segment)', 'Courses\CourseController::tab/$1/$2');
});

$routes->group('instructor', ['filter' => 'group:instructor'], function ($routes) {
    $routes->get('dashboard', 'Dashboard\DashboardController::index');
});