<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Traits;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kirantimsina\FileManager\FileManagerService;
use Kirantimsina\FileManager\Jobs\DeleteMedia;
use Kirantimsina\FileManager\Jobs\ResizeImages;
use Kirantimsina\FileManager\Models\MediaMetadata;
use Kirantimsina\FileManager\Services\FileInfoService;

trait HasMultimedia
{
    /**
     * Define which fields contain media files and their types
     * Override this method in your model to specify media fields
     *
     * @return array{images?: array, videos?: array, documents?: array}
     */
    public function mediaFieldsToWatch(): array
    {
        return [
            'images' => [],
            'videos' => [],
            'documents' => [],
        ];
    }

    /**
     * Get all media fields regardless of type
     */
    public function getAllMediaFields(): array
    {
        $fields = $this->mediaFieldsToWatch();

        return array_merge(
            $fields['images'] ?? [],
            $fields['videos'] ?? [],
            $fields['documents'] ?? []
        );
    }

    /**
     * Check if a field is an image field
     */
    public function isImageField(string $field): bool
    {
        $fields = $this->mediaFieldsToWatch();

        return in_array($field, $fields['images'] ?? []);
    }

    /**
     * Check if a field is a video field
     */
    public function isVideoField(string $field): bool
    {
        $fields = $this->mediaFieldsToWatch();

        return in_array($field, $fields['videos'] ?? []);
    }

    /**
     * Check if a field is a document field
     */
    public function isDocumentField(string $field): bool
    {
        $fields = $this->mediaFieldsToWatch();

        return in_array($field, $fields['documents'] ?? []);
    }

    protected static function bootHasMultimedia()
    {
        self::creating(function (self $model) {
            $mediaFields = $model->mediaFieldsToWatch();
            $imageFields = $mediaFields['images'] ?? [];

            foreach ($imageFields as $field) {
                if (static::shouldExcludeConversion($model, $field)) {
                    continue;
                }

                if ($model->{$field}) {
                    // Only dispatch resize if image_sizes config is not empty
                    $sizes = FileManagerService::getImageSizes();
                    if (! empty($sizes)) {
                        $newFilename = $model->{$field};
                        if (is_array($newFilename)) {
                            ResizeImages::dispatch($newFilename);
                        } else {
                            ResizeImages::dispatch([$newFilename]);
                        }
                    }
                }
            }
        });

        // Create media metadata after the model is created
        self::created(function (self $model) {
            if (! config('file-manager.media_metadata.enabled', true)) {
                return;
            }

            $fieldsToWatch = $model->getAllMediaFields();

            foreach ($fieldsToWatch as $field) {
                if (empty($model->{$field})) {
                    continue;
                }

                $images = is_array($model->{$field}) ? $model->{$field} : [$model->{$field}];

                foreach ($images as $image) {
                    if (empty($image) || ! is_string($image)) {
                        continue;
                    }

                    // Skip external URLs (YouTube, Vimeo, etc.)
                    if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
                        continue;
                    }

                    // Get actual file information from storage using FileInfoService
                    $fileInfoService = new FileInfoService;
                    $fileInfo = $fileInfoService->getFileInfo($image, config('filesystems.default'));

                    if (! $fileInfo) {
                        // File doesn't exist or error reading
                        continue;
                    }

                    $fileSize = $fileInfo['size'];
                    $mimeType = $fileInfo['mime_type'];
                    $width = $fileInfo['width'];
                    $height = $fileInfo['height'];

                    // Get SEO title if model supports it
                    $seoTitle = null;
                    if (method_exists($model, 'seoTitleField')) {
                        $seoField = $model->seoTitleField();
                        $seoTitle = $model->{$seoField} ?? null;
                    }

                    $metadataToCreate = [
                        'mime_type' => $mimeType,
                        'file_size' => $fileSize,
                        'metadata' => [
                            'seo_title' => $seoTitle,
                            'created_at' => now()->toIso8601String(),
                        ],
                    ];

                    // Add dimensions for images
                    if ($width !== null && $height !== null) {
                        $metadataToCreate['width'] = $width;
                        $metadataToCreate['height'] = $height;
                    }

                    MediaMetadata::updateOrCreate([
                        'mediable_type' => get_class($model),
                        'mediable_id' => $model->id,
                        'mediable_field' => $field,
                        'file_name' => $image,
                    ], $metadataToCreate);
                }
            }
        });

