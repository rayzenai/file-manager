<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Tables\Columns;

use Closure;
use Filament\Actions\Action;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Support\Collection;
use Kirantimsina\FileManager\Facades\FileManager;
use Kirantimsina\FileManager\Forms\Components\MediaUpload;
use Kirantimsina\FileManager\Models\MediaMetadata;

// TODO: This works with Image type only for now
abstract class MediaColumn
{
    public static function make(
        string|array $field,
        ?string $size = 'icon',
        ?bool $showInModal = false,
        ?string $modalSize = null, // null means full size
        string $label = 'Image',
        string|Closure $heading = '',
        string $viewCountField = '',
        bool $showMetadata = false,
        ?string $relationship = null
    ): ImageColumn {
        return ImageColumn::make($field)->label($label)
            ->when(! $showInModal, function (ImageColumn $column) use ($field, $viewCountField, $relationship) {

                $column->url(function ($record) use ($field, $viewCountField, $relationship) {
                    $images = static::getImagesWithoutUrl($field, $record, null, $relationship);
                    $imageFilename = $images[0] ?? null;

                    if ($imageFilename) {
                        // Check if viewPageUrl method exists and if the model has a slug attribute with a value
                        if (method_exists($record, 'viewPageUrl')) {
                            // Try to access slug - it might be a property, attribute, or accessor
                            try {
                                $hasSlug = isset($record->slug) && ! empty($record->slug);
                            } catch (\Exception $e) {
                                $hasSlug = false;
                            }

                            if ($hasSlug) {
                                if ($viewCountField) {
                                    return $record->viewPageUrl(field: $field, counter: $viewCountField);
                                }

                                return $record->viewPageUrl($field);
                            }
                        }

                        // Return the full image URL as fallback
                        return FileManager::getMediaPath($imageFilename);
                    }

                    return '#';
                });
            })->when(! $showInModal, function (ImageColumn $column) use ($field, $relationship) {

                $column->openUrlInNewTab(function ($record) use ($field, $relationship) {
                    $images = static::getImagesWithoutUrl($field, $record, null, $relationship);
                    $slug = $images[0] ?? null;

                    if ($slug) {
                        return true;
                    }

                    return false;
                });
            })
            ->getStateUsing(function ($record) use ($field, $size, $relationship) {
                // If relationship is provided, use it to access the field
                if ($relationship) {
                    // Load the relationship if not already loaded
                    if (!$record->relationLoaded($relationship)) {
                        $record->load($relationship);
                    }
                    
                    $related = $record->{$relationship};
                    
                    if (!$related) {
                        return null;
                    }
                    
                    // Handle HasMany/BelongsToMany relationships (collections)
                    if ($related instanceof Collection) {
                        $images = $related->pluck($field)->filter()->values();
                        return $images->map(fn ($file) => FileManager::getMediaPath($file, $size))->toArray();
                    }
                    
                    // Handle HasOne/BelongsTo relationships (single model)
                    if (isset($related->{$field})) {
                        return FileManager::getMediaPath($related->{$field}, $size);
                    }
                    
                    return null;
                }

                // Original logic for non-relationship fields
                $keys = explode('.', $field);
                $temp = $record;

                // Iterate through nested keys to get to the final value
                foreach ($keys as $key) {
                    if ($temp instanceof Collection) {
                        $temp = $temp->map(fn ($item) => $item->{$key});
                    } else {
                        $temp = $temp->{$key};
                    }
                }

                // Handle collections and arrays of images
                if ($temp instanceof Collection) {
                    return $temp->map(fn ($file) => FileManager::getMediaPath($file, $size))->toArray();
                }

                if (is_array($temp)) {
                    return array_map(fn ($file) => FileManager::getMediaPath($file, $size), $temp);
                }

                if (empty($temp)) {
                    return null;
                }

                return FileManager::getMediaPath($temp, $size);
            })
            // ->circular()
            // ->height(40)

            ->stacked()
            ->limitedRemainingText()
            ->when($showMetadata && config('file-manager.media_metadata.enabled'), function (ImageColumn $column) use ($field) {
                $column->tooltip(function ($record) use ($field) {
                    $metadata = static::getMetadataForField($record, $field);

                    if (! $metadata) {
                        return null;
                    }

                    $size = static::formatBytes($metadata->file_size);
                    $mimeType = $metadata->mime_type ?? 'Unknown type';

                    return "Size: {$size} | Type: {$mimeType}";
                });
            })
            ->action(
                Action::make($field)
                    ->schema(function ($record) use ($field, $relationship) {
                        // Handle relationship fields
                        if ($relationship) {
                            // Load the relationship to get current values
                            if (!$record->relationLoaded($relationship)) {
                                $record->load($relationship);
                            }
                            
                            $related = $record->{$relationship};
                            
                            // Get current images for default value
                            $currentImages = null;
                            if ($related instanceof Collection) {
                                $currentImages = $related->pluck($field)->filter()->values()->toArray();
                            } elseif ($related && isset($related->{$field})) {
                                $currentImages = $related->{$field};
                            }
                            
                            return [
                                MediaUpload::make('relationship_images')
                                    ->label('Images')
                                    ->default($currentImages)
                                    ->uploadOriginal()
                                    ->convertToWebp(false)
                                    ->columnSpanFull()
                                    ->downloadable()
                                    ->multiple()
                                    ->hint('Upload new images to replace existing ones')
                                    ->previewable()
                                    ->required(false),
                            ];
                        }
                        
                        // Original logic for non-relationship fields
                        return [
                            MediaUpload::make($field)
                                ->uploadOriginal()
                                ->convertToWebp(false)
                                ->columnSpanFull()
                                ->downloadable()
                                ->when(is_array($record->{$field}), function ($mediaUpload) {
                                    $mediaUpload->multiple();
                                })
                                ->hint('Warning: This will replace the image/images.')
                                ->previewable()
                                ->required(),
                        ];
                    })->action(function ($record, $data) use ($field, $relationship) {
                        if ($relationship) {
                            // Handle relationship update
                            if (isset($data['relationship_images'])) {
                                // Load the relationship
                                if (!$record->relationLoaded($relationship)) {
                                    $record->load($relationship);
                                }
                                
                                $related = $record->{$relationship};
                                
                                // Handle HasMany relationships
                                if ($related instanceof Collection) {
                                    // Delete existing attachments
                                    $record->{$relationship}()->delete();
                                    
                                    // Create new attachments for each uploaded image
                                    if (is_array($data['relationship_images'])) {
                                        foreach ($data['relationship_images'] as $image) {
                                            $record->{$relationship}()->create([
                                                $field => $image,
                                            ]);
                                        }
                                    } else {
                                        $record->{$relationship}()->create([
                                            $field => $data['relationship_images'],
                                        ]);
                                    }
                                } 
                                // Handle HasOne/BelongsTo relationships
                                elseif ($related) {
                                    $related->update([
                                        $field => $data['relationship_images'],
                                    ]);
                                } else {
                                    // Create new related record if it doesn't exist
                                    $record->{$relationship}()->create([
                                        $field => $data['relationship_images'],
                                    ]);
                                }
                            }
                        } else {
                            // Original logic for non-relationship fields
                            $record->update([
                                $field => $data[$field],
                            ]);
                        }
                    })
                    ->modalContent(function ($record, Action $action) use ($field, $modalSize, $relationship) {
                        $images = null;
                        
                        if ($relationship) {
                            // Load the relationship if not already loaded
                            if (!$record->relationLoaded($relationship)) {
                                $record->load($relationship);
                            }
                            
                            $related = $record->{$relationship};
                            
                            if ($related instanceof Collection) {
                                $images = $related->pluck($field)->filter()
                                    ->map(fn ($file) => FileManager::getMediaPath($file, $modalSize))
                                    ->toArray();
                            } elseif ($related && isset($related->{$field})) {
                                $images = [FileManager::getMediaPath($related->{$field}, $modalSize)];
                            }
                        } else {
                            // Use modalSize to determine the image size to display
                            // null or 'full' means original size, otherwise use the specified size
                            $displaySize = ($modalSize === null || $modalSize === 'full') ? null : $modalSize;
                            $images = static::getImages($field, $record, $displaySize);
                        }

                        return view('file-manager::livewire.media-modal', ['images' => $images ?? []]);
                    })->slideOver()
                    ->modalSubmitActionLabel('Save')
                    ->modalHeading($heading ?:
                        fn ($record) => isset($record->name) ? $record->name : (isset($record->title) ? $record->title : 'Image!'))
                    ->modalWidth('2xl')
            );
    }

