<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Traits;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kirantimsina\FileManager\Facades\FileManager;
use Kirantimsina\FileManager\FileManagerService;
use Kirantimsina\FileManager\Jobs\DeleteImages;
use Kirantimsina\FileManager\Jobs\ResizeImages;
use Kirantimsina\FileManager\Models\MediaMetadata;

trait HasImages
{
    // TODO: this does not yet work for images of type array / json

    protected static function bootHasImages()
    {

        self::creating(function (self $model) {
            if (! method_exists($model, 'hasImagesTraitFields')) {
                throw new Exception('You must define a `hasImagesTraitFields` method in your model.');
            }

            $fieldsToWatch = $model->hasImagesTraitFields();
            foreach ($fieldsToWatch as $field) {

                if (static::shouldExcludeConversion($model, $field)) {
                    continue;
                }

                if ($model->{$field}) {
                    $newFilename = $model->{$field};
                    if (is_array($newFilename)) {
                        ResizeImages::dispatch($newFilename);
                    } else {
                        ResizeImages::dispatch([$newFilename]);
                    }
                }
            }
        });

        // Create media metadata after the model is created
        self::created(function (self $model) {
            if (! config('file-manager.media_metadata.enabled', true)) {
                return;
            }

            $fieldsToWatch = $model->hasImagesTraitFields();
            foreach ($fieldsToWatch as $field) {
                if (empty($model->{$field})) {
                    continue;
                }

                $images = is_array($model->{$field}) ? $model->{$field} : [$model->{$field}];

                foreach ($images as $image) {
                    if (empty($image) || ! is_string($image)) {
                        continue;
                    }

                    // Check if metadata already exists
                    $exists = MediaMetadata::where('mediable_type', get_class($model))
                        ->where('mediable_id', $model->id)
                        ->where('mediable_field', $field)
                        ->where('file_name', $image)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    // Get file info from storage
                    $fileInfo = static::getFileInfoForMetadata($image);

                    if (! $fileInfo) {
                        continue;
                    }

                    // Create media metadata record
                    MediaMetadata::create([
                        'mediable_type' => get_class($model),
                        'mediable_id' => $model->id,
                        'mediable_field' => $field,
                        'file_name' => $image,
                        'mime_type' => $fileInfo['mime_type'],
                        'file_size' => $fileInfo['size'],
                        'width' => $fileInfo['width'],
                        'height' => $fileInfo['height'],
                        'metadata' => [
                            'created_via' => 'HasImages trait',
                            'created_at' => now()->toIso8601String(),
                        ],
                    ]);
                }
            }
        });

        self::updating(function (self $model) {
            if (! method_exists($model, 'hasImagesTraitFields')) {
                throw new Exception('You must define a `hasImagesTraitFields` method in your model.');
            }

            $fieldsToWatch = $model->hasImagesTraitFields();

            foreach ($fieldsToWatch as $field) {
                // TODO: Auto Deleting non-images is no longer working since we are skipping the whole process here
                if (static::shouldExcludeConversion($model, $field)) {
                    continue;
                }

                if ($model->isDirty($field)) {
                    $oldFilename = $model->getOriginal($field);
                    $newFilename = $model->{$field};

                    // Handle resizing ONLY truly new images
                    if (is_array($newFilename) && is_array($oldFilename)) {
                        // Find images that are in new but not in old (newly added images)
                        $newlyAdded = array_diff($newFilename, $oldFilename);
                        if (! empty($newlyAdded)) {
                            ResizeImages::dispatch(array_values($newlyAdded));
                        }
                    } elseif (is_array($newFilename) && ! $oldFilename) {
                        // All images are new if there was no old value
                        ResizeImages::dispatch($newFilename);
                    } elseif ($newFilename && $newFilename !== $oldFilename) {
                        // Single new image that's different from the old one
                        ResizeImages::dispatch([$newFilename]);
                    }

                    // Handle deleting old images
                    if (is_array($oldFilename)) {
                        $toDelete = array_diff($oldFilename, (array) $newFilename);
                        if (! empty($toDelete)) {
                            DeleteImages::dispatch(array_values($toDelete));
                            // Delete metadata for removed images
                            static::deleteMetadataForImages($model, $field, array_values($toDelete));
                        }
                    } elseif ($oldFilename && $oldFilename !== $newFilename) {
                        // Only delete if the old file is different from the new one
                        DeleteImages::dispatch([$oldFilename]);
                        // Delete metadata for removed image
                        static::deleteMetadataForImages($model, $field, [$oldFilename]);
                    }
                }
            }
        });

        // Create media metadata after the model is updated
        self::updated(function (self $model) {
            if (! config('file-manager.media_metadata.enabled', true)) {
                return;
            }

            $fieldsToWatch = $model->hasImagesTraitFields();
            foreach ($fieldsToWatch as $field) {
                if (! $model->wasChanged($field)) {
                    continue;
                }

                $oldValue = $model->getOriginal($field);
                $newValue = $model->{$field};

                // Get newly added images
                $newImages = [];
                if (is_array($newValue)) {
                    if (is_array($oldValue)) {
                        $newImages = array_diff($newValue, $oldValue);
                    } else {
                        $newImages = $newValue;
                    }
                } elseif ($newValue && $newValue !== $oldValue) {
                    $newImages = [$newValue];
                }

                foreach ($newImages as $image) {
                    if (empty($image) || ! is_string($image)) {
                        continue;
                    }

                    // Check if metadata already exists
                    $exists = MediaMetadata::where('mediable_type', get_class($model))
                        ->where('mediable_id', $model->id)
                        ->where('mediable_field', $field)
                        ->where('file_name', $image)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    // Get file info from storage
                    $fileInfo = static::getFileInfoForMetadata($image);

                    if (! $fileInfo) {
                        continue;
                    }

                    // Create media metadata record
                    MediaMetadata::create([
                        'mediable_type' => get_class($model),
                        'mediable_id' => $model->id,
                        'mediable_field' => $field,
                        'file_name' => $image,
                        'mime_type' => $fileInfo['mime_type'],
                        'file_size' => $fileInfo['size'],
                        'width' => $fileInfo['width'],
                        'height' => $fileInfo['height'],
                        'metadata' => [
                            'created_via' => 'HasImages trait (update)',
                            'created_at' => now()->toIso8601String(),
                        ],
                    ]);
                }
            }
        });

        // Delete media metadata when the model is deleted
        self::deleting(function (self $model) {
            if (! config('file-manager.media_metadata.enabled', true)) {
                return;
            }

            // Delete all metadata for this model
            MediaMetadata::where('mediable_type', get_class($model))
                ->where('mediable_id', $model->id)
                ->delete();
        });

    }

