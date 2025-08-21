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
                // Check if viewPageUrl method exists and if the model has a slug attribute with a value
                if (method_exists($record, 'viewPageUrl')) {
                    // Try to access slug - it might be a property, attribute, or accessor
                    try {
                        $hasSlug = isset($record->slug) && !empty($record->slug);
                    } catch (\Exception $e) {
                        $hasSlug = false;
                    }

                    if ($hasSlug) {
                        if ($this->viewCountField) {
                            return $record->viewPageUrl(field: $this->getName(), counter: $this->viewCountField);
                        }

                        return $record->viewPageUrl($this->getName());
                    }
                }

                // Return the full image URL as fallback
                return FileManager::getMediaPath($imageFilename);
            }

            return '#';
        });

        $this->openUrlInNewTab(function ($record) {
            if (!$this->openInNewTab) {
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

        $this->stacked();
        $this->limitedRemainingText();
        
        // Apply the thumbnail size to the parent ImageColumn
        if ($this->thumbnailSize !== null) {
            $this->imageSize($this->thumbnailSize);
        }

        if ($this->showMetadata && config('file-manager.media_metadata.enabled')) {
            $this->tooltip(function ($record) {
                $metadata = $this->getMetadataForField($record);

                if (!$metadata) {
                    return null;
                }

                $size = $this->formatBytes($metadata->file_size);
                $mimeType = $metadata->mime_type ?? 'Unknown type';

                return "Size: {$size} | Type: {$mimeType}";
            });
        }
    }
}