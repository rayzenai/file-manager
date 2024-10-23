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

    public static function getMediaPath(?string $file = null, ?string $size = null, ?string $disk = 'public'): ?string
    {

        if (is_null($file)) {
            return null;
        }

        $ext = static::getExtensionFromName($file);

        if (in_array($ext, ['gif']) || is_null($size)) {

            return $disk === 's3' ? static::s3Url().$file : $file;
        }

        $model = Arr::first(explode('/', $file));
        $fileName = Arr::last(explode('/', $file));

        return $disk === 's3'
            ? static::s3Url()."{$model}/{$size}/{$fileName}"
            : env('APP_URL')."/storage/{$model}/{$size}/{$fileName}";
    }

    public static function getExtensionFromName(string $filename): string
    {
        return Arr::last(explode('.', $filename));
    }

    public static function s3Url(): string
    {
        $region = config('filesystems.disks.s3.region');
        $bucket = config('filesystems.disks.s3.bucket');

        return "https://{$bucket}.s3.{$region}.amazonaws.com/";
    }
}
