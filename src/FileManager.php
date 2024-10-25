<?php

namespace Kirantimsina\FileManager;

use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Gif\Exceptions\NotReadableException;
use Intervention\Image\Exceptions\NotSupportedException;
use Intervention\Image\ImageManager;
use Kirantimsina\FileManager\Facades\FileManager as FacadesFileManager;
use Kirantimsina\FileManager\Jobs\ResizeImages;
use Symfony\Component\Console\Output\ConsoleOutput;

class FileManager
{
    const MAX_UPLOAD_SIZE = 4096; //4096 or 4 MB

    const SIZE_ARR = [
        'full' => '1080',
        'card' => '640',
        'thumb' => '360',
        'icon' => '120',
    ];

    const ORIGINAL_SIZE = '1920';

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
        if (auth()->check()) {
            $filename = auth()->id().time().'-'.Str::random(10);
        } else {
            $filename = time().'-'.Str::random(10);
        }

        if (! empty($tag)) {
            $filename = Str::slug($tag).'-'.$filename;
        }

        $orginalName = $file->getClientOriginalName();

        if (! $ext) {
            $ext = $file->extension();
        }

        if (Str::contains($orginalName, '.apk')) {
            $ext = 'apk';
        }
        $filename = $filename.'.'.$ext;

        return $filename;
    }

    public static function uploadBase64($model, $base64Image, $tag = null, $fit = false, $resize = true)
    {
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Image)) {
            $fileData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64Image));
            $tmpFilePath = sys_get_temp_dir().'/'.Str::uuid()->toString();
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
        return config('file-manager.'.$model);
    }

    // This method is being used by API only, and not the Filament Backend
    // ImageUpload triat handles all this for Filament Backend
    public static function upload($model, $file, $tag = null, $fit = false, $resize = true)
    {
        $mime = $file->getMimeType();
        $path = static::getUploadDirectory($model);

        $filename = static::filename($file, $tag);

        // Forcing all images to webp
        if (in_array($mime, ['image/jpg', 'image/jpeg', 'image/png', 'image/webp', 'image/avif'])) {
            $img = ImageManager::gd()->read($file);
            $img = $img->toWebp(100)->toFilePointer();
            $ext = 'webp';
            $filename = \explode('.', $filename)[0].'.'.$ext;
        }

        $uploadedFilename = $file->storeAs($path, $filename);

        if ($resize && in_array($mime, ['image/jpg', 'image/jpeg', 'image/png', 'image/webp', 'image/avif'])) {
            ResizeImages::dispatch([$uploadedFilename]);
        }

        if ($uploadedFilename) {
            return [
                'status' => true,
                'file' => $uploadedFilename,
                'link' => FacadesFileManager::getMediaPath($uploadedFilename),
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
            $img = ImageManager::gd()->read(\file_get_contents(FacadesFileManager::getMediaPath($file)));
            foreach (static::SIZE_ARR as $key => $val) {
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

                $status = Storage::disk('default')->put(
                    "{$path}/{$key}/{$filename}",
                    $image,
                    'public'
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

        $path = static::getUploadDirectory($model);

        $newFile = $path.'/'.Arr::last(explode('/', $tempFile));

        $status = Storage::disk('s3')->move($tempFile, $newFile);

        if ($status) {
            ResizeImages::dispatch([$newFile]);
        }

        return $status ? $newFile : null;
    }

    public static function deleteImagesArray($arr): void
    {
        foreach ($arr as $filename) {
            static::deleteImage($filename);
        }
    }

    public function uploadTempVideo($file)
    {
        if (auth()->check()) {
            $filename = auth()->id().time().'-'.Str::random(5).'.'.$file->extension();
        } else {
            $filename = time().'-'.Str::random(10).'.'.$file->extension();
        }

        $upload = Storage::disk('s3')->putFileAs(
            'temp',
            new File($file),
            $filename,
            'public'
        );

        if ($upload) {
            return [
                'status' => true,
                'file' => explode('/', $upload)[1],
                'link' => FacadesFileManager::getMediaPath($upload),
            ];
        } else {
            return [
                'status' => false,
            ];
        }
    }

    public function moveTempVideo($filename, $to)
    {
        Storage::disk('s3')->move('temp/'.$filename, $to.$filename);

        return true;
    }
}
