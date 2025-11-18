<?php

namespace Kirantimsina\FileManager\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class ImageCompressionService
{
    private string $driver;

    private int $defaultQuality;

    private string $defaultFormat;

    private string $defaultMode;

    private int $maxHeight;

    private int $maxWidth;

    public function __construct()
    {
        $this->driver = config('file-manager.compression.driver', 'gd');
        $this->defaultQuality = config('file-manager.compression.quality', 85);
        $this->defaultFormat = config('file-manager.compression.format', 'webp');
        $this->defaultMode = config('file-manager.compression.mode', 'contain');
        $this->maxHeight = config('file-manager.compression.max_height', 2160);
        $this->maxWidth = config('file-manager.compression.max_width', 3840);
    }

    /**
     * Compress an image using the configured driver (GD or Imagick)
     */
    public function compress(
        $image,
        ?int $quality = null,
        ?int $height = null,
        ?int $width = null,
        ?string $format = null,
        ?string $mode = null
    ): array {
        $quality = $quality ?? $this->defaultQuality;
        $format = $format ?? $this->defaultFormat;
        $mode = $mode ?? $this->defaultMode;

        return $this->compressViaDriver($image, $quality, $height, $width, $format, $mode);
    }

    /**
     * Compress using configured driver (GD or Imagick)
     */
    protected function compressViaDriver(
        $image,
        int $quality,
        ?int $height,
        ?int $width,
        string $format,
        string $mode
    ): array {
        try {
            $fileContent = $this->getFileContent($image);
            if (! $fileContent['success']) {
                return $fileContent;
            }

            $originalSize = strlen($fileContent['data']['content']);

            // Use Intervention Image with configured driver
            $manager = $this->driver === 'imagick'
                ? ImageManager::imagick()
                : ImageManager::gd();

            $img = $manager->read($fileContent['data']['content']);

            // Get original dimensions
            $originalWidth = $img->width();
            $originalHeight = $img->height();

            // Calculate enforced dimensions based on max constraints
            $enforcedDimensions = $this->calculateEnforcedDimensions(
                $originalWidth,
                $originalHeight,
                $width,
                $height
            );

            $finalWidth = $enforcedDimensions['width'];
            $finalHeight = $enforcedDimensions['height'];

            // Resize if dimensions need to be enforced or were explicitly provided
            if ($finalWidth !== $originalWidth || $finalHeight !== $originalHeight) {
                if ($mode === 'contain') {
                    $img->scaleDown($finalWidth, $finalHeight);
                } elseif ($mode === 'cover') {
                    $img->cover($finalWidth, $finalHeight);
                } elseif ($mode === 'crop') {
                    $img->crop($finalWidth, $finalHeight);
                }
            }

            // Convert to desired format
            switch ($format) {
                case 'webp':
                    $compressedContent = $img->toWebp($quality)->toString();
                    break;
                case 'jpg':
                case 'jpeg':
                    $compressedContent = $img->toJpeg($quality)->toString();
                    break;
                case 'png':
                    $compressedContent = $img->toPng()->toString();
                    break;
                case 'avif':
                    $compressedContent = $img->toAvif($quality)->toString();
                    break;
                default:
                    $compressedContent = $img->toWebp($quality)->toString();
            }

            $compressedSize = strlen($compressedContent);
            $compressionRatio = round((1 - ($compressedSize / $originalSize)) * 100, 2);

            // Get final dimensions after processing
            try {
                $finalImg = $manager->read($compressedContent);
                $finalWidth = $finalImg->width();
                $finalHeight = $finalImg->height();
            } catch (\Exception $e) {
                // If we can't read the compressed image dimensions, use the enforced dimensions
                $finalWidth = $enforcedDimensions['width'];
                $finalHeight = $enforcedDimensions['height'];
            }

            return [
                'success' => true,
                'data' => [
                    'compressed_image' => $compressedContent,
                    'original_size' => $originalSize,
                    'compressed_size' => $compressedSize,
                    'compression_ratio' => $compressionRatio . '%',
                    'filename' => $fileContent['data']['filename'],
                    'format' => $format,
                    'width' => $finalWidth,
                    'height' => $finalHeight,
                    'compression_method' => $this->driver,
                ],
                'message' => 'Image compressed successfully using ' . strtoupper($this->driver),
            ];

        } catch (Throwable $t) {
            // Provide more context about the error
            $errorMessage = ucfirst($this->driver) . ' compression failed: ' . $t->getMessage();

            // Add helpful debugging info
            if (str_contains($t->getMessage(), 'Unable to decode')) {
                $errorMessage .= ' - The image file may be corrupted or in an unsupported format.';
            }

            return [
                'success' => false,
                'message' => $errorMessage,
                'debug' => [
                    'file_exists' => isset($fileContent['data']['content']),
                    'file_size' => isset($fileContent['data']['content']) ? strlen($fileContent['data']['content']) : 0,
                    'exception_class' => get_class($t),
                ],
            ];
        }
    }

