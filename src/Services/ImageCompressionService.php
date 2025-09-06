<?php

namespace Kirantimsina\FileManager\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class ImageCompressionService
{
    private string $compressionMethod;

    private string $apiUrl;

    private string $apiToken;
    
    private string $bgRemovalApiUrl;
    
    private string $bgRemovalApiToken;

    private int $defaultQuality;

    private string $defaultFormat;

    private string $defaultMode;

    private int $timeout;
    
    private int $bgRemovalTimeout;
    
    private int $maxHeight;
    
    private int $maxWidth;

    public function __construct()
    {
        $this->compressionMethod = config('file-manager.compression.method', 'gd');
        $this->apiUrl = config('file-manager.compression.api.url', '');
        $this->apiToken = config('file-manager.compression.api.token', '');
        $this->bgRemovalApiUrl = config('file-manager.compression.api.bg_removal.url', '');
        $this->bgRemovalApiToken = config('file-manager.compression.api.bg_removal.token', '');
        $this->defaultQuality = config('file-manager.compression.quality', 85);
        $this->defaultFormat = config('file-manager.compression.format', 'webp');
        $this->defaultMode = config('file-manager.compression.mode', 'contain');
        $this->timeout = config('file-manager.compression.api.timeout', 30);
        $this->bgRemovalTimeout = config('file-manager.compression.api.bg_removal.timeout', 60);
        $this->maxHeight = config('file-manager.compression.max_height', 2160);
        $this->maxWidth = config('file-manager.compression.max_width', 3840);
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
                $finalImg = ImageManager::gd()->read($compressedContent);
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
                    'compression_method' => 'gd',
                ],
                'message' => 'Image compressed successfully using GD',
            ];

        } catch (Throwable $t) {
            // Provide more context about the error
            $errorMessage = 'GD compression failed: ' . $t->getMessage();

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
            
            // Get original image dimensions to calculate enforced dimensions
            $originalImg = ImageManager::gd()->read($fileContent['data']['content']);
            $originalWidth = $originalImg->width();
            $originalHeight = $originalImg->height();
            
            // Calculate enforced dimensions based on max constraints
            $enforcedDimensions = $this->calculateEnforcedDimensions(
                $originalWidth,
                $originalHeight,
                $width,
                $height
            );
            
            // Use enforced dimensions for API call
            $finalWidth = $enforcedDimensions['width'];
            $finalHeight = $enforcedDimensions['height'];
            
            // Determine which API to use
            $useBackgroundRemovalApi = $removeBg && !empty($this->bgRemovalApiUrl);
            $apiUrl = $useBackgroundRemovalApi ? $this->bgRemovalApiUrl : $this->apiUrl;
            $apiToken = $useBackgroundRemovalApi ? $this->bgRemovalApiToken : $this->apiToken;
            $apiTimeout = $useBackgroundRemovalApi ? $this->bgRemovalTimeout : $this->timeout;
            
            // If no appropriate API is configured, fall back to GD
            if (empty($apiUrl)) {
                if ($removeBg) {
                    return [
                        'success' => false,
                        'message' => 'Background removal requested but no API configured',
                    ];
                }
                return $this->compressViaGd($image, $quality, $finalHeight, $finalWidth, $format, $mode);
            }
            
            // Check file size - skip API for files over 5MB to avoid timeouts (except for bg removal)
            $fileSizeInMb = strlen($fileContent['data']['content']) / (1024 * 1024);
            if ($fileSizeInMb > 5 && !$removeBg) {
                // For large files, fall back to GD unless background removal is required
                $gdResult = $this->compressViaGd($image, $quality, $finalHeight, $finalWidth, $format, $mode);
                if ($gdResult['success']) {
                    $gdResult['data']['compression_method'] = 'gd_fallback';
                    $gdResult['data']['api_fallback_reason'] = 'File too large for API (' . round($fileSizeInMb, 2) . ' MB)';
                }
                return $gdResult;
            }

            // Build API request parameters
            $params = [
                'format' => $format,
                'mode' => $mode,
                'quality' => $quality,
            ];

            // Always send dimensions to API (API requires these parameters)
            $params['width'] = $finalWidth;
            $params['height'] = $finalHeight;

            // Add background removal parameter if requested
            if ($removeBg) {
                $params['removebg'] = 'true';
            }

            $queryParams = http_build_query($params);
            $url = $apiUrl . '?' . $queryParams;

            // Make API request
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiToken,
            ])
                ->timeout($apiTimeout)
                ->attach(
                    'file',
                    $fileContent['data']['content'],
                    $fileContent['data']['filename']
                )
                ->post($url);

            if (! $response->successful()) {
                // Fallback to GD if API fails (but can't do background removal with GD)
                if ($removeBg) {
                    $statusCode = $response->status();
                    $errorMessage = match($statusCode) {
                        401 => 'Authentication failed - check API token',
                        403 => 'Access forbidden',
                        404 => 'API endpoint not found',
                        413 => 'Image too large',
                        429 => 'Too many requests - please try again later',
                        500 => 'Server error - please try again',
                        503 => 'Service temporarily unavailable - Cloud Run is scaling up, please try again',
                        default => "API returned status {$statusCode}"
                    };
                    
                    return [
                        'success' => false,
                        'message' => "Background removal failed: {$errorMessage}",
                    ];
                }

                // Fallback to GD and mark it as a fallback
                $gdResult = $this->compressViaGd($image, $quality, $finalHeight, $finalWidth, $format, $mode);
                if ($gdResult['success']) {
                    $gdResult['data']['compression_method'] = 'gd_fallback';
                    $responseBody = $response->body();
                    $statusCode = $response->status();
                    $gdResult['data']['api_fallback_reason'] = "API returned status {$statusCode}" . 
                        ($responseBody ? ". Response: " . substr($responseBody, 0, 100) : '');
                }

                return $gdResult;
            }

            $compressedImage = $response->body();
            $originalSize = strlen($fileContent['data']['content']);
            $compressedSize = strlen($compressedImage);
            $compressionRatio = round((1 - ($compressedSize / $originalSize)) * 100, 2);
            
            // Get dimensions of the compressed image (actual dimensions from the API result)
            try {
                $compressedImg = ImageManager::gd()->read($compressedImage);
                $actualWidth = $compressedImg->width();
                $actualHeight = $compressedImg->height();
                
                // Use the actual dimensions from the compressed image
                $finalWidth = $actualWidth;
                $finalHeight = $actualHeight;
            } catch (\Exception $e) {
                // If we can't read dimensions, keep the enforced dimensions we calculated earlier
                // $finalWidth and $finalHeight already contain the enforced dimensions
            }

            $apiType = $useBackgroundRemovalApi ? 'api_bg_removal' : 'api';
            $apiName = $useBackgroundRemovalApi ? 'Cloud Run (BG Removal)' : 'Lambda';
            
            return [
                'success' => true,
                'data' => [
                    'compressed_image' => $compressedImage,
                    'original_size' => $originalSize,
                    'compressed_size' => $compressedSize,
                    'compression_ratio' => $compressionRatio . '%',
                    'filename' => $fileContent['data']['filename'],
                    'format' => $format,
                    'width' => $finalWidth,
                    'height' => $finalHeight,
                    'compression_method' => $apiType,
                    'api_used' => $apiName,
                ],
                'message' => 'Image compressed successfully using ' . $apiName . ' API',
            ];

        } catch (Throwable $t) {
            // Fallback to GD if API fails (but can't do background removal with GD)
            if ($removeBg) {
                $errorMessage = $t->getMessage();
                
                // Provide more user-friendly error messages
                if (str_contains($errorMessage, 'cURL error 28') || str_contains($errorMessage, 'Operation timed out')) {
                    $errorMessage = 'Request timed out - Cloud Run may be starting up. Please try again in a few seconds.';
                } elseif (str_contains($errorMessage, 'Could not resolve host')) {
                    $errorMessage = 'Cannot connect to API server - check your internet connection';
                }
                
                return [
                    'success' => false,
                    'message' => 'Background removal failed: ' . $errorMessage,
                ];
            }

            // Fallback to GD and mark it as a fallback
            $gdResult = $this->compressViaGd($image, $quality, $finalHeight, $finalWidth, $format, $mode);
            if ($gdResult['success']) {
                $gdResult['data']['compression_method'] = 'gd_fallback';
                $gdResult['data']['api_fallback_reason'] = 'API exception: ' . $t->getMessage();
            }

            return $gdResult;
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

            // Use putFileAs for more reliable S3 uploads
            try {
                // Prepare storage options with cache headers for S3
                if ($disk === 's3') {
                    // Determine content type based on format
                    $contentType = match($format) {
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
                    
                    $saved = Storage::disk($disk)->put(
                        $outputPath,
                        $result['data']['compressed_image'],
                        $storageOptions
                    );
                } else {
                    $saved = Storage::disk($disk)->put(
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
    
    /**
     * Build the Cache-Control header value from config
     */
    protected function buildCacheControlHeader(): ?string
    {
        if (!config('file-manager.cache.enabled', true)) {
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
     * @param int $originalWidth Original image width
     * @param int $originalHeight Original image height  
     * @param int|null $requestedWidth Explicitly requested width (can be null)
     * @param int|null $requestedHeight Explicitly requested height (can be null)
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
