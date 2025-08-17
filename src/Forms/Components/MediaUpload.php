<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Forms\Components;

use Exception;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Kirantimsina\FileManager\Facades\FileManager;
use Kirantimsina\FileManager\FileManagerService;
use Kirantimsina\FileManager\Models\MediaMetadata;
use Kirantimsina\FileManager\Services\ImageCompressionService;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class MediaUpload extends FileUpload
{
    /**
     * Whether to upload the original version or resize.
     */
    protected bool $uploadOriginal = true;

    /**
     * Whether or not to convert (non-exempt images) to WebP.
     */
    protected bool $convertToWebp = true;

    /**
     * Quality to use if converting to WebP.
     */
    protected int $quality = 100;

    /**
     * Whether to use compression service
     */
    protected bool $useCompression = true;

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
    protected bool|\Closure $removeBackground = false;
    
    /**
     * Compression driver to use (overrides config)
     */
    protected ?string $compressionDriver = null;

    /**
     * Set whether to upload original or resize.
     */
    public function uploadOriginal(bool $uploadOriginal = true): static
    {
        $this->uploadOriginal = $uploadOriginal;

        return $this;
    }

    /**
     * Set whether to convert images to WebP.
     */
    public function convertToWebp(bool $convertToWebp = true): static
    {
        $this->convertToWebp = $convertToWebp;

        return $this;
    }

    /**
     * Set the WebP quality.
     */
    public function quality(int $quality): static
    {
        $this->quality = $quality;

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
     * Convenience method to disable WebP conversion.
     */
    public function keepOriginalFormat(): static
    {
        return $this->convertToWebp(false);
    }

    /**
     * Set whether to use compression service
     */
    public function useCompression(bool $useCompression = true): static
    {
        $this->useCompression = $useCompression;

        return $this;
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
    public function removeBg(bool|\Closure $removeBackground = true): static
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
        if (!in_array($driver, ['gd', 'api'])) {
            throw new \InvalidArgumentException("Invalid compression driver: {$driver}. Must be 'gd' or 'api'.");
        }
        
        $this->compressionDriver = $driver;
        
        return $this;
    }

    /**
     * This is called automatically by Filament when the component is constructed.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Mark this as an image component
        $this->image();

        // Allowed file types (images + videos).
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

        // Make the file openable and set max size
        $this->openable()
            ->maxSize(intval(config('file-manager.max-upload-size')));

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

            // Videos are handled normally
            if ($isVideo) {
                $filename = (string) FileManagerService::filename($file, static::tag($get), $file->extension());
                $fullPath = "{$directory}/{$filename}";
                $file->storeAs($directory, $filename, 's3');

                $this->createMetadata($model, $this->getName(), $fullPath, $file);

                return $fullPath;
            }

            // Check if we should use compression service
            $shouldUseCompression = $this->shouldUseCompression($file);

            if ($shouldUseCompression) {
                return $this->handleCompressedUpload($file, $get, $model, $directory);
            }

            // Regular image upload with potential WebP conversion
            $extension = ($this->convertToWebp && ! in_array($file->extension(), ['ico', 'svg', 'avif', 'webp']))
                ? 'webp'
                : $file->extension();

            $filename = (string) FileManagerService::filename($file, static::tag($get), $extension);
            $fullPath = "{$directory}/{$filename}";

            // If converting to webp, use Intervention
            if ($this->convertToWebp && ! in_array($file->extension(), ['ico', 'svg', 'avif', 'webp'])) {
                $img = ImageManager::gd()->read(\file_get_contents(FileManager::getMediaPath($file->path())));
                $media = $img->toWebp($this->quality)->toFilePointer();
                Storage::disk('s3')->put($fullPath, $media);
            } else {
                $file->storeAs($directory, $filename, 's3');
            }

            $this->createMetadata($model, $this->getName(), $fullPath, $file);

            return $fullPath;
        });

        // Determine the stored name for the file
        $this->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file, $get) {
            $isVideo = Str::lower(Arr::first(explode('/', $file->getMimeType()))) === 'video';

            $extension = ($this->convertToWebp && ! $isVideo)
                ? 'webp'
                : $file->extension();

            return (string) FileManagerService::filename($file, static::tag($get), $extension);
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
     * Check if we should use compression service
     */
    protected function shouldUseCompression(TemporaryUploadedFile $file): bool
    {
        // Skip compression for videos
        $isVideo = Str::lower(Arr::first(explode('/', $file->getMimeType()))) === 'video';
        if ($isVideo) {
            return false;
        }

        // Skip compression for certain formats that shouldn't be compressed
        if (in_array($file->extension(), ['ico', 'svg', 'gif'])) {
            return false;
        }

        // If compression is disabled at component level
        if (! $this->useCompression) {
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
                    throw new \Exception('Cannot access temporary file content');
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

            // Always use webp for compressed images
            $filename = (string) FileManagerService::filename($file, static::tag($get), 'webp');
            $fullPath = "{$directory}/{$filename}";

            // Evaluate the removeBackground value if it's a closure
            $shouldRemoveBackground = $this->evaluate($this->removeBackground);

            // Compress the image (with optional background removal)
            // Pass the file path instead of the TemporaryUploadedFile object
            $result = $compressionService->compressAndSave(
                $tempPath,
                $fullPath,
                (int) config('file-manager.compression.quality'),
                config('file-manager.compression.height') ? (int) config('file-manager.compression.height') : null,
                config('file-manager.compression.width') ? (int) config('file-manager.compression.width') : null,
                config('file-manager.compression.format'),
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
            
        } catch (\Exception $e) {
            // If compression fails, try with the original file object
            
            // Apply driver override in fallback too
            $originalMethod = null;
            if ($this->compressionDriver !== null) {
                $originalMethod = config('file-manager.compression.method');
                config(['file-manager.compression.method' => $this->compressionDriver]);
            }
            
            // Create compression service AFTER config override
            $compressionService = new ImageCompressionService;
            
            $filename = (string) FileManagerService::filename($file, static::tag($get), 'webp');
            $fullPath = "{$directory}/{$filename}";
            $shouldRemoveBackground = $this->evaluate($this->removeBackground);
            
            $result = $compressionService->compressAndSave(
                $file,
                $fullPath,
                (int) config('file-manager.compression.quality'),
                config('file-manager.compression.height') ? (int) config('file-manager.compression.height') : null,
                config('file-manager.compression.width') ? (int) config('file-manager.compression.width') : null,
                config('file-manager.compression.format'),
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
                    // Successful API compression
                    Notification::make()
                        ->success()
                        ->title('Image Compressed via API')
                        ->body("Size: {$originalSizeFormatted} → {$compressedSizeFormatted}<br>
                               Saved: {$compressionRatio}")
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

        // Fallback to regular upload if compression fails
        $file->storeAs($directory, $filename, 's3');
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
        if (! $model || is_string($model) || ! isset($model->id)) {
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
                'quality' => $this->quality ?? config('file-manager.compression.quality', 85),
            ];
        }

        return MediaMetadata::updateOrCreateFor($model, $field, [
            'file_name' => $fullPath,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'metadata' => $metadata,
        ]);
    }
}
