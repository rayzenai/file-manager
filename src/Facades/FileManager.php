<?php

namespace Kirantimsina\FileManager\Facades;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;

/**
 * @see \Kirantimsina\FileManager\FileManager
 */
class FileManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Kirantimsina\FileManager\FileManagerService::class;
    }

    public static function mainMediaUrl()
    {
        $path = config('file-manager.cdn');

        if (Str::endsWith($path, '/')) {
            return $path;
        }

        return "{$path}/";
    }

    public static function getMediaPath($filename = null, $size = null)
    {
        if ($filename == null) {
            return null;
        }

        $main = static::mainMediaUrl();
        // $main has a '/' at the end already

        // If this is a gif, we have not resized it so send the main file
        $size = Str::endsWith($filename, '.gif') ? null : $size;
        if (! $size) {
            return "{$main}{$filename}";
        }

        $exploded = explode('/', $filename);
        $filename = Arr::last($exploded);
        $model = Arr::first($exploded);

        return "{$main}/{$model}/{$size}/{$filename}";
    }

    public static function getExtensionFromName(string $filename): string
    {
        return Arr::last(explode('.', $filename));
    }
}
