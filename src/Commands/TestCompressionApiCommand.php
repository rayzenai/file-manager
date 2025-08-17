<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kirantimsina\FileManager\Services\ImageCompressionService;

class TestCompressionApiCommand extends Command
{
    protected $signature = 'file-manager:test-api {--bg-remove : Test background removal} {--file= : Path to test file}';

    protected $description = 'Test the compression API endpoint and background removal';

    public function handle(): int
    {
        $this->info('🔍 Testing Compression API...');
        $this->newLine();

        // Get test file
        $testFile = $this->option('file') ?? __DIR__ . '/../../test.jpg';
        
        if (!file_exists($testFile)) {
            $this->error("Test file not found at: {$testFile}");
            $this->info("Please ensure test.jpg exists in the package root or provide --file option");
            return 1;
        }

        $this->info("Using test file: {$testFile}");
        $fileSize = filesize($testFile);
        $this->info("File size: " . $this->formatBytes($fileSize));
        $this->newLine();

        // Test 1: Check API endpoint directly
        $this->info('1️⃣  Testing API endpoint directly...');
        $apiUrl = config('file-manager.compression.api.url');
        $apiToken = config('file-manager.compression.api.token');
        
        if (empty($apiUrl)) {
            $this->error('API URL not configured in FILE_MANAGER_COMPRESSION_API_URL');
            return 1;
        }

        $this->info("API URL: {$apiUrl}");
        $this->info("API Token: " . (empty($apiToken) ? 'Not set' : 'Set (' . strlen($apiToken) . ' chars)'));
        
        // Test with a small timeout first
        try {
            $this->info('Testing API connectivity (5 second timeout)...');
            
            $testParams = [
                'format' => 'webp',
                'mode' => 'contain',
                'quality' => 85,
                'height' => 200, // Small size for quick test
            ];
            
            if ($this->option('bg-remove')) {
                $testParams['removebg'] = 'true';
                $this->info('Background removal: ENABLED');
            }
            
            $queryParams = http_build_query($testParams);
            $fullUrl = $apiUrl . '?' . $queryParams;
            
            $startTime = microtime(true);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiToken,
            ])
            ->timeout(5)
            ->attach('file', file_get_contents($testFile), 'test.jpg')
            ->post($fullUrl);
            
            $duration = round(microtime(true) - $startTime, 2);
            
            if ($response->successful()) {
                $this->info("✅ API responded successfully in {$duration} seconds");
                $responseSize = strlen($response->body());
                $this->info("Response size: " . $this->formatBytes($responseSize));
                
                // Save the result
                $outputFile = sys_get_temp_dir() . '/api_test_result.webp';
                file_put_contents($outputFile, $response->body());
                $this->info("Result saved to: {$outputFile}");
            } else {
                $this->error("❌ API returned error: " . $response->status());
                $this->error("Response: " . $response->body());
            }
            
        } catch (\Exception $e) {
            $this->error("❌ API request failed: " . $e->getMessage());
        }
        
        $this->newLine();
        
        // Test 2: Test using ImageCompressionService with GD
        $this->info('2️⃣  Testing GD compression...');
        
        try {
            $compressionService = new ImageCompressionService();
            
            // Force GD method
            config(['file-manager.compression.method' => 'gd']);
            
            $startTime = microtime(true);
            
            $result = $compressionService->compress(
                $testFile,
                85,
                200,
                null,
                'webp',
                'contain',
                false
            );
            
            $duration = round(microtime(true) - $startTime, 2);
            
            if ($result['success']) {
                $this->info("✅ GD compression successful in {$duration} seconds");
                $this->info("Original size: " . $this->formatBytes($result['data']['original_size'] ?? 0));
                $this->info("Compressed size: " . $this->formatBytes($result['data']['compressed_size'] ?? 0));
                $this->info("Compression ratio: " . ($result['data']['compression_ratio'] ?? 'N/A'));
                
                // Save the result
                $outputFile = sys_get_temp_dir() . '/gd_test_result.webp';
                file_put_contents($outputFile, $result['data']['compressed_image']);
                $this->info("Result saved to: {$outputFile}");
            } else {
                $this->error("❌ GD compression failed: " . ($result['message'] ?? 'Unknown error'));
            }
            
        } catch (\Exception $e) {
            $this->error("❌ GD compression exception: " . $e->getMessage());
        }
        
        $this->newLine();
        
        // Test 3: Test using ImageCompressionService with API
        if (!empty($apiUrl)) {
            $this->info('3️⃣  Testing API via ImageCompressionService...');
            
            try {
                // Force API method
                config(['file-manager.compression.method' => 'api']);
                config(['file-manager.compression.api.timeout' => 10]); // Shorter timeout for testing
                
                $compressionService = new ImageCompressionService();
                
                $startTime = microtime(true);
                
                $result = $compressionService->compress(
                    $testFile,
                    85,
                    200,
                    null,
                    'webp',
                    'contain',
                    $this->option('bg-remove')
                );
                
                $duration = round(microtime(true) - $startTime, 2);
                
                if ($result['success']) {
                    $this->info("✅ API compression via service successful in {$duration} seconds");
                    $this->info("Original size: " . $this->formatBytes($result['data']['original_size'] ?? 0));
                    $this->info("Compressed size: " . $this->formatBytes($result['data']['compressed_size'] ?? 0));
                    $this->info("Compression ratio: " . ($result['data']['compression_ratio'] ?? 'N/A'));
                    $this->info("Method used: " . ($result['data']['compression_method'] ?? 'unknown'));
                    
                    if (isset($result['data']['api_fallback_reason'])) {
                        $this->warn("Fallback reason: " . $result['data']['api_fallback_reason']);
                    }
                    
                    // Save the result
                    $outputFile = sys_get_temp_dir() . '/service_api_result.webp';
                    file_put_contents($outputFile, $result['data']['compressed_image']);
                    $this->info("Result saved to: {$outputFile}");
                } else {
                    $this->error("❌ API compression via service failed: " . ($result['message'] ?? 'Unknown error'));
                }
                
            } catch (\Exception $e) {
                $this->error("❌ API compression exception: " . $e->getMessage());
            }
        }
        
        $this->newLine();
        $this->info('📊 Test Summary:');
        $this->table(
            ['Configuration', 'Value'],
            [
                ['API URL', config('file-manager.compression.api.url') ?: 'Not set'],
                ['API Token', config('file-manager.compression.api.token') ? 'Set' : 'Not set'],
                ['Method', config('file-manager.compression.method')],
                ['Timeout', config('file-manager.compression.api.timeout') . ' seconds'],
                ['Auto Compress', config('file-manager.compression.auto_compress') ? 'Yes' : 'No'],
                ['Default Quality', config('file-manager.compression.quality')],
                ['Default Format', config('file-manager.compression.format')],
            ]
        );
        
        return 0;
    }
    
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}