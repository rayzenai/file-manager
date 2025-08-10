<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Commands;

use Illuminate\Console\Command;
use Kirantimsina\FileManager\Jobs\PopulateMediaMetadataJob;

class PopulateMediaMetadataCommand extends Command
{
    protected $signature = 'file-manager:populate-metadata 
                            {--model= : Specific model to process (e.g., Product)}
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

            // Check if model has HasImages trait
            if (! method_exists($model, 'hasImagesTraitFields')) {
                $this->warn("Model {$modelClass} does not use HasImages trait. Skipping...");

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
            $modelClass = "App\\Models\\{$specificModel}";
            $directory = $configuredModels[$specificModel] ?? null;

            if (! $directory) {
                $this->error("Model {$specificModel} is not configured in file-manager.model config");

                return [];
            }

            return [$modelClass => $directory];
        }

        // Process all configured models
        $models = [];
        foreach ($configuredModels as $modelName => $directory) {
            $modelClass = "App\\Models\\{$modelName}";
            $models[$modelClass] = $directory;
        }

        return $models;
    }

    private function getFieldsToProcess($model): array
    {
        if ($specificField = $this->option('field')) {
            return [$specificField];
        }

        // Get fields from HasImages trait
        if (method_exists($model, 'hasImagesTraitFields')) {
            return $model->hasImagesTraitFields();
        }

        return [];
    }
}
