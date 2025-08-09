<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager;

use Exception;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Gif\Exceptions\NotReadableException;
use Intervention\Image\Exceptions\NotSupportedException;
use Intervention\Image\ImageManager;
use Kirantimsina\FileManager\Facades\FileManager;
use Kirantimsina\FileManager\Jobs\ResizeImages;
use Symfony\Component\Console\Output\ConsoleOutput;

class FileManagerService
{
    /**
     * Get image sizes from config with fallback defaults
     */
    public static function getImageSizes(): array
    {
        return config('file-manager.image_sizes', [
            'icon' => 120,
            'thumb' => 360,
            'card' => 640,
            'full' => 1080,
            'ultra' => 1920,
        ]);
    }

    public static function uploadImages($model, $files, $tag = null, $fit = false, $resize = true)
    {
        $result = [];
        foreach ($files as $file) {
            $upload = static::upload(
                model: $model,
                file: $file,
                tag: $tag,
                fit: $fit,
                resize: $resize
            );
            $result[] = $upload['file'];
        }

        return ! empty($result) ? ['status' => true, 'files' => $result] : ['status' => false];
    }

    public static function filename($file, $tag = null, $ext = null)
    {
        if (Auth::check()) {
            $filename = Auth::id() . time() . '-' . Str::random(10);
        } else {
            $filename = time() . '-' . Str::random(10);
        }

        if (! empty($tag)) {
            $filename = Str::slug($tag) . '-' . $filename;
        }

        $orginalName = $file->getClientOriginalName();

        if (! $ext) {
            $ext = $file->extension();
        }

        if (Str::contains($orginalName, '.apk')) {
            $ext = 'apk';
        }
        $filename = $filename . '.' . $ext;

        return $filename;
    }

