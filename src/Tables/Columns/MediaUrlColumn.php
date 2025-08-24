<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Tables\Columns;

use Kirantimsina\FileManager\Facades\FileManager;

class MediaUrlColumn extends MediaColumn
{
    protected bool $openInNewTab = true;

    public function openInNewTab(bool $open = true): static
    {
        $this->openInNewTab = $open;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->url(function ($record) {
            $images = $this->getImagesWithoutUrl($record);
            $imageFilename = $images[0] ?? null;

            if ($imageFilename) {
                // Check if viewPageUrl method exists - use it regardless of slug presence
                if (method_exists($record, 'viewPageUrl')) {
                    if ($this->viewCountField) {
                        return $record->viewPageUrl(field: $this->getName(), counter: $this->viewCountField);
                    }

                    return $record->viewPageUrl($this->getName());
                }

                // Return the full image URL as fallback for models without viewPageUrl
                return FileManager::getMediaPath($imageFilename);
            }

            return '#';
        });

        $this->openUrlInNewTab(function ($record) {
            if (! $this->openInNewTab) {
                return false;
            }

            $images = $this->getImagesWithoutUrl($record);
            $imageFilename = $images[0] ?? null;

            if ($imageFilename) {
                return true;
            }

            return false;
        });

        $this->getStateUsing(function ($record) {
            return $this->getStateForRecord($record);
        });

        if ($this->showMetadata && config('file-manager.media_metadata.enabled')) {
            $this->tooltip(function ($record) {
                $metadata = $this->getMetadataForField($record);

                if (! $metadata) {
                    return null;
                }

                $size = $this->formatBytes($metadata->file_size);
                $mimeType = $metadata->mime_type ?? 'Unknown type';

                return "Size: {$size} | Type: {$mimeType}";
            });
        }
    }
}
