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
    public static function make($field): FileUpload
    {
        return FileUpload::make($field, $hintLabel = null)
            ->image()
            ->acceptedFileTypes(['image/webp', 'image/avif  ', 'image/jpg', 'image/jpeg', 'image/png', 'image/svg+xml'])
            ->imagePreviewHeight('200')
            ->imageResizeTargetHeight(FileManagerService::ORIGINAL_SIZE)
            ->imageResizeTargetWidth(FileManagerService::ORIGINAL_SIZE)
            ->imageResizeMode('contain')
            ->imageResizeUpscale(false)
            ->openable()
            ->maxSize(FileManagerService::MAX_UPLOAD_SIZE)
            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file, $get, $model) {

                if (config('filesystems.default') === 'local') {
                    throw new Exception('Please set the default disk to s3 to use this package.');
                }

                //class name is predefined laravel method to get the class name
                $directory = FileManagerService::getUploadDirectory(class_basename($model));

             
                $filename = (string) FileManagerService::filename($file, static::tag($get), 'webp');

                $img = ImageManager::gd()->read(\file_get_contents(FileManager::getMediaPath($file->path())));

                $image = $img->toWebp(100)->toFilePointer();

                $filename = "{$directory}/{$filename}";
                Storage::put($filename, $image);

                return $filename;
            })
            ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file, $get): string {

                $filename = (string) FileManagerService::filename($file, static::tag($get), 'webp');

                return $filename;
            })->hint('Might not work with Safari, use another browser!');
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
