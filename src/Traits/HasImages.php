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

    /**
     * Define which fields contain video files
     * Override this method in your model to specify video fields
     */
    public function hasVideosTraitFields(): array
    {
        return [];
    }

    /**
     * Check if a field is a video field
     */
    public function isVideoField(string $field): bool
    {
        return in_array($field, $this->hasVideosTraitFields());
    }

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

                // Skip video fields from image resizing
                if (method_exists($model, 'isVideoField') && $model->isVideoField($field)) {
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

            // Watch both image and video fields for metadata
            $fieldsToWatch = $model->hasImagesTraitFields();
            if (method_exists($model, 'hasVideosTraitFields')) {
                $fieldsToWatch = array_merge($fieldsToWatch, $model->hasVideosTraitFields());
            }

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

            // Watch both image and video fields
            $fieldsToWatch = $model->hasImagesTraitFields();
            if (method_exists($model, 'hasVideosTraitFields')) {
                $fieldsToWatch = array_merge($fieldsToWatch, $model->hasVideosTraitFields());
            }

            foreach ($fieldsToWatch as $field) {
                // TODO: Auto Deleting non-images is no longer working since we are skipping the whole process here
                if (static::shouldExcludeConversion($model, $field)) {
                    continue;
                }

                if ($model->isDirty($field)) {
                    $oldFilename = $model->getOriginal($field);
                    $newFilename = $model->{$field};

                    // Skip image resizing for video fields
                    $isVideoField = method_exists($model, 'isVideoField') && $model->isVideoField($field);

                    // Handle resizing ONLY truly new images (skip for video fields)
                    // Only dispatch resize if image_sizes config is not empty
                    $sizes = FileManagerService::getImageSizes();
                    if (! empty($sizes) && ! $isVideoField) {
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

        // Update SEO titles when model's SEO field changes
        self::updated(function (self $model) {
            // Check if model has seoTitleField method (indicates it wants SEO titles)
            if (method_exists($model, 'seoTitleField')) {
                $seoField = $model->seoTitleField();

                // Check if the SEO field was changed
                if ($model->wasChanged($seoField)) {
                    static::updateMediaSeoTitles($model);
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
        $modelClass = get_class($this);

        // Use the full class name to look up in config
        $modelAlias = config("file-manager.model.{$modelClass}");

        // If not found in config, use a fallback
        if (! $modelAlias) {
            $modelKey = class_basename($this);
            $modelAlias = Str::plural(Str::lower($modelKey));
        }

        if ($this->{$field}) {
            // Use slug if available, otherwise use ID
            $identifier = isset($this->slug) && ! empty($this->slug) ? $this->slug : $this->id;

            // Generate a URL matching your route: /media-page/{model}/{slug}
            if ($counter) {
                return route('media.page', [
                    'directory' => $modelAlias,
                    'slug' => $identifier,
                    'counter' => $counter,
                    'field' => $field,
                ]);
            }

            return route('media.page', [
                'directory' => $modelAlias,
                'slug' => $identifier,
                'field' => $field,
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

            // Get file content for better mime type detection
            $fileContent = $disk->get($path);

            // Create a temporary file for accurate mime type and dimension detection
            $tempPath = tempnam(sys_get_temp_dir(), 'media_');
            file_put_contents($tempPath, $fileContent);

            // Use multiple methods to detect MIME type for better accuracy
            $mimeType = static::detectMimeType($tempPath, $path);

            // Get dimensions if it's an image
            $width = null;
            $height = null;

            if (str_starts_with($mimeType, 'image/')) {
                try {
                    if ($imageInfo = getimagesize($tempPath)) {
                        $width = $imageInfo[0];
                        $height = $imageInfo[1];

                        // getimagesize also returns mime type, use it as fallback if needed
                        if (! empty($imageInfo['mime']) && $mimeType === 'application/octet-stream') {
                            $mimeType = $imageInfo['mime'];
                        }
                    }
                } catch (Exception $e) {
                    // Ignore dimension errors
                }
            }

            // Clean up temp file
            if (file_exists($tempPath)) {
                unlink($tempPath);
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

    /**
     * Detect MIME type using multiple methods for better accuracy
     */
    protected static function detectMimeType(string $tempPath, string $originalPath): string
    {
        // Get file extension first
        $extension = strtolower(pathinfo($originalPath, PATHINFO_EXTENSION));

        // Priority 1: Extension-based detection for known problematic formats
        // WebP and AVIF can be misidentified by other methods
        if ($extension === 'webp') {
            return 'image/webp';
        }
        if ($extension === 'avif') {
            return 'image/avif';
        }

        // Priority 2: Use finfo (most reliable for actual file content)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tempPath);
            finfo_close($finfo);

            // Don't trust finfo for WebP/AVIF - it often gets them wrong
            if ($mimeType && $mimeType !== 'application/octet-stream') {
                // Double-check: if extension is webp but mime is avif, trust extension
                if ($extension === 'webp' && $mimeType === 'image/avif') {
                    return 'image/webp';
                }
                if ($extension === 'avif' && $mimeType === 'image/webp') {
                    return 'image/avif';
                }

                return $mimeType;
            }
        }

        // Priority 3: Use mime_content_type (fallback)
        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($tempPath);
            if ($mimeType && $mimeType !== 'application/octet-stream') {
                // Same double-check for WebP/AVIF
                if ($extension === 'webp' && $mimeType === 'image/avif') {
                    return 'image/webp';
                }
                if ($extension === 'avif' && $mimeType === 'image/webp') {
                    return 'image/avif';
                }

                return $mimeType;
            }
        }

        // Priority 4: For images, try getimagesize
        $imageInfo = @getimagesize($tempPath);
        if ($imageInfo && ! empty($imageInfo['mime'])) {
            // Same double-check for WebP/AVIF
            if ($extension === 'webp' && $imageInfo['mime'] !== 'image/webp') {
                return 'image/webp';
            }
            if ($extension === 'avif' && $imageInfo['mime'] !== 'image/avif') {
                return 'image/avif';
            }

            return $imageInfo['mime'];
        }

        // Priority 5: Fallback to extension-based detection
        $extensionMimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp',
            'ico' => 'image/x-icon',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain',
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'mp4' => 'video/mp4',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
        ];

        if (isset($extensionMimeTypes[$extension])) {
            return $extensionMimeTypes[$extension];
        }

        // Last resort: use Storage facade's mime type
        try {
            $storageMimeType = Storage::disk()->mimeType($originalPath);
            if ($storageMimeType) {
                return $storageMimeType;
            }
        } catch (Exception $e) {
            // Ignore and use default
        }

        // Default fallback
        return 'application/octet-stream';
    }

    /**
     * Update SEO titles for all media metadata associated with this model
     */
    protected static function updateMediaSeoTitles($model): void
    {
        // Get all media metadata for this model
        $mediaRecords = MediaMetadata::where('mediable_type', get_class($model))
            ->where('mediable_id', $model->id)
            ->get();

        if ($mediaRecords->isEmpty()) {
            return;
        }

        foreach ($mediaRecords as $media) {
            $newSeoTitle = static::generateSeoTitle($model, $media);

            // Only update if the SEO title has changed
            if ($newSeoTitle && $newSeoTitle !== $media->seo_title) {
                $media->update(['seo_title' => $newSeoTitle]);
            }
        }
    }

    /**
     * Generate SEO title for media based on parent model
     */
    protected static function generateSeoTitle($model, MediaMetadata $media): ?string
    {
        // Get the SEO title field for this model
        $seoField = method_exists($model, 'seoTitleField') ? $model->seoTitleField() : 'name';

        if (isset($model->$seoField) && ! empty($model->$seoField)) {
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
                $fieldContext = static::getFieldContext($field);
                if ($fieldContext && ! str_contains(strtolower($value), strtolower($fieldContext))) {
                    $value = mb_substr($value . ' - ' . $fieldContext, 0, 160);
                }
            }

            // Clean SEO title
            $value = mb_substr($value, 0, 160);

            // Remove all control characters (0x00-0x1F, 0x7F) except tab, newline, and carriage return
            // These characters are invalid in XML
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

            // Remove non-word characters from start and end
            $value = preg_replace('/^[^\w\s]+|[^\w\s]+$/u', '', $value);

            // Collapse multiple spaces
            $value = preg_replace('/\s+/', ' ', $value);

            return trim($value);
        }

        return null;
    }

    /**
     * Get field context for SEO title
     */
    protected static function getFieldContext(string $field): ?string
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
}
