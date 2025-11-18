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
use Kirantimsina\FileManager\Services\FileInfoService;

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

        $fileInfoService = new FileInfoService;

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

                            $fileInfo = $fileInfoService->getFileInfo($image);

                            if ($fileInfo) {
                                // Update MIME type if wrong
                                if ($existingMetadata->mime_type !== $fileInfo['mime_type']) {
                                    Log::info("Fixing mime type for {$image}: {$existingMetadata->mime_type} -> {$fileInfo['mime_type']}");
                                    $updates['mime_type'] = $fileInfo['mime_type'];
                                    $needsUpdate = true;
                                }

                                // Update file size if missing
                                if ($existingMetadata->file_size == 0 && ($fileInfo['size'] ?? 0) > 0) {
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
                    $fileInfo = $fileInfoService->getFileInfo($image);

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
}
