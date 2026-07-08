<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Auth\LoginController::index');


service('auth')->routes($routes);


$routes->group('student', ['filter' => 'group:student'], function ($routes) {

    $routes->get('dashboard', 'Dashboard\DashboardController::student');

});

$routes->group('instructor', ['filter' => 'group:instructor'], function ($routes) {

    $routes->get('dashboard', 'Dashboard\DashboardController::instructor');

});