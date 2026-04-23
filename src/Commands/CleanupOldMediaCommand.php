<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Kirantimsina\FileManager\Traits\PrunableMedia;

class CleanupOldMediaCommand extends Command
{
    protected $signature = 'file-manager:cleanup-old-media
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--model= : Specific model class to clean (optional - discovers all models with PrunableMedia trait if not set)}
                            {--disk= : Specific disk to clean (s3 or r2), otherwise uses default}
                            {--age= : Override the age in days (otherwise uses each model\'s prunableMediaPeriod())}';

    protected $description = 'Delete old media files from storage for models using the PrunableMedia trait';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $modelClass = $this->option('model');
        $diskName = $this->option('disk') ?? config('filesystems.default', 's3');
        $ageOverride = $this->option('age') ? (int) $this->option('age') : null;

        $this->info('Starting media cleanup...');
        $this->info("Disk: {$diskName}");

        if ($dryRun) {
            $this->warn('DRY RUN - No files will be deleted');
        }

        if ($modelClass) {
            $this->processModel($modelClass, $diskName, $dryRun, $ageOverride);
        } else {
            $this->discoverAndProcessModels($diskName, $dryRun, $ageOverride);
        }

        $this->newLine();
        $this->info('Media cleanup complete.');

        return self::SUCCESS;
    }

    /**
     * Discover all models using the PrunableMedia trait and process them.
     */
    private function discoverAndProcessModels(string $diskName, bool $dryRun, ?int $ageOverride): void
    {
        $discoveredModels = $this->discoverPrunableMediaModels();

        if (empty($discoveredModels)) {
            $this->warn('No models with PrunableMedia trait found.');

            return;
        }

        $this->info('Found ' . count($discoveredModels) . ' model(s) with PrunableMedia trait:');
        foreach ($discoveredModels as $modelClass) {
            $this->line("  - {$modelClass}");
        }
        $this->newLine();

        foreach ($discoveredModels as $modelClass) {
            $this->processModel($modelClass, $diskName, $dryRun, $ageOverride);
        }
    }

    /**
     * Discover all model classes in the application that use the PrunableMedia trait.
     *
     * @return array<class-string>
     */
    private function discoverPrunableMediaModels(): array
    {
        $models = [];

        // Get all models from the application
        $modelDirectories = [
            app_path('Models'),
        ];

        // Also check packages if configured
        if (config('file-manager.prunable_models_path')) {
            $modelDirectories[] = config('file-manager.prunable_models_path');
        }

        foreach ($modelDirectories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $files = glob($directory . '/*.php');
            foreach ($files as $file) {
                $className = $this->getModelClassFromFile($file);
                if ($className && $this->modelUsesPrunableMediaTrait($className)) {
                    $models[] = $className;
                }
            }
        }

        return array_unique($models);
    }

    /**
     * Get the fully qualified class name from a file path.
     */
    private function getModelClassFromFile(string $file): ?string
    {
        $namespace = app()->getNamespace();
        $relativePath = str_replace(app_path('Models') . DIRECTORY_SEPARATOR, '', $file);
        $className = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
        $className = $namespace . '\\Models\\' . str_replace('.php', '', $className);

        if (! class_exists($className)) {
            return null;
        }

        return $className;
    }

    /**
     * Check if a model class uses the PrunableMedia trait.
     */
    private function modelUsesPrunableMediaTrait(string $className): bool
    {
        $traits = class_uses_recursive($className);

        return in_array(PrunableMedia::class, $traits);
    }

    /**
     * Process a specific model for cleanup.
     */
    private function processModel(string $modelClass, string $diskName, bool $dryRun, ?int $ageOverride): void
    {
        if (! class_exists($modelClass)) {
            $this->error("Model class {$modelClass} does not exist.");

            return;
        }

        $ageInDays = $ageOverride ?? $modelClass::prunableMediaPeriod();

        $this->info("Processing {$modelClass} (age threshold: {$ageInDays} days)...");

        /** @var Collection<Model> $models */
        $models = $modelClass::getModelsWithOldMedia($ageInDays);

        if ($models->isEmpty()) {
            $this->info("  No media to clean for {$modelClass}");

            return;
        }

        $this->info("  Found {$models->count()} {$modelClass} record(s) with old media");

        if ($dryRun) {
            foreach ($models as $model) {
                $files = $model->getMediaToPrune();
                foreach ($files as $file) {
                    $this->line("    [DRY] Would delete: {$file}");
                }
            }

            return;
        }

        $totalDeleted = 0;
        $totalErrors = 0;

        foreach ($models as $model) {
            $result = $model->pruneMedia($diskName);
            $totalDeleted += $result['files_deleted'];
            $totalErrors += $result['errors'];

            if ($result['files_deleted'] > 0) {
                $this->line("    Pruned {$result['files_deleted']} file(s) for {$modelClass} #{$model->id}");
            }
        }

        $this->info("  Summary for {$modelClass}: deleted={$totalDeleted}, errors={$totalErrors}");
    }
}
