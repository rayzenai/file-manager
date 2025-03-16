<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Traits;

use Exception;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Kirantimsina\FileManager\Facades\FileManager;
use Kirantimsina\FileManager\FileManagerService;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

abstract class ImageUpload
{
    public static function make(string $field, bool $uploadOriginal = true, bool $convertToWebp = true, ?int $quality = 100): FileUpload
    {
        return FileUpload::make($field, $hintLabel = '')
            ->image()
            ->acceptedFileTypes(['image/webp', 'image/avif  ', 'image/jpg', 'image/jpeg', 'image/png', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon', 'video/mp4', 'video/webm', 'video/mpeg', 'video/quicktime'])
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

                // class name is predefined laravel method to get the class name
                $directory = FileManagerService::getUploadDirectory(class_basename($model));

                $isVideo = Str::lower((Arr::first(explode('/', $file->getMimeType())))) === 'video';

                $filename = (string) FileManagerService::filename($file, static::tag($get), ($convertToWebp && ! $isVideo) ? 'webp' : $file->extension());

                if ($convertToWebp && ! $isVideo && ! in_array($file->extension(), ['ico', 'svg', 'avif', 'webp'])) {
                    $img = ImageManager::gd()->read(\file_get_contents(FileManager::getMediaPath($file->path())));
                    $media = $img->toWebp($quality)->toFilePointer();
                    Storage::put($filename, $media);

                } else {
                    // $media = file_get_contents(FileManager::getMediaPath($file->path()));
                    $file->storeAs($directory, $filename, 's3'); // Save any non-image to S3 as it was uploaded
                }

                return "{$directory}/{$filename}";
            })
            ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file, $get) use ($convertToWebp): string {

                $isVideo = Str::lower((Arr::first(explode('/', $file->getMimeType())))) === 'video';

                return (string) FileManagerService::filename($file, static::tag($get), ($convertToWebp && ! $isVideo) ? 'webp' : $file->extension());

            })->hint($hintLabel);
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
