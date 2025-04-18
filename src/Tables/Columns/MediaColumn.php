<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Tables\Columns;

use Closure;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Support\Collection;
use Kirantimsina\FileManager\Facades\FileManager;
use Kirantimsina\FileManager\Forms\Components\MediaUpload;

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
        string $viewCountField = ''
    ): ImageColumn {
        return ImageColumn::make($field)->label($label)
            ->when(! $showInModal, function (ImageColumn $column) use ($field, $viewCountField) {

                $column->url(function ($record) use ($field, $viewCountField) {
                    $images = static::getImagesWithoutUrl($field, $record, null);
                    $slug = $images[0] ?? null;

                    if ($slug) {
                        if ($viewCountField) {

                            return $record->viewPageUrl(field: $field, counter: $viewCountField);
                        }

                        return $record->viewPageUrl($field);
                    }

                    return '#';
                });
            })->when(! $showInModal, function (ImageColumn $column) use ($field) {

                $column->openUrlInNewTab(function ($record) use ($field) {
                    $images = static::getImagesWithoutUrl($field, $record, null);
                    $slug = $images[0] ?? null;

                    if ($slug) {
                        return true;
                    }

                    return false;
                });
            })
            ->getStateUsing(function ($record) use ($field, $size) {

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
            ->action(
                Action::make($field)
                    ->form(function ($record) use ($field) {
                        return [
                            MediaUpload::make($field, uploadOriginal: true, convertToWebp: false)
                                ->columnSpanFull()
                                ->downloadable
                                ->when(is_array($record->{$field}), function ($mediaUpload) {
                                    $mediaUpload->multiple();
                                })
                                ->hint('Warning: This will replace the image/images.')
                                ->previewable()
                                ->required(),
                        ];
                    })->action(function ($record, $data) use ($field) {
                        $record->update([
                            $field => $data[$field],
                        ]);
                    })
                    ->modalContent(function ($record, Action $action) use ($field, $modalSize) {
                        $temp = static::getImages($field, $record, $modalSize);

                        return view('file-manager::livewire.media-modal', ['images' => $temp ?? []]);
                    })->slideOver()
                    ->modalSubmitActionLabel('Save')
                    ->modalHeading($heading ?:
                        fn ($record) => isset($record->name) ? $record->name : (isset($record->title) ? $record->title : 'Image!'))
                    ->modalWidth('2xl')
            );
    }

    private static function getImagesWithoutUrl($field, $record, ?string $modalSize = null): ?array
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
}
