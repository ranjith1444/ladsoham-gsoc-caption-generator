<?php

namespace Drupal\caption_generator\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for integrating with Python caption generation API.
 */
class PythonApiService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new PythonApiService object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    FileSystemInterface $file_system,
    ConfigFactoryInterface $config_factory
  ) {
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
    $this->fileSystem = $file_system;
    $this->configFactory = $config_factory;
  }

  /**
   * Generate caption using Python API.
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
      // Method 1: Try HTTP API first (since user confirmed it works in Postman)
      $result = $this->callHttpApi($image_path, $length);
      if ($result !== false) {
        return $result;
      }

      // Method 2: Try shell command approach with curl
      $result = $this->callPythonViaShell($image_path, $length);
      if ($result !== false) {
        return $result;
      }

      // Method 3: Try standalone Python script
      $result = $this->callStandalonePython($image_path, $length);
      if ($result !== false) {
        return $result;
      }

      // Method 4: Try direct Python execution with FastAPI
      $result = $this->callPythonDirectly($image_path, $length);
      if ($result !== false) {
        return $result;
      }

      return 'All caption generation methods failed. Please check the logs for details.';

    } catch (\Exception $e) {
      $logger->error('Caption generation failed: @error', ['@error' => $e->getMessage()]);
      return 'Error: ' . $e->getMessage();
    }
  }

  /**
   * Helper to read config values.
   */
  protected function getSettings(): array {
    $config = $this->configFactory->get('caption_generator.settings');
    return [
      'gemini_api_key' => (string) ($config->get('gemini_api_key') ?? ''),
      'min_len' => (int) ($config->get('min_len') ?? 30),
      'max_len' => (int) ($config->get('max_len') ?? 60),
      'add_emojis' => (bool) ($config->get('add_emojis') ?? FALSE),
    ];
  }

  /**
   * Call standalone Python script directly.
   */
  protected function callStandalonePython($image_path, $length) {
    $logger = $this->loggerFactory->get('caption_generator');
    $settings = $this->getSettings();
    
    try {
      $api_dir = DRUPAL_ROOT . '/../caption-generating-api';
      $standalone_script = $api_dir . '/standalone_caption.py';
      
      if (!file_exists($standalone_script)) {
        $logger->warning('Standalone Python script not found at @path', ['@path' => $standalone_script]);
        return false;
      }

      // Make script executable
      chmod($standalone_script, 0755);

      // Execute the standalone script. This path does not currently support
      // min/max/emojis/API key. Keeping for legacy fallback.
      $command = sprintf(
        'cd %s && python3 %s "%s" %d 2>&1',
        escapeshellarg($api_dir),
        escapeshellarg($standalone_script),
        escapeshellarg($image_path),
        $length
      );

      $output = shell_exec($command);
      
      if ($output && !strpos($output, 'Error:')) {
        return trim($output);
      }

      $logger->warning('Standalone Python call failed: @output', ['@output' => $output]);
      return false;

    } catch (\Exception $e) {
      $logger->error('Standalone Python execution error: @error', ['@error' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * Call Python directly using FastAPI TestClient.
   */
  protected function callPythonDirectly($image_path, $length) {
    $logger = $this->loggerFactory->get('caption_generator');
    $settings = $this->getSettings();
    
    try {
      $api_dir = DRUPAL_ROOT . '/../caption-generating-api';
      
      if (!file_exists($api_dir)) {
        $logger->warning('Python API directory not found at @path', ['@path' => $api_dir]);
        return false;
      }

      // Prepare dynamic values for the Python script.
      $addEmojisStr = $settings['add_emojis'] ? 'true' : 'false';
      $geminiEsc = str_replace("'", "\\'", (string) $settings['gemini_api_key']);

      // Create a simple Python script
      $script = "
import sys
import os
sys.path.insert(0, '{$api_dir}')

try:
    from api import app
    from fastapi.testclient import TestClient
    
    client = TestClient(app)
    
    with open('{$image_path}', 'rb') as f:
        files = {'image': ('image.jpg', f, 'image/jpeg')}
        data = {
            'length': {$length},
            'min_len': {$settings['min_len']},
            'max_len': {$settings['max_len']},
            'add_emojis': '{$addEmojisStr}',
            'gemini_api_key': '{$geminiEsc}'
        }
        
        response = client.post('/generate', files=files, data=data)
        
        if response.status_code == 200:
            result = response.json()
            print(result.get('enhanced_caption', 'No caption generated'))
        else:
            print('API Error: ' + str(response.status_code))
            
except Exception as e:
    print('Python Error: ' + str(e))
";

      $temp_file = tempnam(sys_get_temp_dir(), 'caption_') . '.py';
      file_put_contents($temp_file, $script);

      $command = "cd {$api_dir} && python3 {$temp_file} 2>&1";
      $output = shell_exec($command);
      
      unlink($temp_file);

      if ($output && !strpos($output, 'Error:')) {
        return trim($output);
      }

      $logger->warning('Direct Python call failed: @output', ['@output' => $output]);
      return false;

    } catch (\Exception $e) {
      $logger->error('Direct Python execution error: @error', ['@error' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * Call Python API via HTTP.
   */
  protected function callHttpApi($image_path, $length) {
    $logger = $this->loggerFactory->get('caption_generator');
    $settings = $this->getSettings();
    
    $endpoints = [
      'http://localhost:8000/generate',
      'http://127.0.0.1:8000/generate',
      'http://0.0.0.0:8000/generate',
    ];

    foreach ($endpoints as $endpoint) {
      try {
        $logger->info('Trying HTTP endpoint: @endpoint', ['@endpoint' => $endpoint]);

        // Check if file exists and is readable
        if (!file_exists($image_path) || !is_readable($image_path)) {
          $logger->error('Image file not accessible: @path', ['@path' => $image_path]);
          continue;
        }

        $multipart = [
          [
            'name' => 'image',
            'contents' => fopen($image_path, 'r'),
            'filename' => basename($image_path),
          ],
          [
            'name' => 'length',
            'contents' => (string) $length,
          ],
          [
            'name' => 'min_len',
            'contents' => (string) $settings['min_len'],
          ],
          [
            'name' => 'max_len',
            'contents' => (string) $settings['max_len'],
          ],
          [
            'name' => 'add_emojis',
            'contents' => $settings['add_emojis'] ? 'true' : 'false',
          ],
        ];

        if (!empty($settings['gemini_api_key'])) {
          $multipart[] = [
            'name' => 'gemini_api_key',
            'contents' => $settings['gemini_api_key'],
          ];
        }

        $response = $this->httpClient->request('POST', $endpoint, [
          'multipart' => $multipart,
          'timeout' => 30,
        ]);

        $response_body = $response->getBody()->getContents();
        $logger->info('HTTP response: @response', ['@response' => $response_body]);
        
        $data = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
          $logger->warning('Failed to parse JSON response from @endpoint: @error', [
            '@endpoint' => $endpoint,
            '@error' => json_last_error_msg(),
          ]);
          continue;
        }
        
        if (isset($data['enhanced_caption'])) {
          $logger->info('Successfully generated caption via HTTP API: @caption', [
            '@caption' => $data['enhanced_caption'],
          ]);
          return $data['enhanced_caption'];
        }

        $logger->warning('No enhanced_caption in response from @endpoint: @data', [
          '@endpoint' => $endpoint,
          '@data' => print_r($data, TRUE),
        ]);

      } catch (RequestException $e) {
        $logger->warning('Failed to connect to @endpoint: @error', [
          '@endpoint' => $endpoint,
          '@error' => $e->getMessage(),
        ]);
        continue;
      } catch (\Exception $e) {
        $logger->error('Unexpected error calling @endpoint: @error', [
          '@endpoint' => $endpoint,
          '@error' => $e->getMessage(),
        ]);
        continue;
      }
    }

    return false;
  }

  /**
   * Call Python via shell command with curl.
   */
  protected function callPythonViaShell($image_path, $length) {
    $logger = $this->loggerFactory->get('caption_generator');
    $settings = $this->getSettings();
    
    try {
      $endpoints = [
        'http://localhost:8000/generate',
        'http://127.0.0.1:8000/generate',
      ];

      foreach ($endpoints as $endpoint) {
        $curl_command = sprintf(
          'curl --location "%s" --form "length=%d" --form "min_len=%d" --form "max_len=%d" --form "add_emojis=%s" %s --form "image=@%s" --max-time 30 2>/dev/null',
          $endpoint,
          (int) $length,
          (int) $settings['min_len'],
          (int) $settings['max_len'],
          $settings['add_emojis'] ? 'true' : 'false',
          !empty($settings['gemini_api_key']) ? sprintf('--form "gemini_api_key=%s"', escapeshellarg($settings['gemini_api_key'])) : '',
          $image_path
        );

        $output = shell_exec($curl_command);
        
        if ($output) {
          $data = json_decode($output, true);
          if (isset($data['enhanced_caption'])) {
            return $data['enhanced_caption'];
          }
        }
      }

      return false;

    } catch (\Exception $e) {
      $logger->error('Shell curl call failed: @error', ['@error' => $e->getMessage()]);
      return false;
    }
  }

} 