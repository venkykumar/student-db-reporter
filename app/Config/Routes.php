<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->setDefaultNamespace('App\Controllers');
$routes->setDefaultController('Dashboard');
$routes->setDefaultMethod('index');
$routes->setTranslateURIDashes(false);
$routes->set404Override();
$routes->setAutoRoute(false);

// Dashboard
$routes->get('/',          'Dashboard::index');
$routes->get('/dashboard', 'Dashboard::index');

// Reports
$routes->get('/reports',                  'Reports::index');
$routes->get('/reports/pdf/(:segment)',   'Reports::exportPdf/$1');
$routes->get('/reports/(:segment)',       'Reports::view/$1');

// Analysis
$routes->get('/analysis',           'Analysis::index');
$routes->post('/analysis/run',      'Analysis::run');
$routes->post('/analysis/regenerate', 'Analysis::regenerate');

// Students (typeahead search for per-student reports)
$routes->get('/students/search', 'Students::search');
