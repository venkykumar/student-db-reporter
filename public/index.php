<?php

declare(strict_types=1);

/*
 * ---------------------------------------------------------------
 * CHECK PHP VERSION
 * ---------------------------------------------------------------
 */
$minPhpVersion = '8.1'; // CI4 requirement
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    $message = sprintf(
        'Your PHP version must be %s or higher to run CodeIgniter 4. Current version: %s',
        $minPhpVersion,
        PHP_VERSION
    );
    header('HTTP/1.1 503 Service Unavailable.', true, 503);
    echo $message;
    exit(1);
}

/*
 * ---------------------------------------------------------------
 * SET THE CURRENT DIRECTORY
 * ---------------------------------------------------------------
 */
// Ensure the current directory is pointing to the front controller's directory
if (getcwd() . DIRECTORY_SEPARATOR !== __DIR__ . DIRECTORY_SEPARATOR) {
    chdir(__DIR__);
}

/*
 * ---------------------------------------------------------------
 * BOOTSTRAP THE APPLICATION
 * ---------------------------------------------------------------
 */
// Path to the front controller (this file)
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);

// Path to the system folder
$pathsConfig = FCPATH . '../app/Config/Paths.php';
// Ensure the paths config file can be found.
if (! is_file($pathsConfig)) {
    // CodeIgniter < 4.1.9 compatibility
    $pathsConfig = FCPATH . '../application/Config/Paths.php';
}

require $pathsConfig;
$paths = new Config\Paths();

// LOAD THE FRAMEWORK BOOTSTRAP FILE
require $paths->systemDirectory . '/Boot.php';
exit(CodeIgniter\Boot::bootWeb($paths));
