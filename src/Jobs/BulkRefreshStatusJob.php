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

class BulkRefreshStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 1; // Only try once, no retries needed for status updates

    public function __construct(
        public string $batchId,
        public int $totalJobs,
        public int $completedJobs,
        public int $failedJobs,
        public array $refreshStats = []
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
                Cache::forget("refresh_batch_{$this->batchId}");
            } else {
                $this->sendProgressNotification();
            }

        } catch (\Throwable $e) {
            Log::error("BulkRefreshStatusJob: Failed to send notification", [
                'batch_id' => $this->batchId,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function sendProgressNotification(): void
    {
        $progress = round(($this->completedJobs / $this->totalJobs) * 100);
        
        Notification::make()
            ->title('Refresh Progress')
            ->body("Refreshing media metadata: {$this->completedJobs}/{$this->totalJobs} completed ({$progress}%)")
            ->info()
            ->duration(5000)
            ->send();
    }

    private function sendFinalNotification(): void
    {
        $successCount = $this->completedJobs;
        $failedCount = $this->failedJobs;
        $updatedCount = count($this->refreshStats);
        
        // Build notification
        if ($failedCount === 0) {
            $title = 'Bulk refresh completed successfully';
            $body = "Processed {$successCount} media files. {$updatedCount} files had changes and were updated.";
            
            $notification = Notification::make()
                ->title($title)
                ->body($body)
                ->success();
        } else {
            $title = 'Bulk refresh completed with errors';
            $body = "Processed {$successCount} files successfully, {$failedCount} failed. {$updatedCount} files were updated.";
            
            $notification = Notification::make()
                ->title($title)
                ->body($body)
                ->warning();
        }
        
        // Add refresh details if available (limit to prevent huge notifications)
        if (!empty($this->refreshStats)) {
            $body .= "\n\n**Updated Files:**\n";
            $detailsToShow = array_slice($this->refreshStats, 0, 5);
            foreach ($detailsToShow as $stat) {
                $changesText = implode(', ', array_slice($stat['changes'], 0, 2));
                if (count($stat['changes']) > 2) {
                    $changesText .= '...';
                }
                $body .= "• {$stat['file_name']}: {$changesText}\n";
            }
            if (count($this->refreshStats) > 5) {
                $remaining = count($this->refreshStats) - 5;
                $body .= "• ...and {$remaining} more\n";
            }
            
            $notification->body($body);
        }
        
        $notification
            ->duration(10000) // Show for 10 seconds
            ->send();
    }
}