<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

// Allow mocking of final classes for unit tests (PHPUnit 11+)
// dg/BypassFinals is no longer needed - PHPUnit handles this natively
// dg\BypassFinals::enable();


// Ensure test env
putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';

// Create cache/log dirs for phpunit if not present
$var = dirname(__DIR__).'/var';
@mkdir($var.'/.phpunit.cache', 0777, true);
@mkdir($var.'/log', 0777, true);
@mkdir($var.'/uploads', 0777, true);