        self::updating(function (self $model) {
            $fieldsToWatch = $model->getAllMediaFields();

            foreach ($fieldsToWatch as $field) {
                if (static::shouldExcludeConversion($model, $field)) {
                    continue;
                }

                if ($model->isDirty($field)) {
                    $oldFilename = $model->getOriginal($field);
                    $newFilename = $model->{$field};

                    // Skip image resizing for non-image fields
                    $isImageField = $model->isImageField($field);

                    // Handle resizing ONLY for image fields
                    $sizes = FileManagerService::getImageSizes();
                    if (! empty($sizes) && $isImageField) {
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
                    }

                    // Handle deleting old files (for all media types)
                    if (is_array($oldFilename)) {
                        $toDelete = array_diff($oldFilename, (array) $newFilename);
                        if (! empty($toDelete)) {
                            DeleteMedia::dispatch(array_values($toDelete));
                            // Delete metadata for removed files
                            static::deleteMetadataForImages($model, $field, array_values($toDelete));
                        }
                    } elseif ($oldFilename && $oldFilename !== $newFilename) {
                        // Only delete if the old file is different from the new one
                        DeleteMedia::dispatch([$oldFilename]);
                        // Delete metadata for removed file
                        static::deleteMetadataForImages($model, $field, [$oldFilename]);
                    }
                }
            }
        });

        // Update SEO titles when model's SEO field changes
        self::updated(function (self $model) {
            if (method_exists($model, 'seoTitleField')) {
                $seoField = $model->seoTitleField();

                if ($model->wasChanged($seoField)) {
                    static::updateMediaSeoTitles($model);
                }
            }
        });
    }

    protected static function shouldExcludeConversion($model, $field): bool
    {
        // Check if the field value is already a URL
        $value = $model->{$field};

        // If it's an array, check the first element
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        // Skip if it's already a URL
        if (is_string($value) && (str_starts_with($value, 'http://') || str_starts_with($value, 'https://'))) {
            return true;
        }

        return false;
    }

    protected static function deleteMetadataForImages($model, $field, array $images): void
    {
        if (! config('file-manager.media_metadata.enabled', true)) {
            return;
        }

        MediaMetadata::where('mediable_type', get_class($model))
            ->where('mediable_id', $model->id)
            ->where('mediable_field', $field)
            ->whereIn('file_name', $images)
            ->delete();
    }

    protected static function updateMediaSeoTitles($model): void
    {
        if (! config('file-manager.media_metadata.enabled', true)) {
            return;
        }

        $seoField = $model->seoTitleField();
        $newTitle = $model->{$seoField};

        // Update all media metadata for this model with the new SEO title
        MediaMetadata::where('mediable_type', get_class($model))
            ->where('mediable_id', $model->id)
            ->get()
            ->each(function ($metadata) use ($newTitle) {
                $meta = $metadata->metadata ?? [];
                $meta['seo_title'] = $newTitle;
                $metadata->metadata = $meta;
                $metadata->save();
            });
    }

    /**
     * Upload image from URL
     */
    public function uploadImageFromUrl(string $url, string $field, bool $skipResize = false): ?string
    {
        try {
            $response = Http::get($url);

            if (! $response->successful()) {
                return null;
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'download_');
            file_put_contents($tempFile, $response->body());

            $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
            if (! $extension) {
                $mimeType = $response->header('Content-Type');
                $extension = match ($mimeType) {
                    'image/jpeg', 'image/jpg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    default => 'jpg',
                };
            }

            $uploadedFile = new UploadedFile(
                $tempFile,
                basename($url),
                $response->header('Content-Type'),
                null,
                true
            );

            $modelName = class_basename($this);
            $directory = FileManagerService::getUploadDirectory($modelName);
            $filename = FileManagerService::filename($uploadedFile, $modelName, $extension);
            $fullPath = "{$directory}/{$filename}";

            Storage::disk(config('filesystems.default'))->put($fullPath, file_get_contents($tempFile), 'public');

            unlink($tempFile);

            $this->{$field} = $fullPath;
            $this->save();

            if (! $skipResize && $this->isImageField($field)) {
                $sizes = FileManagerService::getImageSizes();
                if (! empty($sizes)) {
                    ResizeImages::dispatch([$fullPath]);
                }
            }

            return $fullPath;
        } catch (Exception $e) {
            return null;
        }
    }
}
