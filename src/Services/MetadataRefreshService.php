<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Services;

use Illuminate\Support\Facades\Storage;
use Kirantimsina\FileManager\Models\MediaMetadata;

class MetadataRefreshService
{
    /**
     * Refresh metadata for a single media record
     * 
     * @param MediaMetadata $record
     * @return array
     */
    public function refreshSingle(MediaMetadata $record): array
    {
        try {
            // Get the parent model
            $model = $record->mediable_type::find($record->mediable_id);
            
            if (!$model) {
                return [
                    'success' => false,
                    'message' => 'Parent model not found',
                ];
            }
            
            $updates = [];
            $changes = [];
            
            // Refetch SEO title if the model has seoTitleField method
            if (method_exists($model, 'seoTitleField')) {
                $seoField = $model->seoTitleField();
                $seoTitle = $model->$seoField ?? null;
                
                if ($seoTitle) {
                    // Clean control characters
                    $seoTitle = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $seoTitle);
                    // Limit to 160 characters
                    $seoTitle = substr($seoTitle, 0, 160);
                    
                    // Only update if different
                    if ($seoTitle !== $record->seo_title) {
                        $updates['seo_title'] = $seoTitle;
                        $oldTitle = $record->seo_title ? substr($record->seo_title, 0, 30) . '...' : 'empty';
                        $newTitle = substr($seoTitle, 0, 30) . '...';
                        $changes[] = "SEO title: {$oldTitle} → {$newTitle}";
                    }
                }
            }
            
            // Get the actual file name from the parent model's field
            $field = $record->mediable_field;
            $actualFileName = null;
            
            if (is_array($model->{$field})) {
                // If it's an array field, find the file in the array
                $values = $model->{$field};
                if (in_array($record->file_name, $values)) {
                    $actualFileName = $record->file_name; // File still exists in array
                } else {
                    // File name might have changed, we can't determine which one
                    return [
                        'success' => false,
                        'message' => 'File not found in model field array',
                    ];
                }
            } else {
                // Single value field - get the current value from model
                $actualFileName = $model->{$field};
            }
            
            // Check if the file name has changed
            if ($actualFileName && $actualFileName !== $record->file_name) {
                $updates['file_name'] = $actualFileName;
                $changes[] = "File name: {$record->file_name} → {$actualFileName}";
            }
            
            // If no file name found in model
            if (!$actualFileName) {
                return [
                    'success' => false,
                    'message' => 'File reference removed from model',
                ];
            }
            
            // Check if file exists and update file info using the actual file name
            $disk = $this->getDiskForFile($actualFileName);
            
            if (Storage::disk($disk)->exists($actualFileName)) {
                // Update file size
                $fileSize = Storage::disk($disk)->size($actualFileName);
                if ($fileSize !== $record->file_size) {
                    $oldSizeKb = round(($record->file_size ?? 0) / 1024, 1);
                    $newSizeKb = round($fileSize / 1024, 1);
                    $updates['file_size'] = $fileSize;
                    $changes[] = "File size: {$oldSizeKb}KB → {$newSizeKb}KB";
                }
                
                // Get MIME type if possible
                $mimeType = Storage::disk($disk)->mimeType($actualFileName);
                if ($mimeType && $mimeType !== $record->mime_type) {
                    $updates['mime_type'] = $mimeType;
                    $changes[] = "MIME type: {$record->mime_type} → {$mimeType}";
                }
                
                // For images, try to get dimensions
                if (str_starts_with($mimeType ?? $record->mime_type ?? '', 'image/')) {
                    $dimensions = $this->getImageDimensions($actualFileName, $disk);
                    if ($dimensions) {
                        if ($dimensions['width'] !== $record->width) {
                            $updates['width'] = $dimensions['width'];
                            $changes[] = "Width: {$record->width}px → {$dimensions['width']}px";
                        }
                        if ($dimensions['height'] !== $record->height) {
                            $updates['height'] = $dimensions['height'];
                            $changes[] = "Height: {$record->height}px → {$dimensions['height']}px";
                        }
                    }
                }
            } else {
                return [
                    'success' => false,
                    'message' => "File not found on storage: {$actualFileName}",
                ];
            }
            
            // Update the record if there are changes
            if (!empty($updates)) {
                $record->update($updates);
                
                return [
                    'success' => true,
                    'updated' => true,
                    'changes' => $changes,
                    'message' => implode("\n", $changes),
                ];
            }
            
            return [
                'success' => true,
                'updated' => false,
                'message' => 'Metadata is already up to date',
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * Refresh metadata for multiple records
     * 
     * @param \Illuminate\Support\Collection $records
     * @return array
     */
    public function refreshBulk($records): array
    {
        $successCount = 0;
        $failedCount = 0;
        $updatedCount = 0;
        $details = [];
        $failedRecords = [];
        
        foreach ($records as $record) {
            $result = $this->refreshSingle($record);
            
            if ($result['success']) {
                $successCount++;
                if ($result['updated'] ?? false) {
                    $updatedCount++;
                    $modelName = class_basename($record->mediable_type);
                    $details[] = "{$modelName} #{$record->mediable_id}: " . ($result['message'] ?? 'Updated');
                }
            } else {
                $failedCount++;
                $modelName = class_basename($record->mediable_type);
                $failedRecords[] = "{$modelName} #{$record->mediable_id}: " . ($result['message'] ?? 'Failed');
            }
        }
        
        return [
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'updated_count' => $updatedCount,
            'details' => $details,
            'failed_records' => $failedRecords,
        ];
    }
    
    /**
     * Determine the disk for a file
     * 
     * @param string $fileName
     * @return string
     */
    protected function getDiskForFile(string $fileName): string
    {
        // Default to S3, but you can add logic here to determine
        // the correct disk based on file path or other criteria
        return 's3';
    }
    
    /**
     * Get image dimensions from storage
     * 
     * @param string $fileName
     * @param string $disk
     * @return array|null
     */
    protected function getImageDimensions(string $fileName, string $disk): ?array
    {
        try {
            // Download the image temporarily to get dimensions
            $content = Storage::disk($disk)->get($fileName);
            $tempPath = sys_get_temp_dir() . '/' . uniqid('img_') . '.tmp';
            file_put_contents($tempPath, $content);
            
            $imageInfo = @getimagesize($tempPath);
            @unlink($tempPath);
            
            if ($imageInfo) {
                return [
                    'width' => $imageInfo[0],
                    'height' => $imageInfo[1],
                ];
            }
        } catch (\Exception $e) {
            // Silently fail - dimensions are optional
        }
        
        return null;
    }
}