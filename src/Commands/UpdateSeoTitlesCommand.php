<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Kirantimsina\FileManager\Models\MediaMetadata;

class UpdateSeoTitlesCommand extends Command
{
    protected $signature = 'file-manager:update-seo-titles 
                            {--model= : Specific model to update (e.g., Product)}
                            {--id= : Specific model ID to update}
                            {--chunk=100 : Number of records to process per batch}';

    protected $description = 'Update SEO titles for media metadata when parent models have changed';

    public function handle(): void
    {
        $this->info('Updating SEO titles for media metadata...');

        $specificModel = $this->option('model');
        $specificId = $this->option('id');
        $chunkSize = (int) $this->option('chunk');

        // Get enabled models from config
        $enabledModels = config('file-manager.seo.enabled_models', []);
        $excludedModels = config('file-manager.seo.excluded_models', []);

        // Build query
        $query = MediaMetadata::query()
            ->with('mediable')
            ->whereHas('mediable')
            ->whereNotNull('seo_title'); // Only update existing SEO titles

        // Filter by specific model if provided
        if ($specificModel) {
            $modelClass = class_exists($specificModel) ? $specificModel : "App\\Models\\{$specificModel}";
            if (class_exists($modelClass)) {
                $query->where('mediable_type', $modelClass);

                // Filter by specific ID if provided
                if ($specificId) {
                    $query->where('mediable_id', $specificId);
                }
            } else {
                $this->error("Model class {$specificModel} does not exist.");

                return;
            }
        } else {
            // Only process enabled models by default
            if (! empty($enabledModels)) {
                $query->whereIn('mediable_type', $enabledModels);
            } elseif (! empty($excludedModels)) {
                $query->whereNotIn('mediable_type', $excludedModels);
            }
        }

        $totalRecords = $query->count();

        if ($totalRecords === 0) {
            $this->info('No media metadata records found that need updating.');

            return;
        }

        $this->info("Found {$totalRecords} media metadata records to check for updates.");

        $bar = $this->output->createProgressBar($totalRecords);
        $bar->start();

        $processed = 0;
        $updated = 0;

        $query->chunkById($chunkSize, function (Collection $mediaRecords) use (&$processed, &$updated, $bar) {
            foreach ($mediaRecords as $media) {
                $model = $media->mediable;

                if (! $model) {
                    $processed++;
                    $bar->advance();

                    continue;
                }

                // Generate new SEO title
                $newSeoTitle = $this->generateSeoTitle($model, $media);

                // Only update if the SEO title has changed
                if ($newSeoTitle && $newSeoTitle !== $media->seo_title) {
                    $media->update(['seo_title' => $newSeoTitle]);
                    $updated++;
                }

                $processed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $this->info('SEO title update completed!');
        $this->info("Total processed: {$processed}");
        $this->info("Total updated: {$updated}");
    }

    private function generateSeoTitle($model, MediaMetadata $media): ?string
    {
        $modelTitle = $this->extractModelTitle($model);

        if (! $modelTitle) {
            return null;
        }

        $field = $media->mediable_field;

        // For specific field types, we might want to add context
        $contextualFields = ['thumbnail', 'gallery_images', 'sec_images', 'cover_image', 'banner_image'];

        if (in_array($field, $contextualFields)) {
            $fieldContext = $this->getFieldContext($field);
            if ($fieldContext && ! str_contains(strtolower($modelTitle), strtolower($fieldContext))) {
                $title = mb_substr($modelTitle . ' - ' . $fieldContext, 0, 160);

                return $this->cleanSeoTitle($title);
            }
        }

        $title = mb_substr($modelTitle, 0, 160);

        return $this->cleanSeoTitle($title);
    }

    private function extractModelTitle($model): ?string
    {
        $titleFields = [
            'meta_title',
            'seo_title',
            'name',
            'title',
            'product_name',
            'display_name',
            'heading',
            'label',
        ];

        foreach ($titleFields as $field) {
            if (isset($model->$field) && ! empty($model->$field)) {
                $value = $model->$field;
                $value = strip_tags($value);
                $value = trim($value);

                if (strlen($value) < 3 || is_numeric($value)) {
                    continue;
                }

                return $value;
            }
        }

        return null;
    }

    private function getFieldContext(string $field): ?string
    {
        $fieldContextMap = [
            'featured_image' => 'Featured',
            'gallery_images' => 'Gallery',
            'thumbnail' => 'Thumbnail',
            'cover_image' => 'Cover',
            'banner_image' => 'Banner',
            'logo' => 'Logo',
            'profile_image' => 'Profile Picture',
            'avatar' => 'Avatar',
            'background_image' => 'Background',
            'hero_image' => 'Hero',
            'icon' => 'Icon',
            'sec_images' => 'Gallery',
        ];

        return $fieldContextMap[$field] ?? null;
    }

    private function cleanSeoTitle(string $title): string
    {
        $title = trim($title);
        $title = preg_replace('/^[^\w\s]+|[^\w\s]+$/u', '', $title);
        $title = preg_replace('/\s+/', ' ', $title);

        return trim($title);
    }
}