    private static function getImagesWithoutUrl($field, $record, ?string $modalSize = null, ?string $relationship = null): ?array
    {
        if ($relationship) {
            // Load the relationship if not already loaded
            if (!$record->relationLoaded($relationship)) {
                $record->load($relationship);
            }
            
            $related = $record->{$relationship};
            
            if (!$related) {
                return null;
            }
            
            // Handle HasMany/BelongsToMany relationships (collections)
            if ($related instanceof Collection) {
                $images = $related->pluck($field)->filter()->values();
                return $images->toArray();
            }
            
            // Handle HasOne/BelongsTo relationships (single model)
            if (isset($related->{$field})) {
                return [$related->{$field}];
            }
            
            return null;
        }

        // Original logic for non-relationship fields
        $keys = explode('.', $field);
        $temp = $record;

        // Iterate through nested keys to get to the final value
        foreach ($keys as $key) {
            if ($temp instanceof Collection) {
                $temp = $temp->map(fn ($item) => $item->{$key});
            } else {
                $temp = $temp->{$key};
            }
        }

        if ($temp instanceof Collection) {
            return $temp->toArray();
        } elseif (is_array($temp)) {
            return $temp;
        } elseif (! is_null($temp)) {
            return [$temp];
        } else {
            return null;
        }
    }

    private static function getImages($field, $record, ?string $modalSize = null): ?array
    {
        $keys = explode('.', $field);
        $temp = $record;

        // Iterate through nested keys to get to the final value
        foreach ($keys as $key) {
            if ($temp instanceof Collection) {
                $temp = $temp->map(fn ($item) => $item->{$key});
            } else {
                $temp = $temp->{$key};
            }
        }

        if ($temp instanceof Collection) {
            $temp = $temp->map(fn ($image) => FileManager::getMediaPath($image, $modalSize))->toArray();
        } elseif (is_array($temp)) {
            $temp = array_map(fn ($image) => FileManager::getMediaPath($image, $modalSize), $temp);
        } elseif (! is_null($temp)) {
            $temp = [FileManager::getMediaPath($temp, $modalSize)];
        } else {
            return null;
        }

        return $temp;
    }

    /**
     * Get metadata for a field
     */
    protected static function getMetadataForField($record, string $field): ?MediaMetadata
    {
        if (! config('file-manager.media_metadata.enabled')) {
            return null;
        }

        return MediaMetadata::where('mediable_type', get_class($record))
            ->where('mediable_id', $record->id)
            ->where('mediable_field', $field)
            ->first();
    }

    /**
     * Format bytes to human readable
     */
    protected static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
