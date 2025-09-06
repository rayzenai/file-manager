<?php

namespace Kirantimsina\FileManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification;

class BulkCompressionStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 1; // Only try once, no retries needed for status updates

    public function __construct(
        public string $batchId,
        public int $totalJobs,
        public int $completedJobs,
        public int $failedJobs,
        public array $compressionStats = []
    ) {
        // Use default queue
    }

    public function handle(): void
    {
        try {
            // Check if this is the final status update
            $isComplete = ($this->completedJobs + $this->failedJobs) >= $this->totalJobs;
            
            if ($isComplete) {
                $this->sendFinalNotification();
                
                // Clean up cache
                Cache::forget("compression_batch_{$this->batchId}");
            } else {
                $this->sendProgressNotification();
            }

        } catch (\Throwable $e) {
            Log::error("BulkCompressionStatusJob: Failed to send notification", [
                'batch_id' => $this->batchId,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function sendProgressNotification(): void
    {
        $progress = round(($this->completedJobs / $this->totalJobs) * 100);
        
        Notification::make()
            ->title('Compression Progress')
            ->body("Processing images: {$this->completedJobs}/{$this->totalJobs} completed ({$progress}%)")
            ->info()
            ->duration(5000)
            ->send();
    }

    private function sendFinalNotification(): void
    {
        $successCount = $this->completedJobs;
        $failedCount = $this->failedJobs;
        
        // Calculate total savings if we have stats
        $totalSavedBytes = 0;
        $compressionDetails = [];
        
        if (!empty($this->compressionStats)) {
            foreach ($this->compressionStats as $stat) {
                if (isset($stat['original_size'], $stat['compressed_size'])) {
                    $saved = $stat['original_size'] - $stat['compressed_size'];
                    $totalSavedBytes += $saved;
                    
                    $originalKb = round($stat['original_size'] / 1024);
                    $compressedKb = round($stat['compressed_size'] / 1024);
                    $compressionDetails[] = "{$stat['file_name']}: {$originalKb}KB → {$compressedKb}KB";
                }
            }
        }
        
        $savedMb = round($totalSavedBytes / (1024 * 1024), 2);
        
        // Build notification
        if ($failedCount === 0) {
            $title = 'Bulk compression completed successfully';
            $body = "Successfully compressed {$successCount} images.";
            
            if ($savedMb > 0) {
                $body .= " Saved {$savedMb} MB total.";
            }
            
            $notification = Notification::make()
                ->title($title)
                ->body($body)
                ->success();
        } else {
            $title = 'Bulk compression completed with errors';
            $body = "Compressed {$successCount} images successfully, {$failedCount} failed.";
            
            if ($savedMb > 0) {
                $body .= " Saved {$savedMb} MB total.";
            }
            
            $notification = Notification::make()
                ->title($title)
                ->body($body)
                ->warning();
        }
        
        // Add compression details if available (limit to prevent huge notifications)
        if (!empty($compressionDetails)) {
            $body .= "\n\n**Compression Results:**\n";
            $detailsToShow = array_slice($compressionDetails, 0, 5);
            foreach ($detailsToShow as $detail) {
                $body .= "• {$detail}\n";
            }
            if (count($compressionDetails) > 5) {
                $remaining = count($compressionDetails) - 5;
                $body .= "• ...and {$remaining} more\n";
            }
            
            $notification->body($body);
        }
        
        $notification
            ->duration(10000) // Show for 10 seconds
            ->send();
    }
}