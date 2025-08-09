<?php

namespace Kirantimsina\FileManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;

class MediaMetadata extends Model
{
    protected $table = 'media_metadata';

    protected $fillable = [
        'mediable_type',
        'mediable_id',
        'mediable_field',
        'file_name',
        'file_size',
        'mime_type',
        'width',
        'height',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    /**
     * Get the parent mediable model
     */
    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get or create media metadata for a model
     */
    public static function updateOrCreateFor($model, string $field, array $data): self
    {
        // Handle both model instances and class name strings
        if (is_string($model)) {
            // If it's a string, we can't track metadata without an ID
            // This happens during creation before the model is saved
            return new self($data);
        }
        
        // Ensure we have a valid model with an ID
        if (!$model || !isset($model->id)) {
            return new self($data);
        }
        
        return self::updateOrCreate(
            [
                'mediable_type' => get_class($model),
                'mediable_id' => $model->id,
                'mediable_field' => $field,
            ],
            $data
        );
    }

    /**
     * Get media metadata for a model and field
     */
    public static function getFor($model, string $field): ?self
    {
        // Handle both model instances and class name strings
        if (is_string($model) || !$model || !isset($model->id)) {
            return null;
        }
        
        return self::where('mediable_type', get_class($model))
            ->where('mediable_id', $model->id)
            ->where('mediable_field', $field)
            ->first();
    }

    /**
     * Format file size to human readable
     */
    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        // Clear cache when a file larger than 500KB is created
        static::created(function (MediaMetadata $mediaMetadata) {
            if ($mediaMetadata->file_size > 500 * 1024) {
                static::clearLargeFilesCountCache();
            }
        });

        // Clear cache when file size changes and crosses the 500KB threshold
        static::updated(function (MediaMetadata $mediaMetadata) {
            if ($mediaMetadata->isDirty('file_size')) {
                $oldSize = $mediaMetadata->getOriginal('file_size');
                $newSize = $mediaMetadata->file_size;
                
                if ($oldSize > 500 * 1024 || $newSize > 500 * 1024) {
                    static::clearLargeFilesCountCache();
                }
            }
        });

        // Clear cache when a file larger than 500KB is deleted
        static::deleted(function (MediaMetadata $mediaMetadata) {
            if ($mediaMetadata->file_size > 500 * 1024) {
                static::clearLargeFilesCountCache();
            }
        });
    }

    public static function clearLargeFilesCountCache(): void
    {
        Cache::forget('media_metadata_large_files_count');
    }
}