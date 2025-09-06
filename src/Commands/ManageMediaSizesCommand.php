<?php

namespace Kirantimsina\FileManager\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Kirantimsina\FileManager\FileManagerService;
use Kirantimsina\FileManager\Models\MediaMetadata;

class ManageMediaSizesCommand extends Command
{
    protected $signature = 'file-manager:manage-sizes 
                           {action : add or remove}
                           {size-name : Name of the size to add/remove}
                           {height? : Height in pixels for the new size (required for add action)}
                           {--force : Skip confirmation prompts}
                           {--dry-run : Show what would be done without executing}
                           {--chunk=100 : Number of records to process at once}';

    protected $description = 'Add or remove image sizes for all media in media_metadata table';

    public function handle(): int
    {
        $action = $this->argument('action');
        $sizeName = $this->argument('size-name');
        $height = $this->argument('height');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');

        // Validate action
        if (!in_array($action, ['add', 'remove'])) {
            $this->error('Action must be either "add" or "remove"');
            return 1;
        }

        // Validate height for add action
        if ($action === 'add') {
            if (!$height || !is_numeric($height) || $height <= 0) {
                $this->error('Height must be a positive number when adding a size');
                return 1;
            }
            $height = (int) $height;
        }

        // Get current configuration
        $currentSizes = config('file-manager.image_sizes', []);

        if ($action === 'add') {
            return $this->handleAddSize($sizeName, $height, $currentSizes, $force, $dryRun, $chunkSize);
        } else {
            return $this->handleRemoveSize($sizeName, $currentSizes, $force, $dryRun, $chunkSize);
        }
    }

    private function handleAddSize(string $sizeName, int $height, array $currentSizes, bool $force, bool $dryRun, int $chunkSize): int
    {
        // Check if size already exists in configuration
        if (array_key_exists($sizeName, $currentSizes)) {
            $currentHeight = $currentSizes[$sizeName];
            if ($currentHeight == $height) {
                $this->info("Size '{$sizeName}' with height {$height}px already exists in configuration");
                
                if (!$force && !$this->confirm("Do you want to regenerate all '{$sizeName}' sized images?")) {
                    return 0;
                }
            } else {
                $this->warn("Size '{$sizeName}' exists in configuration with different height ({$currentHeight}px). This will update it to {$height}px.");
                
                if (!$force && !$this->confirm("Continue?")) {
                    return 0;
                }
            }
        } else {
            // Size doesn't exist in configuration - block operation
            $this->error("Size '{$sizeName}' is not found in your configuration.");
            $this->newLine();
            $this->info("Please add it to config/file-manager.php first:");
            $this->line("'image_sizes' => [");
            $this->line("    // ... existing sizes ...");
            $this->line("    '{$sizeName}' => {$height},");
            $this->line("],");
            $this->newLine();
            $this->comment("After updating your config, run this command again.");
            
            return 1;
        }

        // Get count of image records
        $totalRecords = MediaMetadata::where('mime_type', 'like', 'image/%')->count();

        if ($totalRecords === 0) {
            $this->info('No image records found in media_metadata table');
            return 0;
        }

        $this->info("Found {$totalRecords} image records to process");

        if ($dryRun) {
            $this->info("DRY RUN: Would add size '{$sizeName}' ({$height}px height) to {$totalRecords} images");
            $this->info("New sized files would be created in S3 storage");
            return 0;
        }

        if (!$force && !$this->confirm("Add size '{$sizeName}' ({$height}px height) to {$totalRecords} images?")) {
            return 0;
        }

        // Process images in chunks
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $failedFiles = [];

        $progressBar = $this->output->createProgressBar($totalRecords);
        $progressBar->start();

        MediaMetadata::where('mime_type', 'like', 'image/%')
            ->orderBy('id')
            ->chunk($chunkSize, function ($records) use ($sizeName, $height, &$processed, &$succeeded, &$failed, &$failedFiles, $progressBar) {
                foreach ($records as $record) {
                    try {
                        $this->createSizedImage($record, $sizeName, $height);
                        $succeeded++;
                    } catch (Exception $e) {
                        $failed++;
                        $failedFiles[] = [
                            'file' => $record->file_name,
                            'error' => $e->getMessage()
                        ];
                    }
                    
                    $processed++;
                    $progressBar->advance();
                }
            });

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Processing completed:");
        $this->info("- Total processed: {$processed}");
        $this->info("- Succeeded: {$succeeded}");
        $this->info("- Failed: {$failed}");

        if ($failed > 0) {
            $this->warn("Failed files (showing first 10):");
            foreach (array_slice($failedFiles, 0, 10) as $failure) {
                $this->line("  {$failure['file']}: {$failure['error']}");
            }
            
            if (count($failedFiles) > 10) {
                $remaining = count($failedFiles) - 10;
                $this->line("  ...and {$remaining} more");
            }
        }

        if ($succeeded > 0) {
            $this->info("Successfully created '{$sizeName}' sized images for {$succeeded} files");
            $this->comment("Remember to update your config/file-manager.php to include the new size:");
            $this->line("'{$sizeName}' => {$height},");
        }

        return $failed > 0 ? 1 : 0;
    }

