<?php

namespace Kirantimsina\FileManager\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Kirantimsina\FileManager\Jobs\RefreshAllMediaJob;
use Kirantimsina\FileManager\Models\MediaMetadata;

class RefreshAllMediaCommand extends Command
{
    protected $signature = 'file-manager:refresh-all
                           {--force : Skip confirmation prompts}
                           {--chunk=100 : Number of records to process at once}
                           {--dry-run : Show what would be done without executing}';

    protected $description = 'Queue refresh jobs for all media metadata records';

    public function handle(): int
    {
        $force = $this->option('force');
        $chunkSize = (int) $this->option('chunk');
        $dryRun = $this->option('dry-run');

        // Get count of all records
        $totalRecords = MediaMetadata::count();

        if ($totalRecords === 0) {
            $this->info('No media metadata records found');
            return 0;
        }

        $this->info("Found {$totalRecords} media metadata records to refresh");

        if ($dryRun) {
            $this->info("DRY RUN: Would queue {$totalRecords} refresh jobs");
            $this->info("Each job will check file metadata, dimensions, and update records as needed");
            return 0;
        }

        if (!$force && !$this->confirm("Queue refresh jobs for {$totalRecords} media records?")) {
            return 0;
        }

        // Generate a unique batch ID
        $batchId = Str::uuid()->toString();
        
        // Initialize batch cache
        Cache::put("refresh_batch_{$batchId}", [
            'total' => $totalRecords,
            'completed' => 0,
            'failed' => 0,
            'stats' => []
        ], 3600); // Cache for 1 hour

        // Dispatch jobs in chunks
        $jobsDispatched = 0;
        $progressBar = $this->output->createProgressBar($totalRecords);
        $progressBar->setMessage('Dispatching refresh jobs...');
        $progressBar->start();

        MediaMetadata::orderBy('id')
            ->chunk($chunkSize, function ($records) use ($batchId, &$jobsDispatched, $progressBar) {
                foreach ($records as $record) {
                    RefreshAllMediaJob::dispatch($record->id, $batchId);
                    $jobsDispatched++;
                    $progressBar->advance();
                }
            });

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Successfully queued {$jobsDispatched} refresh jobs");
        $this->info("Batch ID: {$batchId}");
        $this->info("You'll receive notifications about progress and completion");
        
        $this->comment("Jobs will check each file for:");
        $this->line("- File size changes");
        $this->line("- MIME type changes");
        $this->line("- Image dimension changes");
        $this->line("- Parent model reference consistency");

        return 0;
    }
}