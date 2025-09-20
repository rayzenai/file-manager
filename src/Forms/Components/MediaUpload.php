<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Forms\Components;

use Closure;
use Exception;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kirantimsina\FileManager\FileManagerService;
use Kirantimsina\FileManager\Jobs\CompressVideoJob;
use Kirantimsina\FileManager\Models\MediaMetadata;
use Kirantimsina\FileManager\Services\ImageCompressionService;
use Kirantimsina\FileManager\Services\VideoCompressionService;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class MediaUpload extends FileUpload
{
    /**
     * Whether to upload the original version without any processing.
     */
    protected bool $uploadOriginal = false;

    /**
     * Quality to use for compression.
     */
    protected ?int $quality = null;

    /**
     * Output format for compression.
     */
    protected ?string $format = null;

    /**
     * Whether to track media metadata
     */
    protected bool $trackMetadata = true;

    /**
     * Whether compression was used for the upload
     */
    protected bool $compressionUsed = false;

    /**
     * Whether to remove background from images
     */
    protected bool|Closure $removeBackground = false;

    /**
     * Compression driver to use (overrides config)
     */
    protected ?string $compressionDriver = null;

    /**
     * SEO title to use for the uploaded media
     */
    protected string|Closure|null $seoTitle = null;

    /**
     * Field name to use for SEO title from the model
     */
    protected ?string $seoTitleField = null;

    /**
     * Whether to compress videos
     */
    protected bool $compressVideo = false;

    /**
     * Video compression settings
     */
    protected ?string $videoFormat = null;

    protected ?int $videoBitrate = null;

    protected ?int $videoMaxWidth = null;

    protected ?int $videoMaxHeight = null;

    protected ?string $videoPreset = null;

    protected ?int $videoCrf = null;

    protected bool $videoAsync = true;

    /**
     * Set whether to upload original or resize.
     */
    public function uploadOriginal(bool $uploadOriginal = true): static
    {
        $this->uploadOriginal = $uploadOriginal;

        return $this;
    }

    /**
     * Set the compression quality (capped at 95).
     */
    public function quality(int $quality): static
    {
        // Cap quality at 95 to prevent unnecessarily large files
        $this->quality = min($quality, 95);

        return $this;
    }

    /**
     * Set the output format for compression.
     */
    public function format(string $format): static
    {
        if (! in_array($format, ['webp', 'jpeg', 'jpg', 'png', 'avif', 'original'])) {
            throw new \InvalidArgumentException("Invalid format: {$format}. Must be webp, jpeg, jpg, png, avif, or original.");
        }

        $this->format = $format;

        return $this;
    }

    /**
     * Convert images to WebP format.
     */
    public function toWebp(): static
    {
        return $this->format('webp');
    }

    /**
     * Convert images to AVIF format.
     */
    public function toAvif(): static
    {
        return $this->format('avif');
    }

    /**
     * Keep original format but still compress.
     */
    public function keepOriginalFormat(): static
    {
        $this->format = 'original';

        return $this;
    }

    /**
     * Convenience method to disable original upload (enable resizing).
     */
    public function resize(): static
    {
        return $this->uploadOriginal(false);
    }

    /**
     * Set whether to track media metadata
     */
    public function trackMetadata(bool $trackMetadata = true): static
    {
        $this->trackMetadata = $trackMetadata;

        return $this;
    }

    /**
     * Set whether to remove background from images
     */
    public function removeBg(bool|Closure $removeBackground = true): static
    {
        $this->removeBackground = $removeBackground;

        return $this;
    }

    /**
     * Convenience method to enable background removal
     */
    public function withoutBackground(): static
    {
        return $this->removeBg(true);
    }

    /**
     * Set the compression driver (gd or api)
     */
    public function driver(string $driver): static
    {
        if (! in_array($driver, ['gd', 'api'])) {
            throw new \InvalidArgumentException("Invalid compression driver: {$driver}. Must be 'gd' or 'api'.");
        }

        $this->compressionDriver = $driver;

        return $this;
    }

    /**
     * Set the SEO title for the uploaded media
     * Can be a string, null, or a closure that receives Get $get
     */
    public function seoTitle(string|Closure|null $seoTitle): static
    {
        $this->seoTitle = $seoTitle;
        $this->seoTitleField = null; // Clear field-based title if using direct title

        return $this;
    }

    /**
     * Set the SEO title from a field on the model
     * The SEO title will be taken from the specified field of the record
     */
    public function seoTitleFromField(string $fieldName): static
    {
        $this->seoTitleField = $fieldName;
        $this->seoTitle = null; // Clear direct title if using field-based title

        return $this;
    }

    /**
     * Enable video compression
     */
    public function compressVideo(bool $compress = true): static
    {
        $this->compressVideo = $compress;

        return $this;
    }

    /**
     * Set video output format
     */
    public function videoFormat(string $format): static
    {
        if (! in_array($format, ['webm', 'mp4'])) {
            throw new \InvalidArgumentException("Invalid video format: {$format}. Must be 'webm' or 'mp4'.");
        }
        $this->videoFormat = $format;

        return $this;
    }

    /**
     * Set video bitrate in kbps
     */
    public function videoBitrate(int $bitrate): static
    {
        $this->videoBitrate = $bitrate;

        return $this;
    }

    /**
     * Set video max dimensions
     */
    public function videoMaxDimensions(?int $width = null, ?int $height = null): static
    {
        $this->videoMaxWidth = $width;
        $this->videoMaxHeight = $height;

        return $this;
    }

    /**
     * Set video encoding preset
     */
    public function videoPreset(string $preset): static
    {
        if (! in_array($preset, ['ultrafast', 'fast', 'medium', 'slow', 'veryslow'])) {
            throw new \InvalidArgumentException("Invalid video preset: {$preset}.");
        }
        $this->videoPreset = $preset;

        return $this;
    }

    /**
     * Set video CRF (Constant Rate Factor)
     */
    public function videoCrf(int $crf): static
    {
        $this->videoCrf = $crf;

        return $this;
    }

    /**
     * Set whether to compress video asynchronously
     */
    public function videoAsync(bool $async = true): static
    {
        $this->videoAsync = $async;

        return $this;
    }

    /**
     * Convert videos to WebM format
     */
    public function toWebm(): static
    {
        return $this->compressVideo()->videoFormat('webm');
    }

    /**
     * Convert videos to MP4 format
     */
    public function toMp4(): static
    {
        return $this->compressVideo()->videoFormat('mp4');
    }

    /**
     * This is called automatically by Filament when the component is constructed.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Mark this as an image component
        $this->image();

        // Allowed file types (images + videos + PDFs).
        $this->acceptedFileTypes([
            'image/webp',
            'image/avif',
            'image/jpg',
            'image/jpeg',
            'image/png',
            'image/svg+xml',
            'image/x-icon',
            'image/vnd.microsoft.icon',
            'video/mp4',
            'video/webm',
            'video/mpeg',
            'video/quicktime',
            'video/x-m4v',
            'video/3gpp',
            'video/3gpp2',
            'application/pdf',
        ]);

        // Preview height
        $this->imagePreviewHeight('200');

        // If NOT uploading original, resize it (max size from config).
        if (! $this->uploadOriginal) {
            $this->imageResizeTargetHeight(strval(config('file-manager.max-upload-height')))
                ->imageResizeTargetWidth(strval(config('file-manager.max-upload-width')))
                ->imageResizeMode('contain')
                ->imageResizeUpscale(false);
        }

        // Make the file openable and set max size based on field type
        $this->openable()
            ->maxSize(function () {
                return $this->getMaxSizeForFieldType();
            });

        // Handle the saving logic
        $this->saveUploadedFileUsing(function (TemporaryUploadedFile $file, $get, $model) {
            if (config('filesystems.default') === 'local') {
                throw new Exception('Please set the default disk to s3 to use this package.');
            }

            // Check if a custom directory was set via directory() method
            $directory = $this->getDirectory();

            if ($directory === null) {
                // Handle both model instance and string (class name)
                $modelName = is_string($model) ? $model : class_basename($model);
                $directory = FileManagerService::getUploadDirectory($modelName);

                // If directory is still null, use a default
                if ($directory === null) {
                    $directory = 'uploads';
                }
            }

            $isVideo = Str::lower(Arr::first(explode('/', $file->getMimeType()))) === 'video';
            $isPdf = $file->getMimeType() === 'application/pdf' || $file->extension() === 'pdf';

            // Handle video compression if enabled
            if ($isVideo && $this->shouldCompressVideo()) {
                return $this->handleVideoCompression($file, $get, $model, $directory);
            }

            // Videos (without compression) and PDFs are handled normally
            if ($isVideo || $isPdf) {
                $filename = (string) FileManagerService::filename($file, static::tag($get), $file->extension());
                $fullPath = "{$directory}/{$filename}";

                // Build storage options
                $storageOptions = [
                    'disk' => 's3',
                    'visibility' => 'public',
                    'ContentType' => $file->getMimeType(),
                ];

                // Add cache headers if enabled (for PDFs too)
                $cacheControl = FileManagerService::buildCacheControlHeader();
                if ($cacheControl) {
                    $storageOptions['CacheControl'] = $cacheControl;
                }

                $file->storeAs($directory, $filename, $storageOptions);

                $this->createMetadata($model, $this->getName(), $fullPath, $file);

                return $fullPath;
            }

            // Check if we should use compression service
            $shouldUseCompression = $this->shouldUseCompression($file);

            if ($shouldUseCompression) {
                return $this->handleCompressedUpload($file, $get, $model, $directory);
            }

            // Regular image upload without compression
            $filename = (string) FileManagerService::filename($file, static::tag($get), $file->extension());
            $fullPath = "{$directory}/{$filename}";

            // Build storage options
            $storageOptions = [
                'disk' => 's3',
                'visibility' => 'public',
                'ContentType' => $file->getMimeType(),
            ];

            // Add cache headers if enabled
            $cacheControl = FileManagerService::buildCacheControlHeader();
            if ($cacheControl) {
                $storageOptions['CacheControl'] = $cacheControl;
            }

            // Store with cache headers for images
            $file->storeAs($directory, $filename, $storageOptions);

            $this->createMetadata($model, $this->getName(), $fullPath, $file);

            return $fullPath;
        });

        // Determine the stored name for the file
        $this->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file, $get) {
            return (string) FileManagerService::filename($file, static::tag($get), $file->extension());
        });
    }

    /**
     * Format bytes to human readable format
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Example of how you pick a 'tag' for the file name.
     */
    private static function tag(callable $get)
    {
        if ($get('slug') && is_string($get('slug'))) {
            return $get('slug');
        }

        return $get('name');
    }

    /**
     * Check if we should compress video
     */
    protected function shouldCompressVideo(): bool
    {
        // If video compression is explicitly enabled on this component
        if ($this->compressVideo) {
            return true;
        }

        // If video compression is disabled globally
        if (! config('file-manager.video_compression.enabled', true)) {
            return false;
        }

        // Check if the current field is defined as a video field in the model
        $model = $this->getRecord();
        if ($model && is_object($model) && method_exists($model, 'isVideoField')) {
            $fieldName = $this->getName();
            if ($model->isVideoField($fieldName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get max file size based on field type
     */
    protected function getMaxSizeForFieldType(): int
    {
        // Check if the field is a video field
        $model = $this->getRecord();
        if ($model && is_object($model) && method_exists($model, 'isVideoField')) {
            $fieldName = $this->getName();
            if ($model->isVideoField($fieldName)) {
                return intval(config('file-manager.max-upload-size-video', 102400));
            }
        }

        // Check if the field is a document field
        if ($model && is_object($model) && method_exists($model, 'isDocumentField')) {
            $fieldName = $this->getName();
            if ($model->isDocumentField($fieldName)) {
                return intval(config('file-manager.max-upload-size-document', 20480));
            }
        }

        // Default to image size limit
        return intval(config('file-manager.max-upload-size-image', config('file-manager.max-upload-size', 8192)));
    }

    /**
     * Handle video compression
     */
    protected function handleVideoCompression(
        TemporaryUploadedFile $file,
        $get,
        $model,
        string $directory
    ): string {
        try {
            // Determine output format
            $outputFormat = $this->videoFormat ?? config('file-manager.video_compression.format', 'webm');
            $extension = $outputFormat;

            $filename = (string) FileManagerService::filename($file, static::tag($get), $extension);
            $fullPath = "{$directory}/{$filename}";

            // Check if we should compress asynchronously
            if ($this->videoAsync) {
                // For async compression, upload the original and dispatch a job
                // We'll return the original path and let the job handle the compression

                // Upload the original video
                $originalFilename = (string) FileManagerService::filename($file, static::tag($get), $file->extension());
                $originalPath = "{$directory}/{$originalFilename}";

                // Build storage options
                $storageOptions = [
                    'disk' => 's3',
                    'visibility' => 'public',
                    'ContentType' => $file->getMimeType(),
                ];

                $file->storeAs($directory, $originalFilename, $storageOptions);

                // Create metadata for the original file
                $this->createMetadata($model, $this->getName(), $originalPath, $file);

                // Prepare model information for the job
                $modelClass = null;
                $modelId = null;
                $modelField = $this->getName();

                if ($model) {
                    if (is_object($model)) {
                        $modelClass = get_class($model);
                        $modelId = $model->id ?? null;
                    } elseif (is_string($model)) {
                        $modelClass = $model;
                    }
                }

                // Determine if we should replace the original
                // Only replace if we have a model ID (existing entity)
                $shouldReplace = (bool) $modelId;

                // Always dispatch the job, but with a delay for new models
                // This gives time for the model to be saved
                $job = new CompressVideoJob(
                    $originalPath,
                    null, // Let the job determine output path
                    $outputFormat,
                    $this->videoBitrate,
                    $this->videoMaxWidth,
                    $this->videoMaxHeight,
                    $this->videoPreset,
                    $this->videoCrf,
                    's3',
                    $modelClass,
                    $modelId,
                    $modelField,
                    $shouldReplace, // Only replace for existing models
                    true // Delete original after successful compression
                );

                if (! $modelId) {
                    // For new models, add a delay to ensure the model is saved first
                    // and store the field name for later retrieval
                    $job->delay(now()->addSeconds(15)); // Increased delay to 15 seconds

                    // Store a reference to the video field and path for post-save processing
                    if ($model && is_object($model)) {
                        // We'll store this in a static property that can be accessed after save
                        if (! isset($model->pendingVideoFields)) {
                            $model->pendingVideoFields = [];
                        }
                        $model->pendingVideoFields[$modelField] = $originalPath;
                    }

                    Log::info('Video compression job dispatched with delay for new model', [
                        'model_class' => $modelClass,
                        'field' => $modelField,
                        'path' => $originalPath,
                        'delay' => '10 seconds',
                    ]);
                } else {
                    Log::info('Video compression job dispatched', [
                        'model_class' => $modelClass,
                        'model_id' => $modelId,
                        'field' => $modelField,
                        'path' => $originalPath,
                    ]);
                }

                dispatch($job);

                Notification::make()
                    ->title('Video Upload Complete')
                    ->body('Your video has been uploaded. Compression will begin shortly.')
                    ->info()
                    ->send();

                // Return the original path (will be replaced after compression)
                return $originalPath;
            } else {
                // Synchronous compression
                $videoService = new VideoCompressionService;

                // Check if FFmpeg is available first
                if (! $videoService->isFFmpegAvailable()) {
                    Notification::make()
                        ->title('FFmpeg Not Installed')
                        ->body('Video compression requires FFmpeg. Please install it using: brew install ffmpeg (macOS) or apt-get install ffmpeg (Linux)')
                        ->warning()
                        ->persistent()
                        ->send();

                    // Fall back to regular upload without compression
                    $filename = (string) FileManagerService::filename($file, static::tag($get), $file->extension());
                    $fullPath = "{$directory}/{$filename}";

                    $storageOptions = [
                        'disk' => 's3',
                        'visibility' => 'public',
                        'ContentType' => $file->getMimeType(),
                    ];

                    $file->storeAs($directory, $filename, $storageOptions);
                    $this->createMetadata($model, $this->getName(), $fullPath, $file);

                    return $fullPath;
                }

                // Get file content
                $tempPath = $file->getRealPath();
                if (! file_exists($tempPath)) {
                    $tempPath = $file->path();
                }

                // Compress and save
                $result = $videoService->compressAndSave(
                    $tempPath,
                    $fullPath,
                    $outputFormat,
                    $this->videoBitrate,
                    $this->videoMaxWidth,
                    $this->videoMaxHeight,
                    $this->videoPreset,
                    $this->videoCrf,
                    's3'
                );

                if (! $result['success']) {
                    throw new Exception('Video compression failed: ' . $result['message']);
                }

                // Create metadata with compression info
                if ($this->trackMetadata && config('file-manager.media_metadata.enabled', true) && $model && is_object($model)) {
                    MediaMetadata::create([
                        'mediable_type' => get_class($model),
                        'mediable_id' => $model->id,
                        'mediable_field' => $this->getName(),
                        'file_name' => $fullPath,
                        'mime_type' => 'video/' . $outputFormat,
                        'file_size' => $result['data']['compressed_size'],
                        'width' => $result['data']['width'] ?? null,
                        'height' => $result['data']['height'] ?? null,
                        'metadata' => [
                            'compression_ratio' => $result['data']['compression_ratio'],
                            'video_bitrate' => $result['data']['video_bitrate'],
                            'duration' => $result['data']['duration'] ?? null,
                            'compression_method' => 'ffmpeg',
                            'created_via' => 'MediaUpload',
                            'created_at' => now()->toIso8601String(),
                        ],
                    ]);
                }

                Notification::make()
                    ->title('Video Compressed Successfully')
                    ->body('Compression ratio: ' . $result['data']['compression_ratio'])
                    ->success()
                    ->send();

                return $fullPath;
            }
        } catch (Exception $e) {
            // If compression fails, fall back to original upload
            Notification::make()
                ->title('Video Compression Failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            // Upload original file
            $filename = (string) FileManagerService::filename($file, static::tag($get), $file->extension());
            $fullPath = "{$directory}/{$filename}";

            $storageOptions = [
                'disk' => 's3',
                'visibility' => 'public',
                'ContentType' => $file->getMimeType(),
            ];

            $file->storeAs($directory, $filename, $storageOptions);
            $this->createMetadata($model, $this->getName(), $fullPath, $file);

            return $fullPath;
        }
    }

    /**
     * Check if we should use compression service
     */
    protected function shouldUseCompression(TemporaryUploadedFile $file): bool
    {
        // If uploadOriginal is true, skip all processing
        if ($this->uploadOriginal) {
            return false;
        }

        // Skip compression for videos
        $isVideo = Str::lower(Arr::first(explode('/', $file->getMimeType()))) === 'video';
        if ($isVideo) {
            return false;
        }

        // Skip compression for PDFs
        if ($file->getMimeType() === 'application/pdf' || $file->extension() === 'pdf') {
            return false;
        }

        // Skip compression for certain formats that shouldn't be compressed
        if (in_array($file->extension(), ['ico', 'svg', 'gif'])) {
            return false;
        }

        // If compression is disabled globally
        if (! config('file-manager.compression.enabled')) {
            return false;
        }

        // If auto_compress is disabled, don't compress on upload
        // (but manual compress action in MediaMetadataResource will still work)
        if (! config('file-manager.compression.auto_compress')) {
            return false;
        }

        // Always compress when enabled (ignore threshold)
        return true;
    }

    /**
     * Handle compressed upload using ImageCompressionService
     */
    protected function handleCompressedUpload(
        TemporaryUploadedFile $file,
        $get,
        $model,
        string $directory
    ): string {
        try {
            // First, ensure we can access the file
            $tempPath = null;

            // Try different methods to get the file path
            if (method_exists($file, 'getRealPath') && $file->getRealPath() && file_exists($file->getRealPath())) {
                $tempPath = $file->getRealPath();
            } elseif (method_exists($file, 'path') && $file->path() && file_exists($file->path())) {
                $tempPath = $file->path();
            } else {
                // Create a temporary copy if we can't access the original
                $fileContent = null;

                // Try to get content from the file object
                if (method_exists($file, 'get')) {
                    $fileContent = $file->get();
                } elseif (method_exists($file, 'getContent')) {
                    $fileContent = $file->getContent();
                }

                if ($fileContent) {
                    $tempPath = sys_get_temp_dir() . '/' . uniqid('media_') . '_' . $file->getClientOriginalName();
                    file_put_contents($tempPath, $fileContent);
                } else {
                    throw new Exception('Cannot access temporary file content');
                }
            }

            // Override compression method if driver is specified
            $originalMethod = null;
            if ($this->compressionDriver !== null) {
                $originalMethod = config('file-manager.compression.method');
                config(['file-manager.compression.method' => $this->compressionDriver]);
            }

            // Create compression service AFTER config override
            $compressionService = new ImageCompressionService;

            // Use specified format or fall back to config
            $outputFormat = $this->format ?? config('file-manager.compression.format', 'webp');

            // If keeping original format, use the file's extension
            if ($outputFormat === 'original') {
                $extension = $file->extension();
                // Map common extensions to format names for compression
                $formatMap = [
                    'jpg' => 'jpeg',
                    'jpeg' => 'jpeg',
                    'png' => 'png',
                    'webp' => 'webp',
                    'avif' => 'avif',
                ];
                $outputFormat = $formatMap[strtolower($extension)] ?? 'jpeg'; // Default to jpeg if unknown
            } else {
                $extension = $outputFormat === 'jpg' ? 'jpeg' : $outputFormat;
            }

            $filename = (string) FileManagerService::filename($file, static::tag($get), $extension);
            $fullPath = "{$directory}/{$filename}";

            // Evaluate the removeBackground value if it's a closure
            $shouldRemoveBackground = $this->evaluate($this->removeBackground);

            // Compress the image (with optional background removal)
            // Pass the file path instead of the TemporaryUploadedFile object
            $result = $compressionService->compressAndSave(
                $tempPath,
                $fullPath,
                min($this->quality ?? (int) config('file-manager.compression.quality', 85), 95),
                config('file-manager.compression.height') ? (int) config('file-manager.compression.height') : null,
                config('file-manager.compression.width') ? (int) config('file-manager.compression.width') : null,
                $outputFormat,
                config('file-manager.compression.mode'),
                's3',
                $shouldRemoveBackground  // Pass the evaluated removeBg flag
            );

            // Restore original compression method if we changed it
            if ($originalMethod !== null) {
                config(['file-manager.compression.method' => $originalMethod]);
            }

            // Clean up temporary file if we created one
            if ($tempPath && strpos($tempPath, sys_get_temp_dir()) === 0 && file_exists($tempPath)) {
                @unlink($tempPath);
            }

        } catch (Exception $e) {
            // If compression fails, try with the original file object

            // Apply driver override in fallback too
            $originalMethod = null;
            if ($this->compressionDriver !== null) {
                $originalMethod = config('file-manager.compression.method');
                config(['file-manager.compression.method' => $this->compressionDriver]);
            }

            // Create compression service AFTER config override
            $compressionService = new ImageCompressionService;

            $outputFormat = $this->format ?? config('file-manager.compression.format', 'webp');

            // If keeping original format, use the file's extension
            if ($outputFormat === 'original') {
                $extension = $file->extension();
                // Map common extensions to format names for compression
                $formatMap = [
                    'jpg' => 'jpeg',
                    'jpeg' => 'jpeg',
                    'png' => 'png',
                    'webp' => 'webp',
                    'avif' => 'avif',
                ];
                $outputFormat = $formatMap[strtolower($extension)] ?? 'jpeg'; // Default to jpeg if unknown
            } else {
                $extension = $outputFormat === 'jpg' ? 'jpeg' : $outputFormat;
            }

            $filename = (string) FileManagerService::filename($file, static::tag($get), $extension);
            $fullPath = "{$directory}/{$filename}";
            $shouldRemoveBackground = $this->evaluate($this->removeBackground);

            $result = $compressionService->compressAndSave(
                $file,
                $fullPath,
                min($this->quality ?? (int) config('file-manager.compression.quality', 85), 95),
                config('file-manager.compression.height') ? (int) config('file-manager.compression.height') : null,
                config('file-manager.compression.width') ? (int) config('file-manager.compression.width') : null,
                $outputFormat,
                config('file-manager.compression.mode'),
                's3',
                $shouldRemoveBackground
            );

            // Restore original method after fallback
            if ($originalMethod !== null) {
                config(['file-manager.compression.method' => $originalMethod]);
            }
        }

        if ($result['success']) {
            // Mark that compression was used
            $this->compressionUsed = true;

            // Format file sizes for display
            $originalSizeFormatted = $this->formatBytes($result['data']['original_size'] ?? 0);
            $compressedSizeFormatted = $this->formatBytes($result['data']['compressed_size'] ?? 0);
            $compressionRatio = $result['data']['compression_ratio'] ?? '0%';

            // Check compression method and send appropriate notification
            if (isset($result['data']['compression_method'])) {
                if ($result['data']['compression_method'] === 'gd_fallback') {
                    // API failed, used GD as fallback
                    $reason = $result['data']['api_fallback_reason'] ?? 'Unknown reason';
                    Notification::make()
                        ->warning()
                        ->title('API Compression Failed - Used GD Fallback')
                        ->body("API Error: {$reason}<br>
                               Compressed with GD: {$originalSizeFormatted} → {$compressedSizeFormatted}<br>
                               Saved: {$compressionRatio}")
                        ->duration(8000)
                        ->send();
                } elseif ($result['data']['compression_method'] === 'gd') {
                    // Direct GD compression
                    Notification::make()
                        ->success()
                        ->title('Image Compressed with GD')
                        ->body("Size: {$originalSizeFormatted} → {$compressedSizeFormatted}<br>
                               Saved: {$compressionRatio}")
                        ->duration(5000)
                        ->send();
                } elseif ($result['data']['compression_method'] === 'api') {
                    // Successful Lambda API compression
                    Notification::make()
                        ->success()
                        ->title('Image Compressed via Lambda API')
                        ->body("Size: {$originalSizeFormatted} → {$compressedSizeFormatted}<br>
                               Saved: {$compressionRatio}<br>
                               <small>Fast compression via AWS Lambda</small>")
                        ->duration(5000)
                        ->send();
                } elseif ($result['data']['compression_method'] === 'api_bg_removal') {
                    // Successful Cloud Run API compression with bg removal
                    Notification::make()
                        ->success()
                        ->title('Image Processed with Background Removal')
                        ->body("Size: {$originalSizeFormatted} → {$compressedSizeFormatted}<br>
                               Saved: {$compressionRatio}<br>
                               <small>Background removed via Cloud Run</small>")
                        ->duration(5000)
                        ->send();
                }
            }

            // Create metadata with compression info
            if ($this->trackMetadata && config('file-manager.media_metadata.enabled') && $model) {
                $metadata = $this->createMetadata($model, $this->getName(), $fullPath, $file);

                if ($metadata) {
                    $metadata->update([
                        'file_size' => $result['data']['compressed_size'] ?? $file->getSize(),
                        'metadata' => array_merge($metadata->metadata ?? [], [
                            'compression' => [
                                'original_size' => $result['data']['original_size'] ?? null,
                                'compressed_size' => $result['data']['compressed_size'] ?? null,
                                'compression_ratio' => $result['data']['compression_ratio'] ?? null,
                                'method' => $result['data']['compression_method'] ?? config('file-manager.compression.method'),
                                'compressed_at' => now()->toIso8601String(),
                                'api_fallback_reason' => $result['data']['api_fallback_reason'] ?? null,
                            ],
                        ]),
                    ]);
                }
            }

            return $fullPath;
        } else {
            // Compression completely failed, show error notification
            Notification::make()
                ->danger()
                ->title('Compression Failed')
                ->body('Error: ' . ($result['message'] ?? 'Unknown error') . '<br>
                       Uploading original file without compression.')
                ->duration(8000)
                ->send();
        }

        // Build storage options for fallback
        $storageOptions = [
            'disk' => 's3',
            'visibility' => 'public',
            'ContentType' => $file->getMimeType(),
        ];

        // Add cache headers if enabled
        $cacheControl = FileManagerService::buildCacheControlHeader();
        if ($cacheControl) {
            $storageOptions['CacheControl'] = $cacheControl;
        }

        // Fallback to regular upload if compression fails
        $file->storeAs($directory, $filename, $storageOptions);
        $this->createMetadata($model, $this->getName(), $fullPath, $file);

        return $fullPath;
    }

    /**
     * Create media metadata
     */
    protected function createMetadata($model, string $field, string $fullPath, TemporaryUploadedFile $file): ?MediaMetadata
    {
        if (! $this->trackMetadata || ! config('file-manager.media_metadata.enabled')) {
            return null;
        }

        // Skip metadata creation if model is not a valid instance with an ID
        // This happens during creation of new records
        if (! $model || ! is_object($model) || ! isset($model->id)) {
            return null;
        }

        $fileSize = $file->getSize();
        $mimeType = $file->getMimeType();
        $metadata = [
            'uploaded_at' => now()->toIso8601String(),
            'uploaded_via' => 'file-manager',
        ];

        // Add compression info if available
        if ($this->compressionUsed) {
            $metadata['compression'] = [
                'method' => config('file-manager.compression.method', 'gd'),
                'quality' => min($this->quality ?? (int) config('file-manager.compression.quality', 85), 95),
            ];
        }

        // Determine SEO title
        $seoTitleValue = null;

        // Check if this model type should have SEO titles
        $modelClass = get_class($model);
        $enabledModels = config('file-manager.seo.enabled_models', []);
        $excludedModels = config('file-manager.seo.excluded_models', []);

        // Skip SEO title for excluded models
        if (in_array($modelClass, $excludedModels)) {
            $seoTitleValue = null;
        }
        // Only set SEO title if model is enabled (or if no restrictions are set)
        elseif (empty($enabledModels) || in_array($modelClass, $enabledModels)) {
            // First check if SEO title from field is set
            if ($this->seoTitleField !== null && $model) {
                $seoTitleValue = $model->{$this->seoTitleField} ?? null;
            }
            // Then check if direct SEO title is set (can be closure)
            elseif ($this->seoTitle !== null) {
                $seoTitleValue = $this->evaluate($this->seoTitle);
            }

            // Limit SEO title to 160 characters (reasonable for meta titles)
            if ($seoTitleValue !== null) {
                $seoTitleValue = substr($seoTitleValue, 0, 160);
            }
        }

        $data = [
            'file_name' => $fullPath,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'metadata' => $metadata,
        ];

        // Only add SEO title if it has a value
        if ($seoTitleValue !== null && $seoTitleValue !== '') {
            $data['seo_title'] = $seoTitleValue;
        }

        return MediaMetadata::updateOrCreateFor($model, $field, $data);
    }
}
