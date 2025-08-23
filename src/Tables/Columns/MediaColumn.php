<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Tables\Columns;

use Closure;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Support\Collection;
use Kirantimsina\FileManager\Facades\FileManager;
use Kirantimsina\FileManager\Models\MediaMetadata;

abstract class MediaColumn extends ImageColumn
{
    protected string|int|Closure|null $thumbnailSize = null;

    protected string $viewCountField = '';

    protected bool $showMetadata = false;

    protected ?string $relationship = null;

    public function thumbnailSize(string|int|Closure|null $size): static
    {
        $this->thumbnailSize = $size;

        return $this;
    }

    public function viewCountField(string $field): static
    {
        $this->viewCountField = $field;

        return $this;
    }

    public function showMetadata(bool $show = true): static
    {
        $this->showMetadata = $show;

        return $this;
    }

    public function relationship(string $relationship): static
    {
        $this->relationship = $relationship;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->circular();
        $this->imageHeight(40);
        $this->stacked();
        $this->limitedRemainingText();
    }

    protected function getImagesWithoutUrl($record): ?array
    {
        if ($this->relationship) {
            // Load the relationship if not already loaded
            if (! $record->relationLoaded($this->relationship)) {
                $record->load($this->relationship);
            }

            $related = $record->{$this->relationship};

            if (! $related) {
                return null;
            }

            // Handle HasMany/BelongsToMany relationships (collections)
            if ($related instanceof Collection) {
                $images = $related->pluck($this->getName())->filter()->values();

                return $images->toArray();
            }

            // Handle HasOne/BelongsTo relationships (single model)
            if (isset($related->{$this->getName()})) {
                return [$related->{$this->getName()}];
            }

            return null;
        }

        // Original logic for non-relationship fields
        $keys = explode('.', $this->getName());
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

    protected function getStateForRecord($record, string|int|Closure|null $customSize = null)
    {
        $sizeToUse = $customSize ?? $this->thumbnailSize ?? config('file-manager.default_thumbnail_size', 'icon');

        // Resolve closure if needed
        if ($sizeToUse instanceof Closure) {
            $sizeToUse = $sizeToUse($record);
        }

        // If relationship is provided, use it to access the field
        if ($this->relationship) {
            // Load the relationship if not already loaded
            if (! $record->relationLoaded($this->relationship)) {
                $record->load($this->relationship);
            }

            $related = $record->{$this->relationship};

            if (! $related) {
                return null;
            }

            // Handle HasMany/BelongsToMany relationships (collections)
            if ($related instanceof Collection) {
                $images = $related->pluck($this->getName())->filter()->values();

                return $images->map(fn ($file) => FileManager::getMediaPath($file, $sizeToUse))->toArray();
            }

            // Handle HasOne/BelongsTo relationships (single model)
            if (isset($related->{$this->getName()})) {
                return FileManager::getMediaPath($related->{$this->getName()}, $sizeToUse);
            }

            return null;
        }

        // Original logic for non-relationship fields
        $keys = explode('.', $this->getName());
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
            return $temp->map(fn ($file) => FileManager::getMediaPath($file, $sizeToUse))->toArray();
        }

        if (is_array($temp)) {
            return array_map(fn ($file) => FileManager::getMediaPath($file, $sizeToUse), $temp);
        }

        if (empty($temp)) {
            return null;
        }

        return FileManager::getMediaPath($temp, $sizeToUse);
    }

    protected function getMetadataForField($record): ?MediaMetadata
    {
        if (! config('file-manager.media_metadata.enabled')) {
            return null;
        }

        // If using a relationship, get metadata from the related model
        if ($this->relationship) {
            if (! $record->relationLoaded($this->relationship)) {
                $record->load($this->relationship);
            }

            $related = $record->{$this->relationship};

            // For HasMany/BelongsToMany, get the first related record's metadata
            if ($related instanceof Collection && $related->isNotEmpty()) {
                $firstRelated = $related->first();

                return MediaMetadata::where('mediable_type', get_class($firstRelated))
                    ->where('mediable_id', $firstRelated->id)
                    ->where('mediable_field', $this->getName())
                    ->first();
            }

            // For HasOne/BelongsTo relationships
            if ($related && ! ($related instanceof Collection)) {
                return MediaMetadata::where('mediable_type', get_class($related))
                    ->where('mediable_id', $related->id)
                    ->where('mediable_field', $this->getName())
                    ->first();
            }

            return null;
        }

        // Original logic for non-relationship fields
        return MediaMetadata::where('mediable_type', get_class($record))
            ->where('mediable_id', $record->id)
            ->where('mediable_field', $this->getName())
            ->first();
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
