<?php

/**
 * Test script to verify Python environment within Drupal.
 * 
 * Usage: drush php-script test_python_env.php
 */

// Load Drupal
require_once DRUPAL_ROOT . '/autoload.php';
$kernel = new \Drupal\Core\DrupalKernel('prod', \Drupal\Core\Site\Settings::getInstance(), FALSE);
$kernel->boot();
$kernel->preHandle();

// Get the caption service
$caption_service = \Drupal::service('caption_generator.caption_service');

echo "=== Python Environment Test ===\n\n";

// Test 1: Check if Python is available
echo "1. Checking Python availability...\n";
$python_available = $caption_service->isPythonAvailable();
echo "   Python available: " . ($python_available ? "YES" : "NO") . "\n\n";

// Test 2: Check Python environment
echo "2. Checking Python environment...\n";
$env_status = $caption_service->checkPythonEnvironment();
echo "   API directory exists: " . ($env_status['api_directory_exists'] ? "YES" : "NO") . "\n";
echo "   Required files:\n";
foreach ($env_status['required_files_exist'] as $file => $exists) {
    echo "     - $file: " . ($exists ? "YES" : "NO") . "\n";
}
echo "\n";

// Test 3: Test Python script execution
echo "3. Testing Python script execution...\n";
$module_path = \Drupal::service('extension.list.module')->getPath('caption_generator');
$python_script = $module_path . '/caption_generator_python.py';

if (file_exists($python_script)) {
    echo "   Python script found: YES\n";
    
    // Make it executable
    chmod($python_script, 0755);
    
    // Test with help
    $command = "cd " . escapeshellarg($module_path) . " && python3 " . escapeshellarg($python_script) . " 2>&1";
    $output = shell_exec($command);
    echo "   Test output: " . trim($output) . "\n";
} else {
    echo "   Python script found: NO\n";
}

echo "\n=== Test Complete ===\n"; 