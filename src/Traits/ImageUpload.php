<?php

namespace Kirantimsina\FileManager\Traits;

use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Kirantimsina\FileManager\Facades\FileManager as FacadesFileManager;
use Kirantimsina\FileManager\FileManager;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

abstract class ImageUpload
{
    public static function make($field): FileUpload
    {
        return FileUpload::make($field, $hintLabel = null)
            ->image()
            ->acceptedFileTypes(['image/webp', 'image/avif  ', 'image/jpg', 'image/jpeg', 'image/png'])
            ->imagePreviewHeight(200)
            ->imageResizeTargetHeight(FileManager::ORIGINAL_SIZE)
            ->imageResizeTargetWidth(FileManager::ORIGINAL_SIZE)
            ->imageResizeMode('contain')
            ->imageResizeUpscale(false)
            ->openable()
            ->maxSize(FileManager::MAX_UPLOAD_SIZE)
            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file, $get, $model) {
                //class name is predefined laravel method to get the class name
                $directory = FileManager::getUploadDirectory(class_basename($model));

                $filename = (string) FileManager::filename($file, $get('slug') ?: $get('name'), 'webp');

                $img = ImageManager::gd()->read(\file_get_contents(FacadesFileManager::getMediaPath($file->path())));
                $image = $img->toWebp(100)->toFilePointer();

                $filename = "{$directory}/{$filename}";
                Storage::disk('public')->put($filename, $image);

                return $filename;
            })
            ->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file, $get): string {
                $filename = (string) FileManager::filename($file, $get('slug') ?: $get('name'), 'webp');

                return $filename;
            })->hint('Might not work with Safari, use another browser!');
    }
}
