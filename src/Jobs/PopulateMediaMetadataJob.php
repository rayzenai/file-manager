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
            Log::error("Failed to fetch records: " . $e->getMessage());
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
                    if (empty($image) || !is_string($image)) {
                        continue;
                    }
                    
                    // Check if metadata already exists
                    $exists = MediaMetadata::where('mediable_type', $this->modelClass)
                        ->where('mediable_id', $record->id)
                        ->where('mediable_field', $field)
                        ->where('file_name', $image)
                        ->exists();
                    
                    if ($exists) {
                        continue;
                    }
                    
                    // Get file info from storage
                    $fileInfo = $this->getFileInfo($image);
                    
                    if (!$fileInfo) {
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
            
            if (!$disk->exists($path)) {
                return null;
            }
            
            $size = $disk->size($path);
            $mimeType = $disk->mimeType($path);
            
            // Get dimensions if it's an image
            $width = null;
            $height = null;
            
            if (str_starts_with($mimeType, 'image/')) {
                try {
                    // Download file temporarily to get dimensions
                    $tempPath = tempnam(sys_get_temp_dir(), 'img');
                    file_put_contents($tempPath, $disk->get($path));
                    
                    if ($imageInfo = getimagesize($tempPath)) {
                        $width = $imageInfo[0];
                        $height = $imageInfo[1];
                    }
                    
                    unlink($tempPath);
                } catch (\Exception $e) {
                    Log::warning("Could not get image dimensions for {$path}: " . $e->getMessage());
                }
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
}