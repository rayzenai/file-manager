<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Tables\Columns;

use Closure;
use Filament\Actions\Action;
use Illuminate\Support\Collection;
use Kirantimsina\FileManager\Facades\FileManager;
use Kirantimsina\FileManager\Forms\Components\MediaUpload;

class MediaModalColumn extends MediaColumn
{
    protected ?string $modalSize = null;

    protected string|Closure|null $heading = null;

    protected bool|Closure $allowEdit = false;

    protected bool $multiple = false;

    protected bool $downloadable = true;

    protected bool $previewable = true;

    protected bool $uploadOriginal = false;

    public function modalSize(?string $size): static
    {
        $this->modalSize = $size;

        return $this;
    }

    public function heading(string|Closure|null $heading): static
    {
        $this->heading = $heading;

        return $this;
    }

    public function allowEdit(bool|Closure $allow = true): static
    {
        $this->allowEdit = $allow;

        return $this;
    }

    public function multiple(bool $multiple = true): static
    {
        $this->multiple = $multiple;

        return $this;
    }

    public function downloadable(bool $downloadable = true): static
    {
        $this->downloadable = $downloadable;

        return $this;
    }

    public function previewable(bool $previewable = true): static
    {
        $this->previewable = $previewable;

        return $this;
    }

    public function uploadOriginal(bool $upload = true): static
    {
        $this->uploadOriginal = $upload;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->getStateUsing(function ($record) {
            return $this->getStateForRecord($record);
        });

        $this->tooltip(function ($record) {
            if (! $this->showMetadata || ! config('file-manager.media_metadata.enabled')) {
                return null;
            }

            $metadata = $this->getMetadataForField($record);

            if (! $metadata) {
                return null;
            }

            $size = $this->formatBytes($metadata->file_size);
            $mimeType = $metadata->mime_type ?? 'Unknown type';

            return "Size: {$size} | Type: {$mimeType}";
        });

        $action = Action::make($this->getName())
            ->modalContent(function ($record) {
                $images = $this->getImagesForModal($record);

                return view('file-manager::livewire.media-modal', ['images' => $images ?? []]);
            })
            ->slideOver()
            ->modalHeading(function ($record) {
                // If heading is set, use it (resolve if it's a closure)
                if ($this->heading !== null) {
                    return $this->heading instanceof Closure ? ($this->heading)($record) : $this->heading;
                }

                // Fallback to record name or title
                return isset($record->name) ? $record->name : (isset($record->title) ? $record->title : 'Image!');
            })
            ->modalWidth('2xl')
            ->schema(function ($record) {
                // Only show schema if editing is allowed
                $allowEdit = $this->allowEdit instanceof Closure ? ($this->allowEdit)($record) : $this->allowEdit;

                if (! $allowEdit) {
                    return [];
                }

                // Handle relationship fields
                if ($this->relationship) {
                    // Load the relationship to get current values
                    if (! $record->relationLoaded($this->relationship)) {
                        $record->load($this->relationship);
                    }

                    $related = $record->{$this->relationship};

                    // Get current images for default value
                    $currentImages = null;
                    if ($related instanceof Collection) {
                        $currentImages = $related->pluck($this->getName())->filter()->values()->toArray();
                    } elseif ($related && isset($related->{$this->getName()})) {
                        $currentImages = $related->{$this->getName()};
                    }

                    return [
                        MediaUpload::make('relationship_images')
                            ->label('Images')
                            ->default($currentImages)
                            ->uploadOriginal($this->uploadOriginal)
                            ->columnSpanFull()
                            ->downloadable($this->downloadable)
                            ->multiple($this->multiple)
                            ->hint('Upload new images to replace existing ones')
                            ->previewable($this->previewable)
                            ->required(false),
                    ];
                }

                // Original logic for non-relationship fields
                $mediaUpload = MediaUpload::make($this->getName())
                    ->uploadOriginal($this->uploadOriginal)
                    ->columnSpanFull()
                    ->downloadable($this->downloadable)
                    ->hint('Warning: This will replace the image/images.')
                    ->previewable($this->previewable)
                    ->required();

                // Check if field value is array to enable multiple
                if ($this->multiple || is_array($record->{$this->getName()})) {
                    $mediaUpload->multiple();
                }

                return [$mediaUpload];
            })
            ->action(function ($record, $data) {
                // Only process action if editing is allowed
                $allowEdit = $this->allowEdit instanceof Closure ? ($this->allowEdit)($record) : $this->allowEdit;

                if (! $allowEdit) {
                    return;
                }

                if ($this->relationship) {
                    // Handle relationship update
                    if (isset($data['relationship_images'])) {
                        // Load the relationship
                        if (! $record->relationLoaded($this->relationship)) {
                            $record->load($this->relationship);
                        }

                        $related = $record->{$this->relationship};

                        // Handle HasMany relationships
                        if ($related instanceof Collection) {
                            // Delete existing attachments
                            $record->{$this->relationship}()->delete();

                            // Create new attachments for each uploaded image
                            if (is_array($data['relationship_images'])) {
                                foreach ($data['relationship_images'] as $image) {
                                    $record->{$this->relationship}()->create([
                                        $this->getName() => $image,
                                    ]);
                                }
                            } else {
                                $record->{$this->relationship}()->create([
                                    $this->getName() => $data['relationship_images'],
                                ]);
                            }
                        }
                        // Handle HasOne/BelongsTo relationships
                        elseif ($related) {
                            $related->update([
                                $this->getName() => $data['relationship_images'],
                            ]);
                        } else {
                            // Create new related record if it doesn't exist
                            $record->{$this->relationship}()->create([
                                $this->getName() => $data['relationship_images'],
                            ]);
                        }
                    }
                } else {
                    // Original logic for non-relationship fields
                    $record->update([
                        $this->getName() => $data[$this->getName()],
                    ]);
                }
            })
            ->modalSubmitActionLabel(function ($record) {
                $allowEdit = $this->allowEdit instanceof Closure ? ($this->allowEdit)($record) : $this->allowEdit;

                return $allowEdit ? 'Save' : '';
            });

        $this->action($action);
    }

    protected function getImagesForModal($record): ?array
    {
        $displaySize = ($this->modalSize === null || $this->modalSize === 'full') ? null : $this->modalSize;

        if ($this->relationship) {
            // Load the relationship if not already loaded
            if (! $record->relationLoaded($this->relationship)) {
                $record->load($this->relationship);
            }

            $related = $record->{$this->relationship};

            if ($related instanceof Collection) {
                return $related->pluck($this->getName())->filter()
                    ->map(fn ($file) => FileManager::getMediaPath($file, $displaySize))
                    ->toArray();
            } elseif ($related && isset($related->{$this->getName()})) {
                return [FileManager::getMediaPath($related->{$this->getName()}, $displaySize)];
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
            return $temp->map(fn ($image) => FileManager::getMediaPath($image, $displaySize))->toArray();
        } elseif (is_array($temp)) {
            return array_map(fn ($image) => FileManager::getMediaPath($image, $displaySize), $temp);
        } elseif (! is_null($temp)) {
            return [FileManager::getMediaPath($temp, $displaySize)];
        }

        return null;
    }
}
