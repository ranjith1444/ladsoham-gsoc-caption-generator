<?php

/**
 * Test script to check service availability.
 * 
 * Usage: drush php-script test_service.php
 */

// Load Drupal
require_once DRUPAL_ROOT . '/autoload.php';
$kernel = new \Drupal\Core\DrupalKernel('prod', \Drupal\Core\Site\Settings::getInstance(), FALSE);
$kernel->boot();
$kernel->preHandle();

echo "=== Service Availability Test ===\n\n";

// Test 1: Check if services are available
echo "1. Testing service availability...\n";

$services_to_test = [
    'caption_generator.python_api',
    'caption_generator.caption_service',
];

foreach ($services_to_test as $service_name) {
    try {
        $service = \Drupal::service($service_name);
        echo "   $service_name: AVAILABLE\n";
        echo "   Class: " . get_class($service) . "\n";
    } catch (\Exception $e) {
        echo "   $service_name: ERROR - " . $e->getMessage() . "\n";
    }
}

echo "\n2. Testing direct service call...\n";
try {
    $python_api = \Drupal::service('caption_generator.python_api');
    echo "   PythonApiService loaded successfully\n";
    
    // Test if the service has the expected method
    if (method_exists($python_api, 'generateCaption')) {
        echo "   generateCaption method exists\n";
    } else {
        echo "   generateCaption method NOT found\n";
    }
    
} catch (\Exception $e) {
    echo "   Error loading PythonApiService: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n"; 