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
                $img = $img->toWebp(95)->toFilePointer();
                $ext = 'webp';

            } else {
                $img = $img->encode()->toFilePointer();
                $ext = Arr::last(explode('.', $filename));
            }
            $filename = \explode('.', $filename)[0] . '.' . $ext;
        }

        // Prepare storage options with cache headers for images
        $storageOptions = [];
        if (in_array($mime, ['image/jpg', 'image/jpeg', 'image/png', 'image/webp', 'image/avif'])) {
            $storageOptions = [
                'visibility' => 'public',
                'ContentType' => $mime,
            ];

            // Add cache headers if enabled
            if (config('file-manager.cache.enabled', true)) {
                $cacheControl = static::buildCacheControlHeader();
                if ($cacheControl) {
                    $storageOptions['CacheControl'] = $cacheControl;
                }
            }
        }

        $uploadedFilename = Storage::disk()->putFileAs(
            $path,
            $file,
            $filename,
            $storageOptions
        );

        // Only resize if enabled and image_sizes config is not empty
        $sizes = static::getImageSizes();
        if ($resize && ! empty($sizes) && in_array($mime, ['image/jpg', 'image/jpeg', 'image/png', 'image/webp', 'image/avif'])) {
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

        // Get sizes from config and skip if empty
        $sizes = static::getImageSizes();
        if (empty($sizes)) {
            $output->writeln("No image sizes configured. Skipping resize for {$filename}");

            return;
        }

        // Get compression settings from config
        $format = config('file-manager.compression.format', 'webp');
        $quality = (int) config('file-manager.compression.quality', 85);

        try {

            $disk = config('filesystems.default');

            if ($disk === 'local') {
                throw new Exception('Local disk is not supported for resizing images. Please use s3.');
            } else {
                // Use configured driver (GD or Imagick)
                $driver = config('file-manager.compression.driver', 'gd');
                $manager = $driver === 'imagick'
                    ? ImageManager::imagick()
                    : ImageManager::gd();

                $img = $manager->read(\file_get_contents(FileManager::getMediaPath($file)));

            }

            foreach ($sizes as $key => $val) {
                $val = intval($val);

                // Clone the original image for each size to avoid progressive shrinking
                $resizedImg = clone $img;

                if ($fit) {
                    $resizedImg->coverDown(width: $val, height: $val);
                } else {
                    // Scale down by width, not height
                    $resizedImg->scaleDown(width: $val);
                }
                
                // Convert to configured format with quality
                // Keep the original filename to maintain consistency
                switch ($format) {
                    case 'jpeg':
                    case 'jpg':
                        $image = $resizedImg->toJpeg($quality)->toFilePointer();
                        $contentType = 'image/jpeg';
                        break;
                    case 'png':
                        $image = $resizedImg->toPng()->toFilePointer();
                        $contentType = 'image/png';
                        break;
                    case 'avif':
                        $image = $resizedImg->toAvif($quality)->toFilePointer();
                        $contentType = 'image/avif';
                        break;
                    case 'webp':
                    default:
                        $image = $resizedImg->toWebp($quality)->toFilePointer();
                        $contentType = 'image/webp';
                        break;
                }

                $storageOptions = [
                    'visibility' => 'public',
                    'ContentType' => $contentType,
                ];

                // Add cache headers if enabled
                if (config('file-manager.cache.enabled', true)) {
                    $cacheControl = static::buildCacheControlHeader();
                    if ($cacheControl) {
                        $storageOptions['CacheControl'] = $cacheControl;
                    }
                }

                // Keep original filename for consistency across all sizes
                $status = Storage::disk()->put(
                    "{$path}/{$key}/{$filename}",
                    $image,
                    $storageOptions
                );

                if ($status) {
                    $output->writeln("Resized to {$path}/{$key}/{$filename}");
                } else {
                    $output->writeln("Could NOT Resize to {$path}/{$key}/{$filename}");
                }
            }
        } catch (NotSupportedException) {
            $output->writeln("{$filename} encoding format is not supported!");
        } catch (NotReadableException) {
            $output->writeln("{$filename} is not readable!");
        }
    }

    public static function moveTempImage($model, $tempFile)
    {
        $newFile = static::moveTempImageWithoutResize($model, $tempFile);

        // Only dispatch resize job if image_sizes config is not empty
        $sizes = static::getImageSizes();
        if ($newFile && ! empty($sizes)) {
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

        // Get sizes from config and skip deletion of resized versions if empty
        $sizes = static::getImageSizes();
        if (empty($sizes)) {
            return;
        }

        $name = Arr::last(explode('/', $filename));
        $model = Arr::first(explode('/', $filename));

        foreach (array_keys($sizes) as $key) {
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

    /**
     * Build the Cache-Control header value from config
     */
    public static function buildCacheControlHeader(): ?string
    {
        if (! config('file-manager.cache.enabled', true)) {
            return null;
        }

        $visibility = config('file-manager.cache.visibility', 'public');
        $maxAge = config('file-manager.cache.max_age', 31536000);
        $immutable = config('file-manager.cache.immutable', true);

        $header = "{$visibility}, max-age={$maxAge}";

        if ($immutable) {
            $header .= ', immutable';
        }

        return $header;
    }
}
