<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Traits;

use Closure;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Support\Collection;
use Kirantimsina\FileManager\Facades\FileManager;

abstract class S3Image
{
    public static function make(
        string|array $field,
        ?string $size = 'thumb',
        ?bool $showInModal = false,
        ?string $modalSize = null, // null means full size
        string $label = 'Image',
        string|Closure $heading = 'Image!'
    ): ImageColumn {
        return ImageColumn::make($field)->label($label)
            ->when(! $showInModal, function (ImageColumn $column) use ($field) {

                $column->url(function ($record) use ($field) {
                    $images = static::getImagesWithoutUrl($field, $record, null);
                    $slug = $images[0] ?? null;

                    if ($slug) {
                        return '/media-page/' . $slug;
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

                return FileManager::getMediaPath($temp, $size);
            })->circular()
            ->stacked()
            ->height(35)
            ->limitedRemainingText()
            ->action(
                Action::make($field)
                    ->modalContent(function ($record) use ($field, $modalSize) {
                        $temp = static::getImages($field, $record, $modalSize);

                        // Return the view from your package
                        return view('file-manager::media-modal', [
                            'images' => $temp,
                        ]);
                    })->slideOver()
                    ->modalSubmitActionLabel('Close')
                    ->modalHeading($heading ?: fn ($record) => $record->name ?: ($record->title ?: 'Image!'))
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