    private function handleRemoveSize(string $sizeName, array $currentSizes, bool $force, bool $dryRun, int $chunkSize): int
    {
        // Check if size exists in config
        if (!array_key_exists($sizeName, $currentSizes)) {
            // For removal, we can be more lenient - maybe they already removed it from config
            // and now want to clean up leftover files
            $this->warn("Size '{$sizeName}' not found in current configuration.");
            $this->info("This suggests you may have already removed it from config.");
            $this->info("This command will clean up any remaining '{$sizeName}' sized files from storage.");
            
            if (!$force && !$this->confirm("Continue to remove any existing '{$sizeName}' sized files?")) {
                return 0;
            }
        } else {
            $height = $currentSizes[$sizeName];
            $this->error("Size '{$sizeName}' is still in your configuration.");
            $this->newLine();
            $this->info("Please remove it from config/file-manager.php first:");
            $this->line("// Remove this line:");
            $this->line("'{$sizeName}' => {$height},");
            $this->newLine();
            $this->comment("After updating your config, run this command again to clean up the sized files.");
            
            return 1;
        }

        // Get count of image records
        $totalRecords = MediaMetadata::where('mime_type', 'like', 'image/%')->count();

        if ($totalRecords === 0) {
            $this->info('No image records found in media_metadata table');
            return 0;
        }

        $this->info("Found {$totalRecords} image records to process");

        if ($dryRun) {
            $this->info("DRY RUN: Would remove size '{$sizeName}' from {$totalRecords} images");
            $this->info("Sized files would be deleted from S3 storage");
            return 0;
        }

        if (!$force && !$this->confirm("Remove size '{$sizeName}' from {$totalRecords} images? This will delete the sized files.")) {
            return 0;
        }

        // Process images in chunks
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $notFound = 0;
        $failedFiles = [];

        $progressBar = $this->output->createProgressBar($totalRecords);
        $progressBar->start();

        MediaMetadata::where('mime_type', 'like', 'image/%')
            ->orderBy('id')
            ->chunk($chunkSize, function ($records) use ($sizeName, &$processed, &$succeeded, &$failed, &$notFound, &$failedFiles, $progressBar) {
                foreach ($records as $record) {
                    try {
                        $result = $this->deleteSizedImage($record, $sizeName);
                        if ($result === 'not_found') {
                            $notFound++;
                        } else {
                            $succeeded++;
                        }
                    } catch (Exception $e) {
                        $failed++;
                        $failedFiles[] = [
                            'file' => $record->file_name,
                            'error' => $e->getMessage()
                        ];
                    }
                    
                    $processed++;
                    $progressBar->advance();
                }
            });

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Processing completed:");
        $this->info("- Total processed: {$processed}");
        $this->info("- Files deleted: {$succeeded}");
        $this->info("- Files not found: {$notFound}");
        $this->info("- Failed: {$failed}");

        if ($failed > 0) {
            $this->warn("Failed files (showing first 10):");
            foreach (array_slice($failedFiles, 0, 10) as $failure) {
                $this->line("  {$failure['file']}: {$failure['error']}");
            }
            
            if (count($failedFiles) > 10) {
                $remaining = count($failedFiles) - 10;
                $this->line("  ...and {$remaining} more");
            }
        }

        if ($succeeded > 0) {
            $this->info("Successfully removed '{$sizeName}' sized images for {$succeeded} files");
            $this->comment("Remember to remove the size from your config/file-manager.php if no longer needed:");
            $this->line("Remove: '{$sizeName}' => ...,");
        }

        return $failed > 0 ? 1 : 0;
    }

