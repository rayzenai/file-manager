<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kirantimsina\FileManager\Models\MediaMetadata;

class PopulateMediaMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $modelClass;

    public array $recordIds;

    public array $fields;

    public function __construct(
        string $modelClass,
        array $recordIds,
        array $fields
    ) {
        $this->modelClass = $modelClass;
        $this->recordIds = $recordIds;
        $this->fields = $fields;
    }

    public function handle(): void
    {
        try {
            $records = $this->modelClass::whereIn('id', $this->recordIds)->get();
        } catch (\Exception $e) {
            Log::error('Failed to fetch records: ' . $e->getMessage());
            throw $e;
        }

        foreach ($records as $record) {
            foreach ($this->fields as $field) {
                $value = $record->{$field};

                if (empty($value)) {
                    continue;
                }

                // Handle both single images and arrays
                $images = is_array($value) ? $value : [$value];

                foreach ($images as $image) {
                    if (empty($image) || ! is_string($image)) {
                        continue;
                    }

                    // Check if metadata already exists
                    $existingMetadata = MediaMetadata::where('mediable_type', $this->modelClass)
                        ->where('mediable_id', $record->id)
                        ->where('mediable_field', $field)
                        ->where('file_name', $image)
                        ->first();

                    // If exists, check if it needs fixing (wrong mime type, missing file size, or missing dimensions)
                    if ($existingMetadata) {
                        $needsUpdate = false;
                        $updates = [];

                        // Check if any data is missing or incorrect
                        if (in_array($existingMetadata->mime_type, ['image', 'video', 'document'])
                            || $existingMetadata->file_size == 0
                            || ($existingMetadata->mime_type && str_starts_with($existingMetadata->mime_type, 'image/') && $existingMetadata->width === null)) {

                            $fileInfo = $this->getFileInfo($image);

                            if ($fileInfo) {
                                // Update MIME type if wrong
                                if ($existingMetadata->mime_type !== $fileInfo['mime_type']) {
                                    Log::info("Fixing mime type for {$image}: {$existingMetadata->mime_type} -> {$fileInfo['mime_type']}");
                                    $updates['mime_type'] = $fileInfo['mime_type'];
                                    $needsUpdate = true;
                                }

                                // Update file size if missing
                                if ($existingMetadata->file_size == 0 && $fileInfo['size'] > 0) {
                                    Log::info("Fixing file size for {$image}: 0 -> {$fileInfo['size']}");
                                    $updates['file_size'] = $fileInfo['size'];
                                    $needsUpdate = true;
                                }

                                // Update dimensions if missing (for images)
                                if ($fileInfo['width'] && $fileInfo['height']) {
                                    if ($existingMetadata->width === null) {
                                        $updates['width'] = $fileInfo['width'];
                                        $needsUpdate = true;
                                    }
                                    if ($existingMetadata->height === null) {
                                        $updates['height'] = $fileInfo['height'];
                                        $needsUpdate = true;
                                    }
                                }

                                if ($needsUpdate) {
                                    $existingMetadata->update($updates);
                                }
                            }
                        }

                        continue;
                    }

                    // Get file info from storage
                    $fileInfo = $this->getFileInfo($image);

                    if (! $fileInfo) {
                        Log::warning("File not found in storage: {$image}");

                        continue;
                    }

                    // Create media metadata record
                    try {
                        MediaMetadata::create([
                            'mediable_type' => $this->modelClass,
                            'mediable_id' => $record->id,
                            'mediable_field' => $field,
                            'file_name' => $image,
                            'mime_type' => $fileInfo['mime_type'],
                            'file_size' => $fileInfo['size'],
                            'width' => $fileInfo['width'],
                            'height' => $fileInfo['height'],
                            'metadata' => [
                                'original_name' => basename($image),
                                'populated_from_existing' => true,
                                'populated_at' => now()->toIso8601String(),
                            ],
                        ]);
                    } catch (\Exception $e) {
                        Log::error("Failed to create MediaMetadata for {$this->modelClass}:{$record->id} field:{$field} file:{$image} - " . $e->getMessage());
                        throw $e;
                    }
                }
            }
        }
    }

    private function getFileInfo(string $path): ?array
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
            $mimeType = $this->detectMimeType($tempPath, $path);

            // Get dimensions if it's an image
            $width = null;
            $height = null;

            if (str_starts_with($mimeType, 'image/')) {
                try {
                    if ($imageInfo = getimagesize($tempPath)) {
                        $width = $imageInfo[0];
                        $height = $imageInfo[1];

                        // getimagesize also returns mime type, use it as fallback if needed
                        if (!empty($imageInfo['mime']) && $mimeType === 'application/octet-stream') {
                            $mimeType = $imageInfo['mime'];
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning("Could not get image dimensions for {$path}: " . $e->getMessage());
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
        } catch (\Exception $e) {
            Log::error("Error getting file info for {$path}: " . $e->getMessage());

            return null;
        }
    }

    /**
     * Detect MIME type using multiple methods for better accuracy
     */
    private function detectMimeType(string $tempPath, string $originalPath): string
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
        if ($imageInfo && !empty($imageInfo['mime'])) {
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
        } catch (\Exception $e) {
            // Ignore and use default
        }

        // Default fallback
        return 'application/octet-stream';
    }
}