    /**
     * Compress and save image to storage
     */
    public function compressAndSave(
        $image,
        string $outputPath,
        ?int $quality = null,
        ?int $height = null,
        ?int $width = null,
        ?string $format = null,
        ?string $mode = null,
        ?string $disk = null
    ): array {
        try {
            $result = $this->compress($image, $quality, $height, $width, $format, $mode);
            if (! $result['success']) {
                return $result;
            }

            // Use putFileAs for more reliable S3 uploads
            try {
                // Prepare storage options with cache headers for S3
                if (($disk ?: config('filesystems.default')) === 's3') {
                    // Determine content type based on format
                    $contentType = match ($format) {
                        'jpeg', 'jpg' => 'image/jpeg',
                        'png' => 'image/png',
                        'webp' => 'image/webp',
                        'avif' => 'image/avif',
                        default => 'image/webp',
                    };

                    $storageOptions = [
                        'visibility' => 'public',
                        'ContentType' => $contentType,
                    ];

                    // Add cache headers if enabled
                    if (config('file-manager.cache.enabled', true)) {
                        $cacheControl = $this->buildCacheControlHeader();
                        if ($cacheControl) {
                            $storageOptions['CacheControl'] = $cacheControl;
                        }
                    }

                    $saved = Storage::disk($disk ?: config('filesystems.default'))->put(
                        $outputPath,
                        $result['data']['compressed_image'],
                        $storageOptions
                    );
                } else {
                    $saved = Storage::disk($disk ?: config('filesystems.default'))->put(
                        $outputPath,
                        $result['data']['compressed_image']
                    );
                }
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'message' => 'Failed to save compressed image: ' . $e->getMessage(),
                ];
            }

            if (! $saved) {
                return [
                    'success' => false,
                    'message' => 'Failed to save compressed image to storage',
                ];
            }

            $result['data']['storage_path'] = $outputPath;
            $result['data']['storage_url'] = Storage::disk($disk)->url($outputPath);

            return $result;

        } catch (Throwable $t) {
            return [
                'success' => false,
                'message' => 'Exception: ' . $t->getMessage(),
            ];
        }
    }

    /**
     * Get file content from various input types
     */
    private function getFileContent($image): array
    {
        try {
            // Handle TemporaryUploadedFile (Livewire)
            if ($image instanceof TemporaryUploadedFile) {
                $realPath = $image->getRealPath();
                if (! file_exists($realPath)) {
                    // Try to get content from the temporary path
                    $tempPath = $image->path();
                    if (file_exists($tempPath)) {
                        $realPath = $tempPath;
                    } else {
                        return [
                            'success' => false,
                            'message' => 'Temporary file not found or already moved',
                        ];
                    }
                }

                return [
                    'success' => true,
                    'data' => [
                        'content' => file_get_contents($realPath),
                        'filename' => $image->getClientOriginalName(),
                    ],
                ];
            }

            // Handle UploadedFile
            if ($image instanceof UploadedFile) {
                return [
                    'success' => true,
                    'data' => [
                        'content' => file_get_contents($image->getRealPath()),
                        'filename' => $image->getClientOriginalName(),
                    ],
                ];
            }

            // Handle file path from storage
            if (is_string($image)) {
                // Check if it's a storage path
                if (Storage::disk(config('filesystems.default'))->exists($image)) {
                    return [
                        'success' => true,
                        'data' => [
                            'content' => Storage::disk(config('filesystems.default'))->get($image),
                            'filename' => basename($image),
                        ],
                    ];
                }

                // Check if it's a local file path
                if (file_exists($image)) {
                    return [
                        'success' => true,
                        'data' => [
                            'content' => file_get_contents($image),
                            'filename' => basename($image),
                        ],
                    ];
                }

                return [
                    'success' => false,
                    'message' => 'File not found: ' . $image,
                ];
            }

            return [
                'success' => false,
                'message' => 'Invalid image input type',
            ];

        } catch (Throwable $t) {
            return [
                'success' => false,
                'message' => 'Exception: ' . $t->getMessage(),
            ];
        }
    }

    /**
     * Build the Cache-Control header value from config
     */
    protected function buildCacheControlHeader(): ?string
    {
        if (! config('file-manager.cache.enabled', true)) {
            return null;
        }

        $visibility = config('file-manager.cache.visibility', 'public');
        $maxAge = config('file-manager.cache.max_age', 31536000);
        $immutable = config('file-manager.cache.immutable', true);

        $header = "{$visibility}, max-age={$maxAge}";

        if ($immutable) {
            $header .= ', immutable';
        }

        return $header;
    }

    /**
     * Calculate dimensions ensuring they don't exceed max constraints
     *
     * @param  int  $originalWidth  Original image width
     * @param  int  $originalHeight  Original image height
     * @param  int|null  $requestedWidth  Explicitly requested width (can be null)
     * @param  int|null  $requestedHeight  Explicitly requested height (can be null)
     * @return array ['width' => int, 'height' => int]
     */
    private function calculateEnforcedDimensions(
        int $originalWidth,
        int $originalHeight,
        ?int $requestedWidth = null,
        ?int $requestedHeight = null
    ): array {
        // Start with original dimensions
        $width = $originalWidth;
        $height = $originalHeight;

        // Apply requested dimensions if provided
        if ($requestedWidth !== null) {
            $width = $requestedWidth;
        }
        if ($requestedHeight !== null) {
            $height = $requestedHeight;
        }

        // Check if dimensions exceed maximum constraints
        $exceedsMaxWidth = $width > $this->maxWidth;
        $exceedsMaxHeight = $height > $this->maxHeight;

        if ($exceedsMaxWidth || $exceedsMaxHeight) {
            // Calculate scaling factors for both dimensions
            $widthScale = $this->maxWidth / $width;
            $heightScale = $this->maxHeight / $height;

            // Use the more restrictive scale factor to maintain aspect ratio
            $scale = min($widthScale, $heightScale);

            // Apply the scale factor
            $width = (int) round($width * $scale);
            $height = (int) round($height * $scale);
        }

        return [
            'width' => $width,
            'height' => $height,
        ];
    }
}
