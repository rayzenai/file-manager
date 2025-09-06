<?php

namespace Kirantimsina\FileManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kirantimsina\FileManager\Jobs\ResizeImages;
use Kirantimsina\FileManager\Models\MediaMetadata;
use Kirantimsina\FileManager\Services\ImageCompressionService;
use Throwable;

class CompressImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes timeout per job
    public int $tries = 3; // Retry failed jobs 3 times
    public int $backoff = 30; // Wait 30 seconds between retries

    public function __construct(
        public int $mediaMetadataId,
        public array $compressionSettings = [],
        public ?string $batchId = null
    ) {
        // Use default queue
    }

    public function handle(): void
    {
        $record = MediaMetadata::find($this->mediaMetadataId);
        
        if (!$record) {
            Log::warning("CompressImageJob: MediaMetadata record {$this->mediaMetadataId} not found");
            return;
        }

        // Only process image files
        if (!str_starts_with($record->mime_type ?? '', 'image/')) {
            Log::info("CompressImageJob: Skipping non-image file {$record->file_name}");
            return;
        }

        try {
            // Override compression method if specified
            $originalMethod = config('file-manager.compression.method');
            if (isset($this->compressionSettings['compression_method']) && $this->compressionSettings['compression_method'] !== 'auto') {
                $method = $this->compressionSettings['compression_method'] === 'api' ? 'api' : 'gd';
                config(['file-manager.compression.method' => $method]);
            }

            $compressionService = new ImageCompressionService;
            
            $result = $this->compressMediaRecord($record, $this->compressionSettings, $compressionService);
            
            // Restore original method
            config(['file-manager.compression.method' => $originalMethod]);

            if ($result['success']) {
                Log::info("CompressImageJob: Successfully compressed {$record->file_name}", [
                    'original_size' => $result['original_size'],
                    'compressed_size' => $result['compressed_size'],
                    'compression_ratio' => $result['compression_ratio']
                ]);

                // Update batch progress if part of a batch
                if ($this->batchId) {
                    $this->updateBatchProgress($record, $result, 'completed');
                }

                // Resize all versions if requested
                if ($this->compressionSettings['resize_after'] ?? true) {
                    $this->resizeAllVersions($record);
                }
            } else {
                Log::error("CompressImageJob: Failed to compress {$record->file_name}", [
                    'error' => $result['message'] ?? 'Unknown error'
                ]);
                
                // Update batch progress if part of a batch
                if ($this->batchId) {
                    $this->updateBatchProgress($record, $result, 'failed');
                }
                
                throw new \Exception($result['message'] ?? 'Compression failed');
            }

        } catch (Throwable $e) {
            Log::error("CompressImageJob: Exception processing {$record->file_name}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Update batch progress if part of a batch
            if ($this->batchId) {
                $this->updateBatchProgress($record, ['message' => $e->getMessage()], 'failed');
            }
            
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $record = MediaMetadata::find($this->mediaMetadataId);
        $fileName = $record?->file_name ?? "ID:{$this->mediaMetadataId}";
        
        Log::error("CompressImageJob: Final failure for {$fileName}", [
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
        
        // Update batch progress if part of a batch
        if ($this->batchId && $record) {
            $this->updateBatchProgress($record, ['message' => $exception->getMessage()], 'failed');
        }
    }

    private function compressMediaRecord(MediaMetadata $record, array $data, ImageCompressionService $compressionService): array
    {
        $quality = (int) ($data['quality'] ?? 85);
        $replaceOriginal = $data['replace_original'] ?? true;
        $fileName = $record->file_name;

        if (!$fileName) {
            return [
                'success' => false,
                'message' => 'No file path found in record'
            ];
        }

        // Determine the output format
        $outputFormat = $data['format'];
        if ($outputFormat === 'preserve') {
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            $outputFormat = match (strtolower($extension)) {
                'jpg', 'jpeg' => 'jpg',
                'png' => 'png',
                'webp' => 'webp',
                'avif' => 'avif',
                default => 'webp',
            };
        }

        // Build the new file name
        $pathInfo = pathinfo($fileName);
        $directory = $pathInfo['dirname'];
        $filenameWithoutExt = $pathInfo['filename'];
        $newExtension = match ($outputFormat) {
            'jpg' => 'jpg',
            'png' => 'png', 
            'webp' => 'webp',
            'avif' => 'avif',
            default => 'webp'
        };
        $newFileName = $directory . '/' . $filenameWithoutExt . '.' . $newExtension;

        // Compress the image
        $result = $compressionService->compressAndSave(
            $fileName,
            $newFileName,
            $quality,
            null, // height - let service handle max constraints
            null, // width - let service handle max constraints  
            $outputFormat,
            config('file-manager.compression.mode', 'contain'),
            's3'
        );

        if ($result['success']) {
            // If replacing and format changed, delete old file
            if ($replaceOriginal && $newFileName !== $fileName) {
                Storage::disk('s3')->delete($fileName);

                // Delete resized versions
                $sizes = config('file-manager.image_sizes', []);
                foreach (array_keys($sizes) as $size) {
                    $resizedPath = "{$directory}/{$size}/{$pathInfo['basename']}";
                    if (Storage::disk('s3')->exists($resizedPath)) {
                        Storage::disk('s3')->delete($resizedPath);
                    }
                }
            }

            // Update metadata including dimensions from compression result
            $updateData = [
                'file_size' => $result['data']['compressed_size'] ?? $record->file_size,
                'metadata' => array_merge($record->metadata ?? [], [
                    'compression' => [
                        'original_size' => $result['data']['original_size'] ?? null,
                        'compressed_size' => $result['data']['compressed_size'] ?? null,
                        'compression_ratio' => $result['data']['compression_ratio'] ?? null,
                        'quality' => $quality,
                        'format' => $outputFormat,
                        'compressed_at' => now()->toIso8601String(),
                    ],
                ]),
            ];

            // Update dimensions if available from compression result
            if (isset($result['data']['width']) && isset($result['data']['height'])) {
                $updateData['width'] = $result['data']['width'];
                $updateData['height'] = $result['data']['height'];
            }

            if ($newFileName !== $fileName) {
                $updateData['file_name'] = $newFileName;

                // Update model field if replacing
                if ($replaceOriginal) {
                    $model = $record->mediable_type::find($record->mediable_id);
                    if ($model) {
                        $field = $record->mediable_field;
                        if (is_array($model->{$field})) {
                            $values = $model->{$field};
                            $key = array_search($fileName, $values);
                            if ($key !== false) {
                                $values[$key] = $newFileName;
                                $model->{$field} = $values;
                                $model->save();
                            }
                        } else {
                            $model->{$field} = $newFileName;
                            $model->save();
                        }
                    }
                }
            }

            $record->update($updateData);

            return [
                'success' => true,
                'original_size' => $result['data']['original_size'],
                'compressed_size' => $result['data']['compressed_size'],
                'compression_ratio' => $result['data']['compression_ratio'],
                'compression_method' => $result['data']['compression_method'] ?? 'unknown'
            ];
        }

        return $result;
    }

    private function generateCompressedPath(string $originalPath, ?string $format): string
    {
        if (!$format) {
            return $originalPath;
        }

        $pathInfo = pathinfo($originalPath);
        $directory = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        
        return "{$directory}/{$filename}_compressed.{$format}";
    }

    private function resizeAllVersions(MediaMetadata $record): void
    {
        try {
            // Dispatch resize job for this image
            ResizeImages::dispatch([$record->file_name]);
            Log::info("CompressImageJob: Dispatched resize job for {$record->file_name}");
        } catch (Throwable $e) {
            Log::warning("CompressImageJob: Failed to dispatch resize job for {$record->file_name}", [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function updateBatchProgress(MediaMetadata $record, array $result, string $status): void
    {
        if (!$this->batchId) {
            return;
        }

        $cacheKey = "compression_batch_{$this->batchId}";
        $batchData = Cache::get($cacheKey, [
            'total' => 0,
            'completed' => 0,
            'failed' => 0,
            'stats' => []
        ]);

        // Update counters
        if ($status === 'completed') {
            $batchData['completed']++;
            
            // Store compression stats
            if (isset($result['original_size'], $result['compressed_size'])) {
                $batchData['stats'][] = [
                    'file_name' => $record->file_name,
                    'original_size' => $result['original_size'],
                    'compressed_size' => $result['compressed_size'],
                    'compression_ratio' => $result['compression_ratio'],
                    'method' => $result['compression_method'] ?? 'unknown'
                ];
            }
        } elseif ($status === 'failed') {
            $batchData['failed']++;
        }

        // Cache the updated data for 1 hour
        Cache::put($cacheKey, $batchData, 3600);

        // Check if batch is complete and dispatch status notification
        $totalProcessed = $batchData['completed'] + $batchData['failed'];
        if ($totalProcessed >= $batchData['total']) {
            // Dispatch final status notification
            \Kirantimsina\FileManager\Jobs\BulkCompressionStatusJob::dispatch(
                $this->batchId,
                $batchData['total'],
                $batchData['completed'],
                $batchData['failed'],
                $batchData['stats']
            );
        } elseif ($totalProcessed % 5 === 0) {
            // Send progress update every 5 completed jobs
            \Kirantimsina\FileManager\Jobs\BulkCompressionStatusJob::dispatch(
                $this->batchId,
                $batchData['total'],
                $batchData['completed'],
                $batchData['failed'],
                []
            );
        }
    }
}