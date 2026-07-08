<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Auth\RedirectController::index');

service('auth')->routes($routes);

$routes->group('student', ['filter' => 'group:student'], function ($routes) {
    $routes->get('dashboard', 'Dashboard\DashboardController::index');
    $routes->get('courses', 'Courses\CourseController::index');
});

$routes->group('instructor', ['filter' => 'group:instructor'], function ($routes) {
    $routes->get('dashboard', 'Dashboard\DashboardController::index');
});