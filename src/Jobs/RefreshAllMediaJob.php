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
use Kirantimsina\FileManager\Models\MediaMetadata;
use Kirantimsina\FileManager\Services\FileInfoService;
use Throwable;

class RefreshAllMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes timeout per job
    public int $tries = 3; // Retry failed jobs 3 times
    public int $backoff = 30; // Wait 30 seconds between retries

    public function __construct(
        public int $mediaMetadataId,
        public ?string $batchId = null
    ) {
        // Use default queue
    }

    public function handle(): void
    {
        $record = MediaMetadata::find($this->mediaMetadataId);
        
        if (!$record) {
            Log::warning("RefreshAllMediaJob: MediaMetadata record {$this->mediaMetadataId} not found");
            return;
        }

        try {
            $fileInfoService = new FileInfoService();
            $result = $this->refreshMediaRecord($record, $fileInfoService);
            
            if ($result['success']) {
                Log::info("RefreshAllMediaJob: Successfully refreshed {$record->file_name}", [
                    'changes' => $result['changes'] ?? []
                ]);

                // Update batch progress if part of a batch
                if ($this->batchId) {
                    $this->updateBatchProgress($record, $result, 'completed');
                }
            } else {
                Log::warning("RefreshAllMediaJob: No changes needed for {$record->file_name}");
                
                // Update batch progress if part of a batch
                if ($this->batchId) {
                    $this->updateBatchProgress($record, $result, 'completed');
                }
            }

        } catch (Throwable $e) {
            Log::error("RefreshAllMediaJob: Exception processing {$record->file_name}", [
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
        
        Log::error("RefreshAllMediaJob: Final failure for {$fileName}", [
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
        
        // Update batch progress if part of a batch
        if ($this->batchId && $record) {
            $this->updateBatchProgress($record, ['message' => $exception->getMessage()], 'failed');
        }
    }

    private function refreshMediaRecord(MediaMetadata $record, FileInfoService $fileInfoService): array
    {
        $fileName = $record->file_name;
        
        // Check if file exists in S3
        if (!Storage::disk(config('filesystems.default'))->exists($fileName)) {
            return [
                'success' => false,
                'message' => "File not found in S3: {$fileName}"
            ];
        }

        // Get fresh file metadata from S3 using FileInfoService
        try {
            $fileInfo = $fileInfoService->getFileInfo($fileName, config('filesystems.default'));

            if (!$fileInfo) {
                return [
                    'success' => false,
                    'message' => "Could not read file metadata: {$fileName}"
                ];
            }

            $changes = [];
            $updateData = [];

            // Check file size changes
            if ($record->file_size !== $fileInfo['size']) {
                $changes[] = "File size: {$record->file_size} → {$fileInfo['size']}";
                $updateData['file_size'] = $fileInfo['size'];
            }

            // Check mime type changes
            if ($record->mime_type !== $fileInfo['mime_type']) {
                $changes[] = "MIME type: {$record->mime_type} → {$fileInfo['mime_type']}";
                $updateData['mime_type'] = $fileInfo['mime_type'];
            }

            // Check dimensions for images
            if ($fileInfo['width'] !== null && $fileInfo['height'] !== null) {
                if ($record->width !== $fileInfo['width']) {
                    $changes[] = "Width: {$record->width} → {$fileInfo['width']}";
                    $updateData['width'] = $fileInfo['width'];
                }

                if ($record->height !== $fileInfo['height']) {
                    $changes[] = "Height: {$record->height} → {$fileInfo['height']}";
                    $updateData['height'] = $fileInfo['height'];
                }
            }
            
            // Check if the parent model still references this file
            $model = $record->mediable_type::find($record->mediable_id);
            if ($model) {
                $field = $record->mediable_field;
                $modelValue = $model->{$field};
                
                $fileStillReferenced = false;
                if (is_array($modelValue)) {
                    $fileStillReferenced = in_array($fileName, $modelValue);
                } else {
                    $fileStillReferenced = ($modelValue === $fileName);
                }
                
                if (!$fileStillReferenced) {
                    $changes[] = "File no longer referenced by parent model";
                    // Note: We don't delete the record, just log this discrepancy
                }
            } else {
                $changes[] = "Parent model no longer exists";
                // Note: We don't delete the record, just log this discrepancy
            }
            
            // Update record if there are changes
            if (!empty($updateData)) {
                $record->update($updateData);
                
                return [
                    'success' => true,
                    'changes' => $changes,
                    'updated_fields' => array_keys($updateData)
                ];
            }
            
            return [
                'success' => false,
                'message' => 'No changes detected',
                'changes' => $changes
            ];
            
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error reading file metadata: ' . $e->getMessage()
            ];
        }
    }

    private function updateBatchProgress(MediaMetadata $record, array $result, string $status): void
    {
        if (!$this->batchId) {
            return;
        }

        $cacheKey = "refresh_batch_{$this->batchId}";
        $batchData = Cache::get($cacheKey, [
            'total' => 0,
            'completed' => 0,
            'failed' => 0,
            'stats' => []
        ]);

        // Update counters
        if ($status === 'completed') {
            $batchData['completed']++;
            
            // Store refresh stats
            if (isset($result['changes']) && !empty($result['changes'])) {
                $batchData['stats'][] = [
                    'file_name' => $record->file_name,
                    'changes' => $result['changes'],
                    'updated_fields' => $result['updated_fields'] ?? []
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
            \Kirantimsina\FileManager\Jobs\BulkRefreshStatusJob::dispatch(
                $this->batchId,
                $batchData['total'],
                $batchData['completed'],
                $batchData['failed'],
                $batchData['stats']
            );
        } elseif ($totalProcessed % 10 === 0) {
            // Send progress update every 10 completed jobs
            \Kirantimsina\FileManager\Jobs\BulkRefreshStatusJob::dispatch(
                $this->batchId,
                $batchData['total'],
                $batchData['completed'],
                $batchData['failed'],
                []
            );
        }
    }
}