    private function createSizedImage(MediaMetadata $record, string $sizeName, int $height): void
    {
        // The file_name in MediaMetadata is actually the full S3 path
        $mainPath = $record->file_name;
        if (!$mainPath) {
            throw new Exception('No file path found in record');
        }

        // Get compression settings from config
        $format = config('file-manager.compression.format', 'webp');
        $quality = (int) config('file-manager.compression.quality', 85);

        // Get the file content from S3
        if (!Storage::disk('s3')->exists($mainPath)) {
            throw new Exception("Main image file not found in S3: {$mainPath}");
        }

        $fileContent = Storage::disk('s3')->get($mainPath);

        // Use Intervention Image to resize
        $img = ImageManager::gd()->read($fileContent);

        // Calculate width maintaining aspect ratio
        $originalWidth = $img->width();
        $originalHeight = $img->height();
        $width = (int) round(($originalWidth / $originalHeight) * $height);

        // Resize the image using contain mode (maintains aspect ratio, fits within bounds)
        $img->scaleDown($width, $height);

        // Convert to desired format
        $resizedContent = match($format) {
            'webp' => $img->toWebp($quality)->toString(),
            'jpg', 'jpeg' => $img->toJpeg($quality)->toString(),
            'png' => $img->toPng()->toString(),
            'avif' => $img->toAvif($quality)->toString(),
            default => $img->toWebp($quality)->toString(),
        };

        // Generate the sized image path
        $pathInfo = pathinfo($mainPath);
        $directory = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        $extension = $pathInfo['extension'];
        
        // Use the configured format extension if different from original
        $newExtension = $format === 'preserve' ? $extension : $format;
        
        $sizedPath = "{$directory}/{$filename}_{$sizeName}.{$newExtension}";

        // Save the resized image to S3
        $saved = Storage::disk('s3')->put($sizedPath, $resizedContent, [
            'visibility' => 'public',
            'ContentType' => $this->getContentType($newExtension),
        ]);

        if (!$saved) {
            throw new Exception('Failed to save resized image to S3');
        }
    }

    private function deleteSizedImage(MediaMetadata $record, string $sizeName): string
    {
        // The file_name in MediaMetadata is actually the full S3 path
        $mainPath = $record->file_name;
        if (!$mainPath) {
            throw new Exception('No file path found in record');
        }

        // Generate possible sized image paths (check different extensions)
        $pathInfo = pathinfo($mainPath);
        $directory = $pathInfo['dirname'];
        $filename = $pathInfo['filename'];
        
        $extensions = ['webp', 'jpg', 'jpeg', 'png', 'avif'];
        $deleted = false;

        foreach ($extensions as $ext) {
            $sizedPath = "{$directory}/{$filename}_{$sizeName}.{$ext}";
            
            if (Storage::disk('s3')->exists($sizedPath)) {
                Storage::disk('s3')->delete($sizedPath);
                $deleted = true;
            }
        }

        return $deleted ? 'deleted' : 'not_found';
    }

    private function getContentType(string $extension): string
    {
        return match($extension) {
            'jpeg', 'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            default => 'image/webp',
        };
    }
}