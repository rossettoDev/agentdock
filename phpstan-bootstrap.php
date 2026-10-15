<?php

/**
 * @file
 * PHPStan bootstrap file.
 */

// Define DRUPAL_ROOT if not already defined.
if (!defined('DRUPAL_ROOT')) {
  define('DRUPAL_ROOT', __DIR__ . '/web');
}

// Ensure autoloader is available.
require_once __DIR__ . '/vendor/autoload.php';
