<?php

namespace Kirantimsina\FileManager\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kirantimsina\FileManager\Models\MediaMetadata;

class RemoveDuplicateMediaMetadataCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'file-manager:remove-duplicates
                            {--dry-run : Preview duplicates without removing them}
                            {--force : Remove duplicates without confirmation prompt}
                            {--chunk=1000 : Number of records to process at once}';

    /**
     * The console command description.
     */
    protected $description = 'Remove duplicate media metadata records based on filename, model type, model ID, and field name';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $chunkSize = (int) $this->option('chunk');

        $this->info('🔍 Analyzing media metadata for duplicates...');

        // Find duplicates - using database-agnostic approach
        $duplicates = DB::table('media_metadata')
            ->select([
                'mediable_type',
                'mediable_id', 
                'mediable_field',
                'file_name',
                DB::raw('COUNT(*) as duplicate_count'),
                DB::raw('MIN(created_at) as oldest_record'),
                DB::raw('MIN(id) as keep_id')
            ])
            ->groupBy(['mediable_type', 'mediable_id', 'mediable_field', 'file_name'])
            ->havingRaw('COUNT(*) > 1')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('mediable_type')
            ->orderBy('mediable_id')
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('✅ No duplicates found! Your media metadata is clean.');
            return self::SUCCESS;
        }

        $totalDuplicates = $duplicates->sum('duplicate_count');
        $totalToRemove = $totalDuplicates - $duplicates->count(); // Keep one of each group

        $this->warn("Found " . $duplicates->count() . " groups of duplicates containing {$totalDuplicates} total records.");
        $this->warn("Will remove {$totalToRemove} duplicate records (keeping the oldest in each group).");

        // Show sample of duplicates
        $this->newLine();
        $this->line('📋 Sample duplicates found:');
        $headers = ['Model', 'ID', 'Field', 'Filename', 'Count', 'Oldest Record'];
        $sampleData = $duplicates->take(10)->map(function ($duplicate) {
            return [
                class_basename($duplicate->mediable_type),
                $duplicate->mediable_id,
                $duplicate->mediable_field,
                substr($duplicate->file_name, 0, 30) . (strlen($duplicate->file_name) > 30 ? '...' : ''),
                $duplicate->duplicate_count,
                $duplicate->oldest_record,
            ];
        })->toArray();

        $this->table($headers, $sampleData);

        if ($duplicates->count() > 10) {
            $this->line("... and " . ($duplicates->count() - 10) . " more groups");
        }

        if ($dryRun) {
            $this->info('🔍 Dry run complete. Use without --dry-run to actually remove duplicates.');
            return self::SUCCESS;
        }

        if (!$force && !$this->confirm("Are you sure you want to remove {$totalToRemove} duplicate records?")) {
            $this->info('Operation cancelled.');
            return self::SUCCESS;
        }

        $this->info('🗑️ Removing duplicates...');
        $this->newLine();

        $removedCount = 0;
        $progressBar = $this->output->createProgressBar($duplicates->count());
        $progressBar->start();

        foreach ($duplicates as $duplicate) {
            // Keep the record with keep_id, remove all others in this group
            $deletedInThisGroup = MediaMetadata::where('mediable_type', $duplicate->mediable_type)
                ->where('mediable_id', $duplicate->mediable_id)
                ->where('mediable_field', $duplicate->mediable_field)
                ->where('file_name', $duplicate->file_name)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
            
            $removedCount += $deletedInThisGroup;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("✅ Successfully removed {$removedCount} duplicate media metadata records.");
        $this->info("Kept " . $duplicates->count() . " unique records (the oldest in each group).");

        // Verify cleanup
        $remainingDuplicates = DB::table('media_metadata')
            ->select([
                'mediable_type',
                'mediable_id', 
                'mediable_field',
                'file_name',
                DB::raw('COUNT(*) as duplicate_count')
            ])
            ->groupBy(['mediable_type', 'mediable_id', 'mediable_field', 'file_name'])
            ->havingRaw('COUNT(*) > 1')
            ->get();
            
        if ($remainingDuplicates->isEmpty()) {
            $this->info('✅ Verification: No duplicates remaining.');
        } else {
            $this->warn('⚠️ Warning: ' . $remainingDuplicates->count() . ' duplicate groups still exist. You may need to run this command again.');
        }

        return self::SUCCESS;
    }
}