    public function viewPageUrl(string $field = 'file', string $counter = ''): ?string
    {
        $modelKey = class_basename($this);

        $modelAlias = config("file-manager.model.{$modelKey}", Str::plural(Str::lower($modelKey)));

        if ($this->{$field}) {
            // Generate a URL matching your route: /media-page/{model}/{slug}
            if ($counter) {
                return route('media.page', [
                    'directory' => $modelAlias,
                    'slug' => $this->slug,
                    'counter' => $counter,
                ]);
            }

            return route('media.page', [
                'directory' => $modelAlias,
                'slug' => $this->slug,
            ]);

        }

        return null;
    }

    public function uploadFromUrl(string $url, Model $record, string $field): string
    {
        $content = Http::get($url)->body();

        $tempFileName = uniqid() . '_' . basename($url);

        $tempFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempFileName;

        File::put($tempFilePath, $content);

        $mimeType = mime_content_type($tempFilePath) ?: 'application/octet-stream';

        // Create the UploadedFile instance
        $uploadedFile = new UploadedFile(
            $tempFilePath,
            basename($tempFilePath),
            $mimeType,
            null,
            true
        );

        $model = class_basename($record);

        $upload = FileManagerService::upload(
            model: $model,
            file: $uploadedFile,
            tag: isset($record->name) ? $record->name : null,
        );

        if ($upload['status']) {
            $record->update([
                $field => $upload['file'],
            ]);
        }

        return $upload['file'];
    }

    private static function shouldExcludeConversion(Model $model, string $field)
    {
        $filenames = $model->{$field};

        if (empty($filenames)) {
            return false;
        }

        if (is_string($model->{$field})) {
            $filenames = [$model->{$field}];
        }

        foreach ($filenames as $filename) {
            $ext = FileManager::getExtensionFromName($filename);

            return in_array($ext, [
                'mp4',
                'mpeg',
                'mov',
                'avi',
                'webm',
            ]);
        }

    }

    /**
     * Delete metadata for specific images
     */
    protected static function deleteMetadataForImages($model, string $field, array $images): void
    {
        if (! config('file-manager.media_metadata.enabled', true) || empty($images)) {
            return;
        }

        MediaMetadata::where('mediable_type', get_class($model))
            ->where('mediable_id', $model->id)
            ->where('mediable_field', $field)
            ->whereIn('file_name', $images)
            ->delete();
    }

    /**
     * Get file info for media metadata
     */
    protected static function getFileInfoForMetadata(string $path): ?array
    {
        try {
            $disk = Storage::disk();

            if (! $disk->exists($path)) {
                return null;
            }

            $size = $disk->size($path);
            $mimeType = $disk->mimeType($path);

            // Get dimensions if it's an image
            $width = null;
            $height = null;

            if (str_starts_with($mimeType, 'image/')) {
                try {
                    // Download file temporarily to get dimensions
                    $tempPath = tempnam(sys_get_temp_dir(), 'img');
                    file_put_contents($tempPath, $disk->get($path));

                    if ($imageInfo = getimagesize($tempPath)) {
                        $width = $imageInfo[0];
                        $height = $imageInfo[1];
                    }

                    unlink($tempPath);
                } catch (Exception $e) {
                    // Ignore dimension errors
                }
            }

            return [
                'size' => $size,
                'mime_type' => $mimeType,
                'width' => $width,
                'height' => $height,
            ];
        } catch (Exception $e) {
            return null;
        }
    }
}
