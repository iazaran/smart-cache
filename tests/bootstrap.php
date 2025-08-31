<?php

/*
 * Bootstrap file for SmartCache package tests
 * This sets up the testing environment without requiring a full Laravel installation
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Set error reporting
error_reporting(E_ALL);

// Set timezone
date_default_timezone_set('UTC');

// Set memory limit for tests
ini_set('memory_limit', '1G');

// Create necessary directories for testing
$testDirs = [
    __DIR__ . '/storage',
    __DIR__ . '/storage/app',
    __DIR__ . '/storage/framework',
    __DIR__ . '/storage/framework/cache',
    __DIR__ . '/storage/framework/sessions',
    __DIR__ . '/storage/framework/views',
    __DIR__ . '/storage/logs',
];

foreach ($testDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Clean up test storage on each run
$cleanupDirs = [
    __DIR__ . '/storage/framework/cache',
    __DIR__ . '/storage/logs',
];

foreach ($cleanupDirs as $dir) {
    if (is_dir($dir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
    }
}
