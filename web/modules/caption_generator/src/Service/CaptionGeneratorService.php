<?php

namespace Drupal\caption_generator\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\File\FileSystemInterface;

/**
 * Service for generating captions using embedded Python script.
 */
class CaptionGeneratorService {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a new CaptionGeneratorService object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    FileSystemInterface $file_system
  ) {
    $this->loggerFactory = $logger_factory;
    $this->fileSystem = $file_system;
  }

  /**
   * Generate caption using embedded Python script.
   *
   * @param string $image_path
   *   The path to the image file.
   * @param int $length
   *   The maximum length for the caption.
   *
   * @return string
   *   The generated caption or error message.
   */
  public function generateCaption($image_path, $length = 50) {
    $logger = $this->loggerFactory->get('caption_generator');

    try {
      // Get the module directory
      $module_path = \Drupal::service('extension.list.module')->getPath('caption_generator');
      $python_script = $module_path . '/caption_generator_python.py';

      // Check if Python script exists
      if (!file_exists($python_script)) {
        $logger->error('Python script not found at @path', ['@path' => $python_script]);
        return 'Error: Python script not found.';
      }

      // Make script executable
      chmod($python_script, 0755);

      // Validate image path
      if (!file_exists($image_path)) {
        $logger->error('Image file not found: @path', ['@path' => $image_path]);
        return 'Error: Image file not found.';
      }

      // Execute Python script with proper working directory
      $command = sprintf(
        'cd %s && python3 %s %s %d 2>&1',
        escapeshellarg($module_path),
        escapeshellarg($python_script),
        escapeshellarg($image_path),
        $length
      );

      $logger->info('Executing command: @command', ['@command' => $command]);

      $output = shell_exec($command);
      
      if (!$output) {
        $logger->error('Python script returned no output');
        return 'Error: No output from Python script.';
      }

      // Parse JSON response
      $result = json_decode($output, true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        $logger->error('Failed to parse JSON output: @output', ['@output' => $output]);
        return 'Error: Invalid response from Python script.';
      }

      if (isset($result['error'])) {
        $logger->error('Python script error: @error', ['@error' => $result['error']]);
        return 'Error: ' . $result['error'];
      }

      if (isset($result['enhanced_caption'])) {
        $logger->info('Successfully generated caption: @caption', ['@caption' => $result['enhanced_caption']]);
        return $result['enhanced_caption'];
      }

      $logger->error('Unexpected response format: @result', ['@result' => print_r($result, TRUE)]);
      return 'Error: Unexpected response format.';

    } catch (\Exception $e) {
      $logger->error('Caption generation failed: @error', ['@error' => $e->getMessage()]);
      return 'Error: ' . $e->getMessage();
    }
  }

  /**
   * Check if Python environment is available.
   *
   * @return bool
   *   TRUE if Python is available, FALSE otherwise.
   */
  public function isPythonAvailable() {
    $output = shell_exec('python3 --version 2>&1');
    return !empty($output) && strpos($output, 'Python') !== false;
  }

  /**
   * Check if required Python packages are available.
   *
   * @return array
   *   Array with status information.
   */
  public function checkPythonEnvironment() {
    $status = [
      'python_available' => $this->isPythonAvailable(),
      'api_directory_exists' => false,
      'required_files_exist' => [],
    ];

    if ($status['python_available']) {
      // Check if python directory exists
      $python_dir = DRUPAL_ROOT . '/../python';
      $status['api_directory_exists'] = file_exists($python_dir);

      if ($status['api_directory_exists']) {
        // Check required files
        $required_files = ['vision.py', 'caption.py', 'enhancer.py'];
        foreach ($required_files as $file) {
          $status['required_files_exist'][$file] = file_exists($python_dir . '/' . $file);
        }
      }
    }

    return $status;
  }

} 