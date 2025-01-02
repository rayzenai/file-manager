<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Traits;

use Exception;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Kirantimsina\FileManager\Facades\FileManager;
use Kirantimsina\FileManager\FileManagerService;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

abstract class ImageUpload
{
    public static function make(string $field, bool $uploadOriginal = true, bool $convertToWebp = true, ?int $quality = 100): FileUpload
    {
        return FileUpload::make($field, $hintLabel = null)
            ->image()
            ->acceptedFileTypes(['image/webp', 'image/avif  ', 'image/jpg', 'image/jpeg', 'image/png', 'image/svg+xml'])
            ->imagePreviewHeight('200')
            ->when(! $uploadOriginal, function (FileUpload $fileUpload) {
                $fileUpload->imageResizeTargetHeight(strval(config('file-manager.max-upload-height')))
                    ->imageResizeTargetWidth(strval(config('file-manager.max-upload-width')))
                    ->imageResizeMode('contain')
                    ->imageResizeUpscale(false);
            })
            ->openable()
            ->maxSize(intval(config('file-manager.max-upload-size')))
            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file, $get, $model) use ($convertToWebp, $quality) {

                if (config('filesystems.default') === 'local') {
                    throw new Exception('Please set the default disk to s3 to use this package.');
                }

                //class name is predefined laravel method to get the class name
                $directory = FileManagerService::getUploadDirectory(class_basename($model));

                $filename = (string) FileManagerService::filename($file, static::tag($get), $convertToWebp ? 'webp' : $file->extension());

                $img = ImageManager::gd()->read(\file_get_contents(FileManager::getMediaPath($file->path())));

                if ($convertToWebp) {
                    $image = $img->toWebp($quality)->toFilePointer();
                } else {
                    $image = file_get_contents(FileManager::getMediaPath($file->path()));
                }

                $filename = "{$directory}/{$filename}";
                Storage::put($filename, $image);

                return $filename;
            })
            ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file, $get): string {

                $filename = (string) FileManagerService::filename($file, static::tag($get), 'webp');

                return $filename;
            })->hint('Might not work with Safari!');
    }

    private static function tag(callable $get)
    {
        if ($get('slug') && is_string($get('slug'))) {
            return $get('slug');
        } else {
            return $get('name');
        }
    }
}
