<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kirantimsina\FileManager\Models\MediaMetadata;

class PopulateSeoTitlesCommand extends Command
{
    protected $signature = 'file-manager:populate-seo-titles 
                            {--chunk=100 : Number of records to process per batch}
                            {--dry-run : Show what would be processed without actually doing it}
                            {--model= : Specific model to process (e.g., Product)}
                            {--overwrite : Overwrite existing SEO titles}';

    protected $description = 'Populate SEO titles for media metadata based on parent model data';

    public function handle(): void
    {
        $this->info('Starting SEO title population for media metadata...');

        $chunkSize = (int) $this->option('chunk');
        $isDryRun = $this->option('dry-run');
        $specificModel = $this->option('model');
        $overwrite = $this->option('overwrite');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Build query
        $query = MediaMetadata::query()
            ->with('mediable')
            ->whereHas('mediable');

        // Filter by model if specified
        if ($specificModel) {
            $modelClass = class_exists($specificModel) ? $specificModel : "App\\Models\\{$specificModel}";
            if (class_exists($modelClass)) {
                // Check if this model has seoTitleField method
                if (!method_exists($modelClass, 'seoTitleField')) {
                    $this->error("Model {$modelClass} does not have seoTitleField() method, skipping.");
                    return;
                }
                $query->where('mediable_type', $modelClass);
                $this->info("Processing model: {$modelClass}");
            } else {
                $this->error("Model class {$specificModel} does not exist.");
                return;
            }
        }

        // Only process records without SEO titles unless overwrite is specified
        if (! $overwrite) {
            $query->whereNull('seo_title');
        }

        $totalRecords = $query->count();

        if ($totalRecords === 0) {
            $this->info('No media metadata records found that need SEO title population.');

            return;
        }

        // Show breakdown by model type
        $modelBreakdown = MediaMetadata::query()
            ->whereHas('mediable')
            ->select('mediable_type', DB::raw('COUNT(*) as count'))
            ->groupBy('mediable_type')
            ->get();

        $this->info("Found {$totalRecords} media metadata records to process:");
        foreach ($modelBreakdown as $breakdown) {
            $modelName = class_basename($breakdown->mediable_type);
            // Check if model has seoTitleField
            $hasMethod = method_exists($breakdown->mediable_type, 'seoTitleField');
            $status = $hasMethod ? '✓' : '✗';
            $this->info("  {$status} {$modelName}: {$breakdown->count} records");
        }
        $this->info('');

        // Filter to only models with seoTitleField method
        $modelsWithSeoField = $modelBreakdown
            ->filter(fn ($breakdown) => method_exists($breakdown->mediable_type, 'seoTitleField'))
            ->pluck('mediable_type')
            ->toArray();

        if (empty($modelsWithSeoField) && !$specificModel) {
            $this->warn('No models found with seoTitleField() method.');
            return;
        }

        // Update query to only include models with seoTitleField
        if (!$specificModel) {
            $query->whereIn('mediable_type', $modelsWithSeoField);
            $totalRecords = $query->count();
        }

        $bar = $this->output->createProgressBar($totalRecords);
        $bar->start();

        $processed = 0;
        $updated = 0;

        $query->chunkById($chunkSize, function (Collection $mediaRecords) use (&$processed, &$updated, $bar, $isDryRun) {
            foreach ($mediaRecords as $media) {
                // Skip if model doesn't have seoTitleField method
                $modelClass = $media->mediable_type;
                if (!method_exists($modelClass, 'seoTitleField')) {
                    $processed++;
                    $bar->advance();
                    continue;
                }
                
                $seoTitle = $this->generateSeoTitle($media);

                if ($seoTitle && !$isDryRun) {
                    $media->update(['seo_title' => $seoTitle]);
                    $updated++;
                } elseif ($seoTitle && $isDryRun) {
                    $updated++;
                }

                $processed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $this->info('Processing completed!');
        $this->info("Total processed: {$processed}");
        $this->info("Total updated: {$updated}");
    }

    private function generateSeoTitle(MediaMetadata $media): ?string
    {
        $model = $media->mediable;

        if (! $model) {
            return null;
        }

        // Check if model has seoTitleField method
        if (!method_exists($model, 'seoTitleField')) {
            return null;
        }

        // Get the field to use for SEO title
        $seoField = $model->seoTitleField();
        
        // Get the value from that field
        if (isset($model->$seoField) && !empty($model->$seoField)) {
            $value = $model->$seoField;
            
            // Clean up the value
            $value = strip_tags($value);
            $value = trim($value);
            
            // Skip if it's just numbers or too short
            if (strlen($value) < 3 || is_numeric($value)) {
                return null;
            }

            // Add field context if needed
            $field = $media->mediable_field;
            $contextualFields = ['thumbnail', 'gallery_images', 'sec_images', 'cover_image', 'banner_image'];
            
            if (in_array($field, $contextualFields)) {
                $fieldContext = $this->getFieldContext($field);
                if ($fieldContext && !str_contains(strtolower($value), strtolower($fieldContext))) {
                    $value = mb_substr($value . ' - ' . $fieldContext, 0, 160);
                }
            }

            // Clean and limit the SEO title
            return $this->cleanSeoTitle(mb_substr($value, 0, 160));
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
        // Trim whitespace
        $title = trim($title);
        
        // Remove all control characters (0x00-0x1F, 0x7F) except tab, newline, and carriage return
        // These characters are invalid in XML
        $title = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $title);
        
        // Remove special characters from the beginning and end
        // This includes quotes, apostrophes, brackets, and other punctuation
        $title = preg_replace('/^[^\w\s]+|[^\w\s]+$/u', '', $title);
        
        // Clean up multiple spaces
        $title = preg_replace('/\s+/', ' ', $title);
        
        // Final trim
        return trim($title);
    }
}