<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Commands;

use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Kirantimsina\FileManager\FileManagerService;

class UpdateImageCacheHeadersCommand extends Command
{
    protected $signature = 'file-manager:update-cache-headers 
                            {directory? : Specific directory to update (e.g., products, users)}
                            {--dry-run : Show what would be updated without making changes}
                            {--limit=0 : Limit number of files to process (0 = unlimited)}
                            {--detailed : Show detailed output for each file processed}';

    protected $description = 'Update cache control headers for existing images in S3';

    protected array $imageExtensions = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'];

    protected int $processedCount = 0;

    protected int $skippedCount = 0;

    protected int $errorCount = 0;

    public function handle(): int
    {
        $directory = $this->argument('directory');
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        if (! config('file-manager.cache.enabled', true)) {
            $this->warn('Cache headers are disabled in config. Enable them first.');

            return 1;
        }

        $cacheControl = FileManagerService::buildCacheControlHeader();
        if (! $cacheControl) {
            $this->error('Could not build cache control header from config.');

            return 1;
        }

        $this->info('Cache Control Header to be applied: ' . $cacheControl);
        $this->info('Starting to update cache headers' . ($dryRun ? ' (DRY RUN)' : '') . '...');
        $this->newLine();

        // Get all directories or use specified one
        $directories = $directory ? [$directory] : $this->getImageDirectories();

        foreach ($directories as $dir) {
            $this->processDirectory($dir, $cacheControl, $dryRun, $limit);

            if ($limit > 0 && $this->processedCount >= $limit) {
                break;
            }
        }

        $this->newLine();
        $this->info('Summary:');
        $this->info("✅ Processed: {$this->processedCount} files");
        $this->info("⏭️  Skipped: {$this->skippedCount} files");
        $this->error("❌ Errors: {$this->errorCount} files");

        return 0;
    }

    protected function processDirectory(string $directory, string $cacheControl, bool $dryRun, int $limit): void
    {
        $this->info("Processing directory: {$directory}");

        try {
            $files = Storage::disk(config('filesystems.default'))->files($directory, true);
            $imageFiles = $this->filterImageFiles($files);

            $this->info('Found ' . count($imageFiles) . ' image files');

            if (empty($imageFiles)) {
                return;
            }

            // Only show progress bar if not in detailed mode
            $bar = null;
            if (! $this->option('detailed')) {
                $bar = $this->output->createProgressBar(count($imageFiles));
                $bar->start();
            } else {
                $this->info('Processing ' . count($imageFiles) . ' files...');
                $this->newLine();
            }

            foreach ($imageFiles as $file) {
                if ($limit > 0 && $this->processedCount >= $limit) {
                    break;
                }

                $this->processFile($file, $cacheControl, $dryRun);

                if ($bar) {
                    $bar->advance();
                }
            }

            if ($bar) {
                $bar->finish();
                $this->newLine(2);
            } else {
                $this->newLine();
            }

        } catch (\Exception $e) {
            $this->error("Error processing directory {$directory}: " . $e->getMessage());
        }
    }

    protected function processFile(string $file, string $cacheControl, bool $dryRun): void
    {
        try {
            // Get the file extension to determine content type
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $contentType = $this->getContentType($extension);

            if ($dryRun) {
                if ($this->option('detailed')) {
                    $this->line("  [DRY RUN] Would update: {$file}");
                }
                $this->processedCount++;

                return;
            }

            // Create S3 client directly
            $config = config('filesystems.disks.s3');
            $client = new S3Client([
                'version' => 'latest',
                'region' => $config['region'],
                'credentials' => [
                    'key' => $config['key'],
                    'secret' => $config['secret'],
                ],
            ]);

            $bucket = $config['bucket'];

            // Copy object to itself with new metadata
            $client->copyObject([
                'Bucket' => $bucket,
                'Key' => $file,
                'CopySource' => urlencode($bucket . '/' . $file),
                'MetadataDirective' => 'REPLACE',
                'CacheControl' => $cacheControl,
                'ContentType' => $contentType,
                'ACL' => 'public-read',
            ]);

            $this->processedCount++;

            if ($this->option('detailed')) {
                $this->info("  ✅ Updated: {$file}");
                $this->line("     Cache-Control: {$cacheControl}");
                $this->line("     Content-Type: {$contentType}");
            }

        } catch (\Exception $e) {
            $this->errorCount++;
            if ($this->option('detailed')) {
                $this->error("  ❌ Error: {$file}");
                $this->line('     ' . $e->getMessage());
            }
        }
    }

    protected function filterImageFiles(array $files): array
    {
        return array_filter($files, function ($file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            return in_array($extension, $this->imageExtensions);
        });
    }

    protected function getImageDirectories(): array
    {
        // Get directories from config model mappings
        $modelMappings = config('file-manager.model', []);
        $directories = array_values($modelMappings);

        // Also check for common resize directories
        $resizeDirs = array_keys(FileManagerService::getImageSizes());

        // Combine base directories with their resize subdirectories
        $allDirectories = [];
        foreach ($directories as $dir) {
            $allDirectories[] = $dir;
            foreach ($resizeDirs as $sizeKey) {
                $allDirectories[] = "{$dir}/{$sizeKey}";
            }
        }

        return array_unique($allDirectories);
    }

    protected function getContentType(string $extension): string
    {
        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }
}
