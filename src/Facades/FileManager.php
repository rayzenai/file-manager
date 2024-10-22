<?php

namespace Kirantimsina\FileManager\Facades;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Facade;

/**
 * @see \Kirantimsina\FileManager\FileManager
 */
class FileManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Kirantimsina\FileManager\FileManager::class;
    }

    public static function getMediaPath($file = null, $size = null)
    {
        return static::getImagePath($file, $size);
    }

    public static function getImagePath(?string $file = null, ?string $size = null)
    {
        if (! $file) {
            return null;
        }

        $ext = static::getExtensionFromName($file);

        if (in_array($ext, ['gif']) || ! $size) {
            return static::s3Url()."$file";
        }

        $model = Arr::first(explode('/', $file));
        $file = Arr::last(explode('/', $file));

        return static::s3Url()."$model/$size/$file";
    }

    public static function getExtensionFromName(string $filename)
    {
        return Arr::last(explode('.', $filename));
    }

    public static function s3Url()
    {
        $region = config('filesystems.disks.s3.region');
        $bucket = config('filesystems.disks.s3.bucket');

        return "https://$bucket.s3.$region.amazonaws.com/";
    }
}
