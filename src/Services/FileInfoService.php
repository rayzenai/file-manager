<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Services;

use Exception;
use Illuminate\Support\Facades\Storage;

class FileInfoService
{
    /**
     * Get comprehensive file information from storage
     *
     * @param string $filePath Path to the file in storage
     * @param string|null $disk Storage disk (defaults to configured default)
     * @return array|null File info array or null if file doesn't exist
     */
    public function getFileInfo(string $filePath, ?string $disk = null): ?array
    {
        $disk = Storage::disk($disk ?? config('filesystems.default'));

        // Check if file exists
        if (!$disk->exists($filePath)) {
            return null;
        }

        try {
            // Get file size
            $fileSize = $disk->size($filePath);

            // Detect MIME type from extension (more reliable than Storage::mimeType())
            $mimeType = $this->detectMimeTypeFromExtension($filePath);

            // Get dimensions for images
            $width = null;
            $height = null;

            if (str_starts_with($mimeType, 'image/')) {
                [$width, $height] = $this->getImageDimensions($disk, $filePath);
            }

            return [
                'size' => $fileSize,
                'mime_type' => $mimeType,
                'width' => $width,
                'height' => $height,
            ];

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Detect MIME type from file extension
     */
    protected function detectMimeTypeFromExtension(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            // Images
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp',
            'ico' => 'image/x-icon',

            // Videos
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            'flv' => 'video/x-flv',

            // Documents
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',

            // Archives
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
            'tar' => 'application/x-tar',
            'gz' => 'application/gzip',

            // Text
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xml' => 'application/xml',

            // Default
            default => 'application/octet-stream',
        };
    }

    /**
     * Get image dimensions from file
     */
    protected function getImageDimensions($disk, string $filePath): array
    {
        try {
            // Download file to temp location
            $tempFile = tempnam(sys_get_temp_dir(), 'img_');
            file_put_contents($tempFile, $disk->get($filePath));

            // Get image dimensions
            $imageInfo = @getimagesize($tempFile);

            // Clean up temp file
            @unlink($tempFile);

            if ($imageInfo && isset($imageInfo[0], $imageInfo[1])) {
                return [(int) $imageInfo[0], (int) $imageInfo[1]];
            }

            return [null, null];

        } catch (Exception $e) {
            return [null, null];
        }
    }
}
