<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Traits;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Log;
use Kirantimsina\FileManager\FileManagerService;
use Kirantimsina\FileManager\Models\MediaMetadata;

/**
 * Apply this trait to models that have media files which should be cleaned up
 * based on age. The model must also use HasMultimedia trait and define
 * mediaFieldsToWatch() and prunableMediaPeriod().
 *
 * Example in your model:
 *
 *   use HasMultimedia, PrunableMedia;
 *
 *   public static function prunableMediaPeriod(): int
 *   {
 *       return 365; // days - files older than this will be deleted
 *   }
 *
 *   public function mediaFieldsToWatch(): array
 *   {
 *       return [
 *           'images' => ['profile_photo', 'cover_image'],
 *           'documents' => ['contract_pdf'],
 *       ];
 *   }
 */
trait PrunableMedia
{
    /**
     * Boot the PrunableMedia trait.
     */
    public static function bootPrunableMedia(): void
    {
        // No model events needed - cleanup is handled by the cleanup command
    }

    /**
     * Get the period in days after which media files should be pruned.
     * Override this method in your model.
     */
    public static function prunableMediaPeriod(): int
    {
        return 365;
    }

    /**
     * Get all media files for this model instance that are older than the prune period.
     * Returns array of file paths to delete.
     *
     * @return array<string>
     */
    public function getMediaToPrune(): array
    {
        $fields = $this->mediaFieldsToWatch();
        $allFields = array_merge(
            $fields['images'] ?? [],
            $fields['videos'] ?? [],
            $fields['documents'] ?? []
        );

        $files = [];

        foreach ($allFields as $field) {
            $value = $this->{$field} ?? null;

            if (empty($value)) {
                continue;
            }

            // Handle both string and array field values
            $fieldFiles = is_array($value) ? $value : [$value];

            foreach ($fieldFiles as $file) {
                if (! empty($file) && is_string($file)) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    /**
     * Get the MediaMetadata records for this model that haven't been deleted yet.
     *
     * @return MorphMany<MediaMetadata>
     */
    public function mediaMetadata(): MorphMany
    {
        return $this->morphMany(MediaMetadata::class, 'mediable');
    }

    /**
     * Mark all media for this model as pruned in the database.
     */
    public function markMediaAsPruned(): void
    {
        $this->mediaMetadata()
            ->whereNull('storage_deleted_at')
            ->update(['storage_deleted_at' => now()]);
    }

    /**
     * Get models that have media older than the prune period.
     * Used by the cleanup command to discover what to prune.
     *
     * @param  int  $ageInDays  Override the model's prunableMediaPeriod
     * @return Collection<Model>
     */
    public static function getModelsWithOldMedia(int $ageInDays): Collection
    {
        $threshold = now()->subDays($ageInDays)->startOfDay();

        return static::where('created_at', '<', $threshold)
            ->whereHas('mediaMetadata', function ($query) {
                $query->whereNull('storage_deleted_at');
            })
            ->with('mediaMetadata')
            ->get();
    }

    /**
     * Prune media for a single model instance.
     *
     * @param  string|null  $diskName  Disk to delete from
     * @return array{main: int, resized: int, errors: int}
     */
    public function pruneMedia(?string $diskName = null): array
    {
        $diskName = $diskName ?? config('filesystems.default', 's3');
        $filesToDelete = $this->getMediaToPrune();
        $mainDeleted = 0;
        $resizedDeleted = 0;
        $errors = 0;

        foreach ($filesToDelete as $file) {
            try {
                $result = FileManagerService::deleteImageWithSizes($file);
                $mainDeleted += $result['main'];
                $resizedDeleted += $result['resized'];
            } catch (\Exception $e) {
                $errors++;
                Log::error("Failed to delete media file: {$file}", [
                    'model' => static::class,
                    'id' => $this->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (count($filesToDelete) > 0) {
            $this->markMediaAsPruned();
            $this->clearPrunedMediaFiles();
        }

        return ['main' => $mainDeleted, 'resized' => $resizedDeleted, 'errors' => $errors];
    }

    /**
     * Clear file references after pruning so this record won't be re-selected.
     * Override in model if it stores files differently (e.g. in a JSON column).
     */
    public function clearPrunedMediaFiles(): void
    {
        // Default: subclasses can override if needed
    }
}
