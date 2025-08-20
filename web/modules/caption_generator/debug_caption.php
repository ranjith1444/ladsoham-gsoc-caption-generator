<?php

/**
 * Debug script to test caption generation functionality.
 * 
 * Usage: drush php-script debug_caption.php
 */

// Load Drupal
require_once DRUPAL_ROOT . '/autoload.php';
$kernel = new \Drupal\Core\DrupalKernel('prod', \Drupal\Core\Site\Settings::getInstance(), FALSE);
$kernel->boot();
$kernel->preHandle();

echo "=== Caption Generation Debug Test ===\n\n";

// Test 1: Check if services are available
echo "1. Checking service availability...\n";
try {
    $python_api_service = \Drupal::service('caption_generator.python_api');
    echo "   PythonApiService: AVAILABLE\n";
} catch (\Exception $e) {
    echo "   PythonApiService: ERROR - " . $e->getMessage() . "\n";
}

try {
    $caption_service = \Drupal::service('caption_generator.caption_service');
    echo "   CaptionGeneratorService: AVAILABLE\n";
} catch (\Exception $e) {
    echo "   CaptionGeneratorService: ERROR - " . $e->getMessage() . "\n";
    echo "   Note: This service might not be needed if PythonApiService is working.\n";
}

// Test 2: Check Python environment
echo "\n2. Checking Python environment...\n";
$python_available = shell_exec('python3 --version 2>&1');
echo "   Python version: " . trim($python_available) . "\n";

// Test 3: Check if Python API is running
echo "\n3. Checking Python API availability...\n";
$endpoints = [
    'http://localhost:8000/generate',
    'http://127.0.0.1:8000/generate',
];

foreach ($endpoints as $endpoint) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "   $endpoint: HTTP $http_code\n";
}

// Test 4: Test with a sample image (if available)
echo "\n4. Testing with sample image...\n";
$sample_image = DRUPAL_ROOT . '/../python/temp_uploads/sample.jpg';
if (file_exists($sample_image)) {
    echo "   Sample image found: $sample_image\n";
    
    try {
        $python_api_service = \Drupal::service('caption_generator.python_api');
        $caption = $python_api_service->generateCaption($sample_image, 50);
        echo "   Generated caption: $caption\n";
    } catch (\Exception $e) {
        echo "   Error generating caption: " . $e->getMessage() . "\n";
    }
} else {
    echo "   No sample image found at: $sample_image\n";
    echo "   Please upload an image to test with.\n";
}

echo "\n=== Debug Test Complete ===\n"; 