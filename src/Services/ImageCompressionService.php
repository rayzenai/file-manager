<?php

namespace Kirantimsina\FileManager\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Throwable;

class ImageCompressionService
{
    private string $compressionMethod;

    private string $apiUrl;

    private string $apiToken;

    private int $defaultQuality;

    private string $defaultFormat;

    private string $defaultMode;

    private int $timeout;

    public function __construct()
    {
        $this->compressionMethod = config('file-manager.compression.method', 'gd');
        $this->apiUrl = config('file-manager.compression.api.url', '');
        $this->apiToken = config('file-manager.compression.api.token', '');
        $this->defaultQuality = config('file-manager.compression.quality', 85);
        $this->defaultFormat = config('file-manager.compression.format', 'webp');
        $this->defaultMode = config('file-manager.compression.mode', 'contain');
        $this->timeout = config('file-manager.compression.api.timeout', 30);
    }

    /**
     * Compress an image using the configured method
     */
    public function compress(
        $image,
        ?int $quality = null,
        ?int $height = null,
        ?int $width = null,
        ?string $format = null,
        ?string $mode = null,
        bool $removeBg = false
    ): array {
        $quality = $quality ?? $this->defaultQuality;
        $format = $format ?? $this->defaultFormat;
        $mode = $mode ?? $this->defaultMode;

        if ($this->compressionMethod === 'api' && ! empty($this->apiUrl)) {
            return $this->compressViaApi($image, $quality, $height, $width, $format, $mode, $removeBg);
        }

        // Note: GD library doesn't support background removal, only API does
        if ($removeBg && $this->compressionMethod !== 'api') {
            // Try to use API if background removal is requested but GD is configured
            if (! empty($this->apiUrl)) {
                return $this->compressViaApi($image, $quality, $height, $width, $format, $mode, $removeBg);
            }
        }

        return $this->compressViaGd($image, $quality, $height, $width, $format, $mode);
    }

    /**
     * Compress using built-in GD library
     */
    protected function compressViaGd(
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

            // Use Intervention Image with GD
            $img = ImageManager::gd()->read($fileContent['data']['content']);

            // Resize if dimensions provided
            if ($height || $width) {
                if ($mode === 'contain') {
                    $img->scaleDown($width, $height);
                } elseif ($mode === 'cover') {
                    $img->cover($width ?? $img->width(), $height ?? $img->height());
                } elseif ($mode === 'crop') {
                    $img->crop($width ?? $img->width(), $height ?? $img->height());
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

            return [
                'success' => true,
                'data' => [
                    'compressed_image' => $compressedContent,
                    'original_size' => $originalSize,
                    'compressed_size' => $compressedSize,
                    'compression_ratio' => $compressionRatio . '%',
                    'filename' => $fileContent['data']['filename'],
                    'format' => $format,
                    'width' => $img->width(),
                    'height' => $img->height(),
                ],
                'message' => 'Image compressed successfully using GD',
            ];

        } catch (Throwable $t) {
            return [
                'success' => false,
                'message' => 'GD compression failed: ' . $t->getMessage(),
            ];
        }
    }

    /**
     * Compress using external API
     */
    protected function compressViaApi(
        $image,
        int $quality,
        ?int $height,
        ?int $width,
        string $format,
        string $mode,
        bool $removeBg = false
    ): array {
        try {
            $fileContent = $this->getFileContent($image);
            if (! $fileContent['success']) {
                return $fileContent;
            }

            // Build API request parameters
            $params = [
                'format' => $format,
                'mode' => $mode,
                'quality' => $quality,
                'height' => $height ?? 1080,
            ];

            if ($width !== null) {
                $params['width'] = $width;
            }

            // Add background removal parameter if requested
            if ($removeBg) {
                $params['removebg'] = 'true';
            }

            $queryParams = http_build_query($params);
            $url = $this->apiUrl . '?' . $queryParams;

            // Make API request
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiToken,
            ])
                ->timeout($this->timeout)
                ->attach(
                    'file',
                    $fileContent['data']['content'],
                    $fileContent['data']['filename']
                )
                ->post($url);

            if (! $response->successful()) {
                // Fallback to GD if API fails (but can't do background removal with GD)
                if ($removeBg) {
                    return [
                        'success' => false,
                        'message' => 'Background removal failed: API returned error',
                    ];
                }
                return $this->compressViaGd($image, $quality, $height, $width, $format, $mode);
            }

            $compressedImage = $response->body();
            $originalSize = strlen($fileContent['data']['content']);
            $compressedSize = strlen($compressedImage);
            $compressionRatio = round((1 - ($compressedSize / $originalSize)) * 100, 2);

            return [
                'success' => true,
                'data' => [
                    'compressed_image' => $compressedImage,
                    'original_size' => $originalSize,
                    'compressed_size' => $compressedSize,
                    'compression_ratio' => $compressionRatio . '%',
                    'filename' => $fileContent['data']['filename'],
                    'format' => $format,
                ],
                'message' => 'Image compressed successfully using API',
            ];

        } catch (Throwable $t) {
            // Fallback to GD if API fails (but can't do background removal with GD)
            if ($removeBg) {
                return [
                    'success' => false,
                    'message' => 'Background removal failed: ' . $t->getMessage(),
                ];
            }
            return $this->compressViaGd($image, $quality, $height, $width, $format, $mode);
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
        string $disk = 's3',
        bool $removeBg = false
    ): array {
        try {
            $result = $this->compress($image, $quality, $height, $width, $format, $mode, $removeBg);

            if (! $result['success']) {
                return $result;
            }

            $saved = Storage::disk($disk)->put(
                $outputPath,
                $result['data']['compressed_image'],
                'public'
            );

            if (! $saved) {
                return [
                    'success' => false,
                    'message' => 'Failed to save compressed image',
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
                if (Storage::disk('s3')->exists($image)) {
                    return [
                        'success' => true,
                        'data' => [
                            'content' => Storage::disk('s3')->get($image),
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
}