    public static function uploadBase64($model, $base64Image, $tag = null, $fit = false, $resize = true)
    {
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Image)) {
            $fileData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64Image));
            $tmpFilePath = sys_get_temp_dir() . '/' . Str::uuid()->toString();
            file_put_contents($tmpFilePath, $fileData);
            $tmpFile = new File($tmpFilePath);
            $file = new UploadedFile(
                $tmpFile->getPathname(),
                $tmpFile->getFilename(),
                $tmpFile->getMimeType(),
                0,
                true // Mark it as test, since the file isn't from real HTTP POST.
            );

            return static::upload($model, $file, $tag, $fit, $resize);
        } else {
            return ['status' => false];
        }
    }

    public static function getUploadDirectory($model)
    {
        return config('file-manager.model.' . $model);
    }

    public static function upload($model, $file, $tag = null, $fit = false, $resize = true, $webp = false, $reencode = false)
    {
        $mime = $file->getMimeType();
        $path = static::getUploadDirectory($model);

        $filename = static::filename($file, $tag);

        // Forcing all images to webp
        if ($reencode && in_array($mime, ['image/jpg', 'image/jpeg', 'image/png', 'image/webp', 'image/avif'])) {
            $img = ImageManager::gd()->read($file);

            if ($webp) {
                $img = $img->toWebp(100)->toFilePointer();
                $ext = 'webp';

            } else {
                $img = $img->encode()->toFilePointer();
                $ext = Arr::last(explode('.', $filename));
            }
            $filename = \explode('.', $filename)[0] . '.' . $ext;
        }

        $uploadedFilename = $file->storeAs($path, $filename);

        if ($resize && in_array($mime, ['image/jpg', 'image/jpeg', 'image/png', 'image/webp', 'image/avif'])) {
            ResizeImages::dispatch([$uploadedFilename]);
        }

        if ($uploadedFilename) {
            return [
                'status' => true,
                'file' => $uploadedFilename,
                'link' => FileManager::getMediaPath($uploadedFilename),
                'mime' => $mime,
            ];
        } else {
            return [
                'status' => false,
            ];
        }
    }

    public static function resizeImage($file, $fit = false): void
    {
        // contain, crop, remake
        $output = new ConsoleOutput;

        $exploded = explode('/', $file);
        $filename = Arr::last($exploded);
        $path = Arr::first($exploded);

        try {

            $disk = config('filesystems.default');

            if ($disk === 'local') {
                throw new Exception('Local disk is not supported for resizing images. Please use s3.');
            } else {
                $img = ImageManager::gd()->read(\file_get_contents(FileManager::getMediaPath($file)));

            }

            // Get sizes from config
            $sizes = static::getImageSizes();

            foreach ($sizes as $key => $val) {
                $val = intval($val);
                if ($fit) {
                    $img->coverDown(width: $val, height: $val);
                } else {
                    $img->scaleDown(height: $val);
                }
                // The below code recreates the image in desired size by placing the image at the center
                // else {
                //     $newImage = ImageManager::gd()->create($val, $val);
                //     $img->scale(height: $val);

                //     $newImage->place($img, 'center');
                //     $img = $newImage;
                // }

                $image = $img->toWebp(85)->toFilePointer();

                $status = Storage::disk()->put(
                    "{$path}/{$key}/{$filename}",
                    $image,
                );

                if ($status) {
                    $output->writeln("Resized to {$path}/{$key}/{$filename}");
                } else {
                    $output->writeln("Cound NOT Resize to {$path}/{$key}/{$filename}");
                }
            }
        } catch (NotSupportedException $e) {
            $output->writeln("{$filename} econding format is not supported!");
        } catch (NotReadableException  $e) {
            $output->writeln("{$filename} is not readable!");
        }
    }

    public static function moveTempImage($model, $tempFile)
    {
        $newFile = static::moveTempImageWithoutResize($model, $tempFile);

        if ($newFile) {
            ResizeImages::dispatch([$newFile]);
        }

        return $newFile;
    }

    public static function moveTempImageWithoutResize($model, $tempFile)
    {
        $path = static::getUploadDirectory($model);

        $newFile = $path . '/' . Arr::last(explode('/', $tempFile));

        $status = Storage::disk()->move($tempFile, $newFile);

        return $status ? $newFile : null;
    }

    public static function deleteImagesArray($arr): void
    {
        foreach ($arr as $filename) {
            static::deleteImage($filename);
        }
    }

    public static function deleteImage($filename): void
    {
        $s3 = Storage::disk();
        $s3->delete($filename);

        $name = Arr::last(explode('/', $filename));
        $model = Arr::first(explode('/', $filename));

        // Get sizes from config
        $sizes = static::getImageSizes();

        foreach ($sizes as $key => $val) {
            $s3->delete("{$model}/{$key}/{$name}");
        }
    }

    public function uploadTempVideo($file)
    {
        if (Auth::check()) {
            $filename = Auth::id() . time() . '-' . Str::random(5) . '.' . $file->extension();
        } else {
            $filename = time() . '-' . Str::random(10) . '.' . $file->extension();
        }

        $upload = Storage::disk()->putFileAs(
            'temp',
            new File($file),
            $filename,
            'public'
        );

        if ($upload) {
            return [
                'status' => true,
                'file' => explode('/', $upload)[1],
                'link' => FileManager::getMediaPath($upload),
            ];
        } else {
            return [
                'status' => false,
            ];
        }
    }

    public function moveTempVideo($filename, $to)
    {
        Storage::disk()->move('temp/' . $filename, $to . $filename);

        return true;
    }

    /**
     * Get the main media URL with trailing slash
     */
    public static function mainMediaUrl(): string
    {
        $path = config('file-manager.cdn');

        if (Str::endsWith($path, '/')) {
            return $path;
        }

        return "{$path}/";
    }

    /**
     * Get the full media path for a file with optional size
     */
    public static function getMediaPath($filename = null, $size = null): ?string
    {
        if ($filename == null) {
            return null;
        }

        $main = static::mainMediaUrl();

        // If this is a gif, we have not resized it so send the main file
        $size = Str::endsWith($filename, '.gif') ? null : $size;

        if (! $size) {
            return "{$main}{$filename}";
        }

        $exploded = explode('/', $filename);
        $filename = Arr::last($exploded);
        $model = Arr::first($exploded);

        return "{$main}{$model}/{$size}/{$filename}";
    }

    /**
     * Get file extension from filename
     */
    public static function getExtensionFromName(string $filename): string
    {
        return Arr::last(explode('.', $filename));
    }
}
