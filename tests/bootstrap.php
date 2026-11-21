<?php
/**
 * PHPUnit bootstrap file for AltRelay tests.
 *
 * @package AltRelay
 * @author  Julian Witznick
 */

// Composer autoloader.
require_once dirname(__DIR__) . '/vendor/autoload.php';

// The real WordPress HTML API, so HTML rewriting is tested against the
// same code that runs in production.
require_once __DIR__ . '/fixtures/load-html-api.php';

// WordPress function stubs for unit tests.
require_once __DIR__ . '/stubs/wp-stubs.php';

// Load the main plugin file (defines constants, loads classes, registers hooks).
require_once dirname(__DIR__) . '/altrelay.php';
