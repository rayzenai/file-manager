<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Commands;

use Illuminate\Console\Command;
use Kirantimsina\FileManager\Jobs\PopulateMediaMetadataJob;

class PopulateMediaMetadataCommand extends Command
{
    protected $signature = 'file-manager:populate-metadata 
                            {--model= : Specific model to process (e.g., Product or App\\Models\\Product)}
                            {--field= : Specific field to process (e.g., image_file_name)}
                            {--chunk=1000 : Number of records to process per job}
                            {--dry-run : Show what would be processed without actually doing it}
                            {--sync : Process synchronously without using queue}';

    protected $description = 'Populate media metadata for existing images in configured models';

    public function handle(): void
    {
        $this->info('Starting media metadata population...');

        $models = $this->getModelsToProcess();
        $chunkSize = (int) $this->option('chunk');
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $totalJobsDispatched = 0;

        foreach ($models as $modelClass => $directory) {
            if (! class_exists($modelClass)) {
                $this->error("Model class {$modelClass} does not exist. Skipping...");

                continue;
            }

            $model = new $modelClass;

            // Check if model has HasMultimedia trait
            if (! method_exists($model, 'mediaFieldsToWatch')) {
                $this->warn("Model {$modelClass} does not use HasMultimedia trait. Skipping...");

                continue;
            }

            $fields = $this->getFieldsToProcess($model);

            $this->info("Processing model: {$modelClass}");
            $this->info('Fields to process: ' . implode(', ', $fields));

            // Get total count
            $totalRecords = $modelClass::query()->count();

            if ($totalRecords === 0) {
                $this->info("No records found for {$modelClass}. Skipping...");

                continue;
            }

            $this->info("Total records: {$totalRecords}");

            if ($isDryRun) {
                $this->table(
                    ['Model', 'Directory', 'Fields', 'Total Records', 'Estimated Jobs'],
                    [[
                        $modelClass,
                        $directory,
                        implode(', ', $fields),
                        $totalRecords,
                        ceil($totalRecords / $chunkSize),
                    ]]
                );

                continue;
            }

            // Process in chunks
            $bar = $this->output->createProgressBar($totalRecords);
            $bar->start();

            $modelClass::query()
                ->select('id', ...$fields)
                ->chunk($chunkSize, function ($records) use ($modelClass, $fields, &$totalJobsDispatched, $bar) {
                    // Dispatch job for this chunk
                    // Using dispatchSync for immediate processing (avoids serialization issues)
                    if ($this->option('sync')) {
                        PopulateMediaMetadataJob::dispatchSync(
                            $modelClass,
                            $records->pluck('id')->toArray(),
                            $fields
                        );
                    } else {
                        PopulateMediaMetadataJob::dispatch(
                            $modelClass,
                            $records->pluck('id')->toArray(),
                            $fields
                        );
                    }

                    $totalJobsDispatched++;
                    $bar->advance($records->count());
                });

            $bar->finish();
            $this->newLine();
            $this->info("Dispatched jobs for {$modelClass}");
        }

        if (! $isDryRun) {
            $this->info("Total jobs dispatched: {$totalJobsDispatched}");
            if ($this->option('sync')) {
                $this->info('Media metadata population completed synchronously.');
            } else {
                $this->info('Media metadata population jobs have been queued. Run `php artisan queue:work` to process them.');
            }
        }
    }

    private function getModelsToProcess(): array
    {
        $configuredModels = config('file-manager.model', []);

        if ($specificModel = $this->option('model')) {
            // Process only the specified model
            // Check if the specific model is already a class name
            if (class_exists($specificModel)) {
                $modelClass = $specificModel;
            } else {
                // Try with App\Models namespace
                $modelClass = "App\\Models\\{$specificModel}";
            }

            // Find the directory for this model
            $directory = null;
            foreach ($configuredModels as $key => $dir) {
                // Skip non-model entries like 'temp'
                if ($key === 'temp' || is_numeric($key)) {
                    continue;
                }

                // Check if key matches our model class
                if ($key === $modelClass ||
                    $key === $specificModel ||
                    (class_exists($key) && $key === $modelClass) ||
                    (class_exists($key) && class_basename($key) === $specificModel)) {
                    $directory = $dir;
                    break;
                }
            }

            if (! $directory) {
                $this->error("Model {$specificModel} is not configured in file-manager.model config");

                return [];
            }

            return [$modelClass => $directory];
        }

        // Process all configured models
        $models = [];
        foreach ($configuredModels as $modelKey => $directory) {
            // Skip non-model entries like 'temp'
            if ($modelKey === 'temp' || is_numeric($modelKey)) {
                continue;
            }

            // Check if the key is already a class (using ::class syntax in config)
            if (class_exists($modelKey)) {
                $models[$modelKey] = $directory;
            } else {
                // Legacy support: treat as string model name
                $modelClass = "App\\Models\\{$modelKey}";
                if (class_exists($modelClass)) {
                    $models[$modelClass] = $directory;
                }
            }
        }

        return $models;
    }

    private function getFieldsToProcess($model): array
    {
        if ($specificField = $this->option('field')) {
            return [$specificField];
        }

        // Get fields from HasMultimedia trait
        if (method_exists($model, 'mediaFieldsToWatch')) {
            $fields = $model->mediaFieldsToWatch();

            return array_merge(
                $fields['images'] ?? [],
                $fields['videos'] ?? [],
                $fields['documents'] ?? []
            );
        }

        return [];
    }
}
