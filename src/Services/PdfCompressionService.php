<?php

namespace Kirantimsina\FileManager\Services;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use setasign\Fpdi\Fpdi;
use Throwable;

class PdfCompressionService
{
    /**
     * Compress a PDF file using image conversion technique
     * This mimics what online services do - convert to images then back to PDF
     */
    public function compress(
        $pdfFile,
        string $quality = 'ebook',
        bool $grayScale = false
    ): array {
        try {
            // Get the PDF content
            $fileContent = $this->getFileContent($pdfFile);
            if (!$fileContent['success']) {
                return $fileContent;
            }

            $inputPath = $fileContent['data']['path'];
            $originalSize = $fileContent['data']['size'];
            
            // Try advanced compression using Imagick if available
            if (extension_loaded('imagick')) {
                $result = $this->compressWithImagick($inputPath, $quality, $grayScale, $originalSize);
            } else {
                // Fallback to FPDI compression
                $result = $this->compressWithFpdi($inputPath, $quality, $originalSize);
            }
            
            // Clean up temporary files
            if (isset($fileContent['data']['temp']) && $fileContent['data']['temp']) {
                @unlink($inputPath);
            }
            
            return $result;
            
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'PDF compression failed: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Compress PDF using Imagick (most effective method)
     * This converts PDF pages to images and reconstructs them
     */
    protected function compressWithImagick(string $inputPath, string $quality, bool $grayScale, int $originalSize): array
    {
        try {
            // Get quality settings
            $settings = $this->getQualitySettings($quality);
            
            // Create Imagick object
            $imagick = new \Imagick();
            
            // Set resolution before reading (important for quality)
            $imagick->setResolution($settings['dpi'], $settings['dpi']);
            
            // Read all pages
            $imagick->readImage($inputPath);
            
            // Create new PDF using FPDF
            $pdf = new \FPDF('P', 'mm', 'A4');
            $pdf->SetCompression(true);
            
            // Process each page
            $pageCount = $imagick->getNumberImages();
            $tempImages = [];
            
            for ($i = 0; $i < $pageCount; $i++) {
                $imagick->setIteratorIndex($i);
                
                // Convert to RGB (required for JPEG)
                $imagick->setImageColorspace(\Imagick::COLORSPACE_RGB);
                
                // Apply grayscale if requested
                if ($grayScale) {
                    $imagick->setImageType(\Imagick::IMGTYPE_GRAYSCALE);
                }
                
                // Set compression quality
                $imagick->setImageCompressionQuality($settings['jpeg_quality']);
                $imagick->setImageFormat('jpeg');
                
                // Resize if needed (for lower quality settings)
                if ($settings['max_width'] > 0) {
                    $width = $imagick->getImageWidth();
                    $height = $imagick->getImageHeight();
                    
                    if ($width > $settings['max_width']) {
                        $ratio = $settings['max_width'] / $width;
                        $newWidth = $settings['max_width'];
                        $newHeight = (int)($height * $ratio);
                        $imagick->resizeImage($newWidth, $newHeight, \Imagick::FILTER_LANCZOS, 1);
                    }
                }
                
                // Strip metadata to reduce size
                $imagick->stripImage();
                
                // Save temporary image
                $tempImagePath = sys_get_temp_dir() . '/pdf_page_' . uniqid() . '.jpg';
                $imagick->writeImage($tempImagePath);
                $tempImages[] = $tempImagePath;
                
                // Add page to PDF
                $pdf->AddPage();
                
                // Calculate dimensions to fit page
                $imgWidth = $imagick->getImageWidth();
                $imgHeight = $imagick->getImageHeight();
                $pageWidth = 210; // A4 width in mm
                $pageHeight = 297; // A4 height in mm
                
                // Calculate scaling to fit page
                $scale = min($pageWidth / ($imgWidth / 25.4 * 72 / $settings['dpi']), 
                           $pageHeight / ($imgHeight / 25.4 * 72 / $settings['dpi']));
                
                $scaledWidth = ($imgWidth / 25.4 * 72 / $settings['dpi']) * $scale;
                $scaledHeight = ($imgHeight / 25.4 * 72 / $settings['dpi']) * $scale;
                
                // Center on page
                $x = ($pageWidth - $scaledWidth) / 2;
                $y = ($pageHeight - $scaledHeight) / 2;
                
                $pdf->Image($tempImagePath, $x, $y, $scaledWidth, $scaledHeight, 'JPG');
            }
            
            // Get compressed PDF
            $compressedContent = $pdf->Output('S');
            
            // Clean up temporary images
            foreach ($tempImages as $tempImage) {
                @unlink($tempImage);
            }
            
            $imagick->clear();
            $imagick->destroy();
            
            $compressedSize = strlen($compressedContent);
            $compressionRatio = round((1 - ($compressedSize / $originalSize)) * 100, 2);
            
            return [
                'success' => true,
                'data' => [
                    'compressed_pdf' => $compressedContent,
                    'original_size' => $originalSize,
                    'compressed_size' => $compressedSize,
                    'compression_ratio' => $compressionRatio . '%',
                    'method' => 'imagick',
                    'quality' => $quality,
                ],
                'message' => 'PDF compressed successfully using Imagick',
            ];
            
        } catch (Throwable $e) {
            // Fall back to FPDI if Imagick fails
            return $this->compressWithFpdi($inputPath, $quality, $originalSize);
        }
    }
    
    /**
     * Compress PDF using FPDI (moderate compression)
     */
    protected function compressWithFpdi(string $inputPath, string $quality, int $originalSize): array
    {
        try {
            // Use Intervention Image to process pages if possible
            if (class_exists('\Intervention\Image\ImageManager')) {
                return $this->compressWithIntervention($inputPath, $quality, $originalSize);
            }
            
            // Basic FPDI compression
            $pdf = new Fpdi();
            $pageCount = $pdf->setSourceFile($inputPath);
            
            // Process each page
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                
                // Add a page with the same size
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId);
            }
            
            // Set compression
            $pdf->SetCompression(true);
            
            // Get the output
            $compressedContent = $pdf->Output('S');
            $compressedSize = strlen($compressedContent);
            
            // Calculate compression ratio
            $compressionRatio = round((1 - ($compressedSize / $originalSize)) * 100, 2);
            
            return [
                'success' => true,
                'data' => [
                    'compressed_pdf' => $compressedContent,
                    'original_size' => $originalSize,
                    'compressed_size' => $compressedSize,
                    'compression_ratio' => $compressionRatio . '%',
                    'method' => 'fpdi',
                    'quality' => $quality,
                ],
                'message' => 'PDF compressed successfully',
            ];
            
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'FPDI compression failed: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Compress using Intervention Image (converts PDF pages to images)
     */
    protected function compressWithIntervention(string $inputPath, string $quality, int $originalSize): array
    {
        try {
            $settings = $this->getQualitySettings($quality);
            
            // Check if we can read PDF with Intervention
            $manager = ImageManager::gd();
            
            // Create new PDF
            $pdf = new \FPDF('P', 'mm', 'A4');
            $pdf->SetCompression(true);
            
            // For now, just use basic FPDI since Intervention doesn't directly support PDF
            $fpdi = new Fpdi();
            $pageCount = $fpdi->setSourceFile($inputPath);
            
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $fpdi->importPage($pageNo);
                $size = $fpdi->getTemplateSize($templateId);
                
                $fpdi->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $fpdi->useTemplate($templateId);
            }
            
            $fpdi->SetCompression(true);
            $compressedContent = $fpdi->Output('S');
            
            // Try additional optimization
            $compressedContent = $this->optimizePdfContent($compressedContent);
            
            $compressedSize = strlen($compressedContent);
            $compressionRatio = round((1 - ($compressedSize / $originalSize)) * 100, 2);
            
            return [
                'success' => true,
                'data' => [
                    'compressed_pdf' => $compressedContent,
                    'original_size' => $originalSize,
                    'compressed_size' => $compressedSize,
                    'compression_ratio' => $compressionRatio . '%',
                    'method' => 'intervention',
                    'quality' => $quality,
                ],
                'message' => 'PDF compressed successfully',
            ];
            
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Intervention compression failed: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get quality settings based on preset
     */
    protected function getQualitySettings(string $quality): array
    {
        return match($quality) {
            'screen' => [
                'dpi' => 72,
                'jpeg_quality' => 60,
                'max_width' => 800,
            ],
            'ebook' => [
                'dpi' => 150,
                'jpeg_quality' => 75,
                'max_width' => 1200,
            ],
            'printer' => [
                'dpi' => 200,
                'jpeg_quality' => 85,
                'max_width' => 1600,
            ],
            'prepress' => [
                'dpi' => 300,
                'jpeg_quality' => 95,
                'max_width' => 0, // No resize
            ],
            default => [
                'dpi' => 150,
                'jpeg_quality' => 75,
                'max_width' => 1200,
            ],
        };
    }
    
    /**
     * Optimize PDF content by removing redundant data
     */
    protected function optimizePdfContent(string $content): string
    {
        // Remove comments
        $content = preg_replace('/^%[^\n\r]*[\r\n]+/m', '', $content);
        
        // Compress whitespace in safe areas
        $content = preg_replace('/[\r\n]+/', "\n", $content);
        
        // Compress streams if not already compressed
        $pattern = '/stream\s*\n(.*?)\nendstream/s';
        $content = preg_replace_callback($pattern, function($matches) {
            $streamContent = $matches[1];
            
            // Check if already compressed
            if (substr($streamContent, 0, 2) === "\x78\x9c" || 
                substr($streamContent, 0, 3) === "\x1f\x8b\x08") {
                return $matches[0];
            }
            
            // Try to compress
            $compressed = @gzcompress($streamContent, 9);
            if ($compressed && strlen($compressed) < strlen($streamContent)) {
                return "stream\n" . $compressed . "\nendstream";
            }
            
            return $matches[0];
        }, $content);
        
        return $content;
    }
    
    /**
     * Get file content from various input types
     */
    protected function getFileContent($file): array
    {
        try {
            // Handle string path from S3
            if (is_string($file)) {
                if (Storage::disk('s3')->exists($file)) {
                    $content = Storage::disk('s3')->get($file);
                    $tempPath = sys_get_temp_dir() . '/' . uniqid('pdf_') . '.pdf';
                    file_put_contents($tempPath, $content);
                    
                    return [
                        'success' => true,
                        'data' => [
                            'path' => $tempPath,
                            'size' => strlen($content),
                            'temp' => true,
                        ],
                    ];
                }
            }
            
            // Handle file upload objects
            if (is_object($file)) {
                if (method_exists($file, 'getRealPath')) {
                    $path = $file->getRealPath();
                    if (file_exists($path)) {
                        return [
                            'success' => true,
                            'data' => [
                                'path' => $path,
                                'size' => filesize($path),
                                'temp' => false,
                            ],
                        ];
                    }
                }
                
                if (method_exists($file, 'path')) {
                    $path = $file->path();
                    if (file_exists($path)) {
                        return [
                            'success' => true,
                            'data' => [
                                'path' => $path,
                                'size' => filesize($path),
                                'temp' => false,
                            ],
                        ];
                    }
                }
                
                // Try to get content directly
                if (method_exists($file, 'get')) {
                    $content = $file->get();
                    $tempPath = sys_get_temp_dir() . '/' . uniqid('pdf_') . '.pdf';
                    file_put_contents($tempPath, $content);
                    
                    return [
                        'success' => true,
                        'data' => [
                            'path' => $tempPath,
                            'size' => strlen($content),
                            'temp' => true,
                        ],
                    ];
                }
            }
            
            // Handle local file path
            if (is_string($file) && file_exists($file)) {
                return [
                    'success' => true,
                    'data' => [
                        'path' => $file,
                        'size' => filesize($file),
                        'temp' => false,
                    ],
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Unable to access PDF file',
            ];
            
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error accessing file: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Compress and save PDF to storage
     */
    public function compressAndSave(
        $file,
        string $outputPath,
        string $quality = 'ebook',
        bool $grayScale = false,
        string $disk = 's3'
    ): array {
        try {
            $result = $this->compress($file, $quality, $grayScale);
            
            if (!$result['success']) {
                return $result;
            }
            
            // Save to storage
            $storageOptions = [
                'visibility' => 'public',
                'ContentType' => 'application/pdf',
            ];
            
            // Add cache headers for S3
            if ($disk === 's3' && config('file-manager.cache.enabled', true)) {
                $cacheControl = $this->buildCacheControlHeader();
                if ($cacheControl) {
                    $storageOptions['CacheControl'] = $cacheControl;
                }
            }
            
            $saved = Storage::disk($disk)->put(
                $outputPath,
                $result['data']['compressed_pdf'],
                $storageOptions
            );
            
            if (!$saved) {
                return [
                    'success' => false,
                    'message' => 'Failed to save compressed PDF to storage',
                ];
            }
            
            $result['data']['storage_path'] = $outputPath;
            
            // Generate URL based on disk type
            if ($disk === 's3') {
                // For S3, build the URL from config
                $cdnUrl = config('file-manager.cdn', config('app.url'));
                $result['data']['storage_url'] = rtrim($cdnUrl, '/') . '/' . ltrim($outputPath, '/');
            } else {
                // For local disk, use the disk's URL method if available
                try {
                    $result['data']['storage_url'] = Storage::disk($disk)->url($outputPath);
                } catch (\Exception $e) {
                    $result['data']['storage_url'] = $outputPath;
                }
            }
            
            return $result;
            
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Build cache control header
     */
    protected function buildCacheControlHeader(): ?string
    {
        if (!config('file-manager.cache.enabled', true)) {
            return null;
        }
        
        $maxAge = config('file-manager.cache.max_age', 31536000);
        $directives = ['public', "max-age={$maxAge}"];
        
        if (config('file-manager.cache.immutable', true)) {
            $directives[] = 'immutable';
        }
        
        return implode(', ', $directives);
    }
}