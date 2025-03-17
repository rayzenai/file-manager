<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Forms\Components;

use Exception;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Kirantimsina\FileManager\Facades\FileManager;
use Kirantimsina\FileManager\FileManagerService;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class MediaUpload extends FileUpload
{
    /**
     * Whether to upload the original version or resize.
     */
    protected bool $uploadOriginal = true;

    /**
     * Whether or not to convert (non-exempt images) to WebP.
     */
    protected bool $convertToWebp = true;

    /**
     * Quality to use if converting to WebP.
     */
    protected int $quality = 100;

    /**
     * Custom "make()" that can receive extra arguments.
     */
    public static function make(
        string $name,
        bool $uploadOriginal = true,
        bool $convertToWebp = true,
        int $quality = 100
    ): static {
        $static = parent::make($name);

        $static->uploadOriginal = $uploadOriginal;
        $static->convertToWebp = $convertToWebp;
        $static->quality = $quality;

        return $static;
    }

    /**
     * This is called automatically by Filament when the component is constructed.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Mark this as an image component
        $this->image();

        // Allowed file types (images + videos).
        $this->acceptedFileTypes([
            'image/webp',
            'image/avif',
            'image/jpg',
            'image/jpeg',
            'image/png',
            'image/svg+xml',
            'image/x-icon',
            'image/vnd.microsoft.icon',
            'video/mp4',
            'video/webm',
            'video/mpeg',
            'video/quicktime',
        ]);

        // Preview height
        $this->imagePreviewHeight('200');

        // If NOT uploading original, resize it (max size from config).
        if (! $this->uploadOriginal) {
            $this->imageResizeTargetHeight(strval(config('file-manager.max-upload-height')))
                ->imageResizeTargetWidth(strval(config('file-manager.max-upload-width')))
                ->imageResizeMode('contain')
                ->imageResizeUpscale(false);
        }

        // Make the file openable and set max size
        $this->openable()
            ->maxSize(intval(config('file-manager.max-upload-size')));

        // Handle the saving logic
        $this->saveUploadedFileUsing(function (TemporaryUploadedFile $file, $get, $model) {
            if (config('filesystems.default') === 'local') {
                throw new Exception('Please set the default disk to s3 to use this package.');
            }

            // Deduce the directory from your service
            $directory = FileManagerService::getUploadDirectory(class_basename($model));
            $isVideo = Str::lower(Arr::first(explode('/', $file->getMimeType()))) === 'video';

            // If converting to webp (and NOT a video), override the extension
            $extension = ($this->convertToWebp && ! $isVideo)
                ? 'webp'
                : $file->extension();

            $filename = (string) FileManagerService::filename(
                $file,
                static::tag($get),
                $extension
            );

            // If an image we can convert, do so. Otherwise, just store the file
            if ($this->convertToWebp && ! $isVideo && ! in_array($file->extension(), ['ico', 'svg', 'avif', 'webp'])) {
                $img = ImageManager::gd()->read(\file_get_contents(FileManager::getMediaPath($file->path())));
                $media = $img->toWebp($this->quality)->toFilePointer();
                Storage::disk('s3')->put("{$directory}/{$filename}", $media);
            } else {
                // Just store any file that doesn't need special image handling
                $file->storeAs($directory, $filename, 's3');
            }

            return "{$directory}/{$filename}";
        });

        // Determine the stored name for the file
        $this->getUploadedFileNameForStorageUsing(function (TemporaryUploadedFile $file, $get) {
            $isVideo = Str::lower(Arr::first(explode('/', $file->getMimeType()))) === 'video';

            $extension = ($this->convertToWebp && ! $isVideo)
                ? 'webp'
                : $file->extension();

            return (string) FileManagerService::filename($file, static::tag($get), $extension);
        });
    }

    /**
     * Example of how you pick a 'tag' for the file name.
     */
    private static function tag(callable $get)
    {
        if ($get('slug') && is_string($get('slug'))) {
            return $get('slug');
        }

        return $get('name');
    }
}
